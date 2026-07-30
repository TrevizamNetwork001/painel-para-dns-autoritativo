<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsAgentPublicationEvent;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DnsAgentPublicationController extends Controller
{
    public function apply(
        Request $request,
        int $publication,
    ): JsonResponse {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $validated = $request->validate([
            'event_id' => ['required', 'uuid'],
            'status' => [
                'required',
                Rule::in(['applying', 'applied', 'failed']),
            ],
            'installed_version' => [
                'required_if:status,applied',
                'nullable',
                'integer',
                'min:1',
            ],
            'authoritative_serial' => [
                'required_if:status,applied',
                'nullable',
                'integer',
                'min:1',
                'max:4294967295',
            ],
            'agent_timestamp' => ['nullable', 'date'],
            'error' => ['nullable', 'string', 'max:2000'],
            'artifact_checksum' => [
                'nullable',
                'string',
                'regex:/\A[a-f0-9]{64}\z/i',
            ],
        ]);

        $destination = DnsAgentPublication::query()
            ->with('zoneVersion')
            ->whereKey($publication)
            ->where('organization_id', $agent->organization_id)
            ->where('dns_server_id', $agent->dns_server_id)
            ->where('dns_agent_id', $agent->id)
            ->first();

        if (! $destination) {
            $this->audit($request, $agent, 'agent.version_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => 'publication_not_available',
                'message' => 'Publicação inexistente ou não destinada a este agente.',
            ], 404);
        }

        if (
            $validated['status'] === 'applied'
            && (int) $validated['installed_version']
                !== (int) $destination->zoneVersion->version
        ) {
            $this->audit($request, $agent, 'agent.version_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => 'installed_version_mismatch',
                'message' => 'A versão instalada não corresponde à publicação.',
            ], 422);
        }

        if (
            $validated['status'] === 'applied'
            && (int) $validated['authoritative_serial']
                !== (int) $destination->zoneVersion->serial
        ) {
            $this->audit($request, $agent, 'agent.serial_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => 'authoritative_serial_mismatch',
                'message' => 'O serial SOA observado no BIND não corresponde à publicação.',
            ], 422);
        }

        $error = $this->sanitizeError($validated['error'] ?? null);
        $payloadHash = hash('sha256', json_encode([
            'publication' => $destination->id,
            'status' => $validated['status'],
            'installed_version' => $validated['installed_version'] ?? null,
            'artifact_checksum' => strtolower(
                $validated['artifact_checksum'] ?? '',
            ),
            'authoritative_serial' => $validated['authoritative_serial'] ?? null,
            'error' => $error,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use (
            $agent,
            $destination,
            $validated,
            $payloadHash,
            $error,
        ): array {
            $locked = DnsAgentPublication::query()
                ->with('zoneVersion.zone')
                ->whereKey($destination->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingEvent = DnsAgentPublicationEvent::query()
                ->where('dns_agent_id', $agent->id)
                ->where('event_id', $validated['event_id'])
                ->first();

            if ($existingEvent) {
                return [
                    'kind' => hash_equals($existingEvent->payload_hash, $payloadHash)
                        ? 'duplicate'
                        : 'replay',
                    'destination' => $locked,
                ];
            }

            $highestInstalled = DnsAgentPublication::query()
                ->where('dns_server_id', $agent->dns_server_id)
                ->where('status', 'applied')
                ->whereHas(
                    'zoneVersion',
                    fn ($query) => $query->where(
                        'dns_zone_id',
                        $locked->zoneVersion->dns_zone_id,
                    ),
                )
                ->max('installed_version');

            if (
                $highestInstalled !== null
                && (int) $locked->zoneVersion->version < (int) $highestInstalled
            ) {
                return ['kind' => 'regression', 'destination' => $locked];
            }

            if ($locked->status === 'applied') {
                if (
                    $validated['status'] === 'applied'
                    && (int) $locked->installed_version
                        === (int) $validated['installed_version']
                ) {
                    $kind = 'duplicate';
                } else {
                    $kind = 'late';
                }

                DnsAgentPublicationEvent::query()->create([
                    'dns_agent_publication_id' => $locked->id,
                    'dns_agent_id' => $agent->id,
                    'event_id' => $validated['event_id'],
                    'status' => $validated['status'],
                    'payload_hash' => $payloadHash,
                ]);

                return ['kind' => $kind, 'destination' => $locked];
            }

            DnsAgentPublicationEvent::query()->create([
                'dns_agent_publication_id' => $locked->id,
                'dns_agent_id' => $agent->id,
                'event_id' => $validated['event_id'],
                'status' => $validated['status'],
                'payload_hash' => $payloadHash,
            ]);

            $now = now();
            $locked->forceFill([
                'status' => $validated['status'],
                'installed_version' => $validated['status'] === 'applied'
                    ? (int) $validated['installed_version']
                    : $locked->installed_version,
                'reported_serial' => $validated['status'] === 'applied'
                    ? (int) $validated['authoritative_serial']
                    : $locked->reported_serial,
                'serial_confirmed_at' => $validated['status'] === 'applied'
                    ? $now
                    : $locked->serial_confirmed_at,
                'artifact_checksum' => isset($validated['artifact_checksum'])
                    ? strtolower($validated['artifact_checksum'])
                    : $locked->artifact_checksum,
                'last_apply_at' => $now,
                'agent_reported_at' => $validated['agent_timestamp'] ?? null,
                'last_apply_error' => $validated['status'] === 'failed'
                    ? $error
                    : null,
            ])->save();

            return ['kind' => 'updated', 'destination' => $locked];
        });

        if (in_array($result['kind'], ['replay', 'regression'], true)) {
            $this->audit($request, $agent, 'agent.version_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => $result['kind'] === 'replay'
                    ? 'event_replay'
                    : 'version_regression',
                'message' => $result['kind'] === 'replay'
                    ? 'O identificador do evento já foi usado com outros dados.'
                    : 'A confirmação não pode regredir a versão instalada.',
            ], 409);
        }

        if ($result['kind'] === 'late') {
            $this->audit($request, $agent, 'agent.version_rejected', 'rejected');
        } elseif ($result['kind'] === 'updated') {
            $this->audit(
                $request,
                $agent,
                match ($validated['status']) {
                    'applying' => 'agent.apply_started',
                    'applied' => 'agent.apply_succeeded',
                    'failed' => 'agent.apply_failed',
                },
                $validated['status'] === 'failed' ? 'failed' : 'success',
            );
        }

        /** @var DnsAgentPublication $current */
        $current = $result['destination']->fresh();

        return response()->json([
            'ok' => true,
            'idempotent' => $result['kind'] !== 'updated',
            'publication_id' => $current->id,
            'status' => $current->status,
            'desired_version' => $destination->zoneVersion->version,
            'installed_version' => $current->installed_version,
            'authoritative_serial' => $current->reported_serial,
            'serial_confirmed_at' => $current->serial_confirmed_at?->toIso8601String(),
            'confirmed_at' => $current->last_apply_at?->toIso8601String(),
        ]);
    }

    private function sanitizeError(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $sanitized = strip_tags($message);
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sanitized) ?? '';
        $sanitized = preg_replace(
            '/\b(token|secret|password|authorization|api[_-]?key)\s*[:=]\s*\S+/iu',
            '$1=[removido]',
            $sanitized,
        ) ?? '';
        $sanitized = preg_replace(
            '/(?<!\w)(?:\/[A-Za-z0-9._-]+){2,}/u',
            '[caminho removido]',
            $sanitized,
        ) ?? '';
        $sanitized = preg_replace(
            '/`[^`]*`|\$\([^)]*\)/u',
            '[comando removido]',
            $sanitized,
        ) ?? '';
        $sanitized = preg_replace(
            '/\b(?:sudo|bash|sh|powershell|cmd(?:\.exe)?|rm|curl|wget)\s+[^.;]*/iu',
            '[comando removido]',
            $sanitized,
        ) ?? '';
        $sanitized = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? '');

        return $sanitized === '' ? null : Str::limit($sanitized, 1000, '');
    }

    private function audit(
        Request $request,
        DnsAgent $agent,
        string $event,
        string $result,
    ): void {
        SecurityAuditLogger::record(
            event: $event,
            user: null,
            result: $result,
            actor: 'dns_agent:'.$agent->id,
            source: 'agent_api',
            ipAddress: $request->ip(),
        );
    }
}
