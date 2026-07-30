<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAuthoritativeObservation;
use App\Models\DnsAuthoritativeObservationEvent;
use App\Models\DnsZone;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DnsAuthoritativeObservationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:1'],
            'observed_at' => ['required', 'date'],
            'server' => ['required', 'array:service_active,tcp_53,udp_53,recursion_enabled,available,last_reload_at,load_error'],
            'server.service_active' => ['required', 'boolean'],
            'server.tcp_53' => ['required', 'boolean'],
            'server.udp_53' => ['required', 'boolean'],
            'server.recursion_enabled' => ['nullable', 'boolean'],
            'server.available' => ['required', 'boolean'],
            'server.last_reload_at' => ['nullable', 'date'],
            'server.load_error' => ['nullable', 'string', 'max:1000'],
            'zones' => ['required', 'array', 'max:200'],
            'zones.*' => ['required', 'array'],
            'zones.*.zone_id' => ['required', 'integer'],
            'zones.*.role' => ['required', Rule::in(['primary', 'secondary'])],
            'zones.*.observed_serial' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'zones.*.status' => ['required', Rule::in(DnsAuthoritativeObservation::STATUSES)],
            'zones.*.zone_state' => ['nullable', 'string', 'max:80'],
            'zones.*.last_refresh_at' => ['nullable', 'date'],
            'zones.*.next_retry_at' => ['nullable', 'date'],
            'zones.*.expires_at' => ['nullable', 'date'],
            'zones.*.primary_address' => ['nullable', 'ip'],
            'zones.*.transfer_status' => ['nullable', 'string', 'max:32'],
            'zones.*.last_transfer_at' => ['nullable', 'date'],
            'zones.*.last_failure_at' => ['nullable', 'date'],
            'zones.*.error' => ['nullable', 'string', 'max:1000'],
            'zones.*.source' => ['required', Rule::in(['rndc_zonestatus', 'dig_soa_local', 'bind_journal'])],
        ]);

        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');
        $server = $agent->server;
        abort_unless(
            $server
            && (int) $server->organization_id === (int) $agent->organization_id,
            404,
        );

        $zoneIds = collect($validated['zones'])->pluck('zone_id')->unique();
        abort_if($zoneIds->count() !== count($validated['zones']), 422, 'Zona duplicada no relatório.');

        $zones = DnsZone::query()
            ->where('organization_id', $agent->organization_id)
            ->whereIn('id', $zoneIds)
            ->whereHas('servers', fn ($query) => $query->where('dns_servers.id', $server->id))
            ->get()
            ->keyBy('id');
        abort_unless($zones->count() === $zoneIds->count(), 404);

        $sanitizedServer = $validated['server'];
        $sanitizedServer['load_error'] = $this->sanitize($sanitizedServer['load_error'] ?? null);
        $payloadHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $outcome = DB::transaction(function () use (
            $agent,
            $zones,
            $validated,
            $sanitizedServer,
            $payloadHash,
            $request,
        ): string {
            $lockedServer = $agent->server()->lockForUpdate()->firstOrFail();

            $existing = DnsAuthoritativeObservationEvent::query()
                ->where('dns_agent_id', $agent->id)
                ->where('event_id', $validated['event_id'])
                ->first();
            if ($existing) {
                return hash_equals($existing->payload_hash, $payloadHash)
                    ? 'duplicate'
                    : 'replay';
            }

            $previousRuntime = $lockedServer->authoritative_runtime ?? [];
            if (
                $lockedServer->authoritative_sequence !== null
                && $validated['sequence'] <= $lockedServer->authoritative_sequence
            ) {
                return 'stale';
            }

            DnsAuthoritativeObservationEvent::query()->create([
                'dns_agent_id' => $agent->id,
                'event_id' => $validated['event_id'],
                'sequence' => $validated['sequence'],
                'payload_hash' => $payloadHash,
            ]);

            $lockedServer->forceFill([
                'authoritative_runtime' => $sanitizedServer,
                'authoritative_observed_at' => $validated['observed_at'],
                'authoritative_sequence' => $validated['sequence'],
                'last_seen_at' => now(),
                'agent_status' => 'online',
                'status' => $sanitizedServer['available'] ? 'online' : 'offline',
            ])->save();

            foreach ($validated['zones'] as $item) {
                $zone = $zones->get($item['zone_id']);
                $role = $zone->servers->firstWhere('id', $lockedServer->id)?->pivot?->role
                    ?? $zone->servers()->whereKey($lockedServer->id)->firstOrFail()->pivot->role;
                abort_unless($role === $item['role'], 404);

                $status = $this->calculateStatus($item, (int) $zone->serial);
                $previous = DnsAuthoritativeObservation::query()
                    ->where('dns_server_id', $lockedServer->id)
                    ->where('dns_zone_id', $zone->id)
                    ->lockForUpdate()
                    ->first();

                DnsAuthoritativeObservation::query()->updateOrCreate(
                    [
                        'dns_server_id' => $lockedServer->id,
                        'dns_zone_id' => $zone->id,
                    ],
                    [
                        'organization_id' => $agent->organization_id,
                        'expected_serial' => $zone->serial,
                        'observed_serial' => $item['observed_serial'] ?? null,
                        'status' => $status,
                        'zone_role' => $role,
                        'zone_state' => $this->sanitize($item['zone_state'] ?? null),
                        'primary_address' => $item['primary_address'] ?? null,
                        'transfer_status' => $item['transfer_status'] ?? null,
                        'last_refresh_at' => $item['last_refresh_at'] ?? null,
                        'next_retry_at' => $item['next_retry_at'] ?? null,
                        'expires_at' => $item['expires_at'] ?? null,
                        'last_transfer_at' => $item['last_transfer_at'] ?? null,
                        'last_failure_at' => $item['last_failure_at'] ?? null,
                        'error' => $this->sanitize($item['error'] ?? null),
                        'source' => $item['source'],
                        'event_id' => $validated['event_id'],
                        'sequence' => $validated['sequence'],
                        'agent_observed_at' => $validated['observed_at'],
                        'server_received_at' => now(),
                    ],
                );

                if ($previous?->status !== $status) {
                    $this->auditTransition(
                        $request,
                        $agent,
                        (int) $zone->id,
                        $previous?->status,
                        $status,
                    );
                }
            }

            $previousRecursion = $previousRuntime['recursion_enabled'] ?? null;
            $currentRecursion = $sanitizedServer['recursion_enabled'];
            if ($currentRecursion === true && $previousRecursion !== true) {
                $this->audit(
                    $request,
                    $agent,
                    'dns.recursion_detected',
                    'server:'.$lockedServer->id,
                );
            } elseif ($currentRecursion === false && $previousRecursion === true) {
                $this->audit(
                    $request,
                    $agent,
                    'dns.recursion_disabled',
                    'server:'.$lockedServer->id,
                );
            }

            return 'updated';
        });

        if ($outcome === 'replay') {
            return response()->json(['ok' => false, 'error' => 'event_replay'], 409);
        }

        return response()->json([
            'ok' => true,
            'idempotent' => $outcome !== 'updated',
            'stale' => $outcome === 'stale',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function calculateStatus(array $item, int $expected): string
    {
        if (! empty($item['expires_at']) && now()->greaterThanOrEqualTo($item['expires_at'])) {
            return 'expired';
        }
        if ($item['status'] === 'primary_unreachable') {
            return 'primary_unreachable';
        }
        if ($item['status'] === 'transfer_failed') {
            return 'transfer_failed';
        }
        if (isset($item['observed_serial']) && (int) $item['observed_serial'] !== $expected) {
            return 'serial_mismatch';
        }
        if (isset($item['observed_serial']) && (int) $item['observed_serial'] === $expected) {
            return 'synchronized';
        }

        return $item['status'];
    }

    private function auditTransition(
        Request $request,
        DnsAgent $agent,
        int $zoneId,
        ?string $from,
        string $to,
    ): void {
        $events = [
            'transfer_failed' => 'dns.transfer_failed',
            'serial_mismatch' => 'dns.serial_mismatch',
            'expired' => 'dns.zone_expired',
            'primary_unreachable' => 'dns.primary_unreachable',
        ];
        if (isset($events[$to])) {
            $this->audit($request, $agent, $events[$to], 'zone:'.$zoneId);
        }
        if ($to === 'synchronized' && $from === 'transfer_failed') {
            $this->audit($request, $agent, 'dns.transfer_recovered', 'zone:'.$zoneId);
        }
        if ($to === 'synchronized' && $from === 'serial_mismatch') {
            $this->audit($request, $agent, 'dns.serial_converged', 'zone:'.$zoneId);
        }
        if ($to === 'synchronized' && $from === 'primary_unreachable') {
            $this->audit($request, $agent, 'dns.primary_recovered', 'zone:'.$zoneId);
        }
    }

    private function audit(Request $request, DnsAgent $agent, string $event, string $reason): void
    {
        SecurityAuditLogger::record(
            event: $event,
            user: null,
            result: 'success',
            actor: 'dns_agent:'.$agent->id,
            source: 'agent_api',
            ipAddress: $request->ip(),
            organizationId: (int) $agent->organization_id,
            reason: $reason,
        );
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace(
            '/\b(token|secret|password|authorization|api[_-]?key|hmac-[a-z0-9-]+)\s*[:=]?\s*\S*/iu',
            '$1=[removido]',
            $value,
        ) ?? '';
        $value = preg_replace('/(?<!\w)(?:\/[A-Za-z0-9._-]+){2,}/u', '[caminho removido]', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, 1000, '');
    }
}
