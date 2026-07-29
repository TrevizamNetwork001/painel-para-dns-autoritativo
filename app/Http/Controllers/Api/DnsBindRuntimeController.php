<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentBindReadinessEvent;
use App\Models\DnsBindOperation;
use App\Models\DnsBindOperationEvent;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DnsBindRuntimeController extends Controller
{
    private const ALLOWED_PATHS = [
        'named_conf' => ['/etc/bind/named.conf', '/etc/named.conf'],
        'include_dir' => ['/etc/bind', '/etc/named'],
        'zones_dir' => ['/etc/bind/dns-center-zones', '/var/named/dns-center-zones'],
        'named_checkconf' => ['/usr/bin/named-checkconf', '/usr/sbin/named-checkconf'],
        'named_checkzone' => ['/usr/bin/named-checkzone', '/usr/sbin/named-checkzone'],
        'rndc' => ['/usr/bin/rndc', '/usr/sbin/rndc'],
    ];

    public function readiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid'],
            'detected_at' => ['nullable', 'date'],
            'os_family' => ['required', Rule::in(['debian', 'rhel', 'unsupported'])],
            'bind_installed' => ['required', 'boolean'],
            'bind_version' => [
                'nullable',
                'string',
                'max:80',
                'not_regex:/[\x00-\x1F\x7F]/',
            ],
            'paths' => ['required', 'array'],
            'paths.named_conf' => ['nullable', 'string', 'max:255'],
            'paths.include_dir' => ['nullable', 'string', 'max:255'],
            'paths.zones_dir' => ['required', 'string', 'max:255'],
            'paths.named_checkconf' => ['nullable', 'string', 'max:255'],
            'paths.named_checkzone' => ['nullable', 'string', 'max:255'],
            'paths.rndc' => ['nullable', 'string', 'max:255'],
            'service' => ['required', 'array'],
            'service.name' => ['nullable', Rule::in(['bind9', 'named'])],
            'service.active' => ['required', 'boolean'],
            'service.user' => ['nullable', 'string', 'max:80', 'regex:/\A[a-z_][a-z0-9_-]*\z/i'],
            'service.group' => ['nullable', 'string', 'max:80', 'regex:/\A[a-z_][a-z0-9_-]*\z/i'],
            'listeners' => ['required', 'array'],
            'listeners.tcp_53' => ['required', 'boolean'],
            'listeners.udp_53' => ['required', 'boolean'],
            'network' => ['required', 'array'],
            'network.ipv4' => ['required', 'boolean'],
            'network.ipv6' => ['required', 'boolean'],
            'security' => ['required', 'array'],
            'security.apparmor' => ['required', Rule::in(['enforcing', 'present', 'absent', 'unknown'])],
            'security.selinux' => ['required', Rule::in(['enforcing', 'permissive', 'disabled', 'absent', 'unknown'])],
            'permissions' => ['required', 'array'],
            'permissions.can_manage_include' => ['required', 'boolean'],
            'permissions.can_manage_zones' => ['required', 'boolean'],
        ]);

        foreach (self::ALLOWED_PATHS as $key => $allowed) {
            $path = data_get($validated, 'paths.'.$key);

            if ($path !== null && ! in_array($path, $allowed, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'path_not_allowed',
                    'message' => 'O inventário contém um caminho não permitido.',
                ], 422);
            }
        }

        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $report = [
            ...$validated,
            'received_at' => now()->toIso8601String(),
        ];
        $payloadHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $outcome = DB::transaction(function () use (
            $agent,
            $report,
            $payloadHash,
        ): string {
            $event = DnsAgentBindReadinessEvent::query()
                ->where('dns_agent_id', $agent->id)
                ->where('event_id', $report['event_id'])
                ->first();

            if ($event) {
                return hash_equals($event->payload_hash, $payloadHash)
                    ? 'duplicate'
                    : 'replay';
            }

            DnsAgentBindReadinessEvent::query()->create([
                'dns_agent_id' => $agent->id,
                'event_id' => $report['event_id'],
                'payload_hash' => $payloadHash,
            ]);

            $server = $agent->server()->lockForUpdate()->firstOrFail();
            abort_unless(
                (int) $server->organization_id === (int) $agent->organization_id,
                403,
            );

            $server->forceFill([
                'bind_version' => $report['bind_version'],
                'bind_readiness' => $report,
                'bind_readiness_at' => now(),
                'last_seen_at' => now(),
                'agent_status' => 'online',
            ])->save();

            $agent->forceFill(['last_seen_at' => now()])->save();

            return 'updated';
        });

        if ($outcome === 'replay') {
            return response()->json([
                'ok' => false,
                'error' => 'event_replay',
                'message' => 'O identificador do inventário já foi utilizado.',
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'idempotent' => $outcome === 'duplicate',
            'message' => 'Prontidão BIND registrada.',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function nextOperation(Request $request): JsonResponse
    {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $operation = DnsBindOperation::query()
            ->where('organization_id', $agent->organization_id)
            ->where('dns_server_id', $agent->dns_server_id)
            ->where('dns_agent_id', $agent->id)
            ->where('status', 'authorized')
            ->oldest('authorized_at')
            ->first();

        return response()->json([
            'ok' => true,
            'operation' => $operation ? [
                'id' => $operation->id,
                'action' => $operation->action,
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'authorized_at' => $operation->authorized_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function report(
        Request $request,
        int $operation,
    ): JsonResponse {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid'],
            'authorization_nonce' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['running', 'succeeded', 'failed'])],
            'result' => ['nullable', 'array', 'max:50'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $target = DnsBindOperation::query()
            ->whereKey($operation)
            ->where('organization_id', $agent->organization_id)
            ->where('dns_server_id', $agent->dns_server_id)
            ->where('dns_agent_id', $agent->id)
            ->first();

        if (! $target || ! hash_equals(
            (string) $target->getRawOriginal('authorization_nonce'),
            $validated['authorization_nonce'],
        )) {
            $this->audit($request, $agent, 'agent.bind_operation_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => 'operation_not_available',
                'message' => 'Operação inexistente ou não autorizada para este agente.',
            ], 404);
        }

        $error = $this->sanitize($validated['error'] ?? null);
        $result = $this->sanitizeResult($validated['result'] ?? null);
        $payloadHash = hash('sha256', json_encode([
            'operation' => $target->id,
            'status' => $validated['status'],
            'result' => $result,
            'error' => $error,
        ], JSON_THROW_ON_ERROR));

        $outcome = DB::transaction(function () use (
            $target,
            $agent,
            $validated,
            $payloadHash,
            $result,
            $error,
        ): string {
            $locked = DnsBindOperation::query()->whereKey($target->id)
                ->lockForUpdate()->firstOrFail();

            $event = DnsBindOperationEvent::query()
                ->where('dns_agent_id', $agent->id)
                ->where('event_id', $validated['event_id'])
                ->first();

            if ($event) {
                return hash_equals($event->payload_hash, $payloadHash)
                    ? 'duplicate'
                    : 'replay';
            }

            if (in_array($locked->status, ['succeeded', 'failed'], true)) {
                return 'late';
            }

            if (
                $validated['status'] === 'running'
                && $locked->status !== 'authorized'
            ) {
                return 'invalid_transition';
            }

            if (
                in_array($validated['status'], ['succeeded', 'failed'], true)
                && $locked->status !== 'running'
            ) {
                return 'invalid_transition';
            }

            DnsBindOperationEvent::query()->create([
                'dns_bind_operation_id' => $locked->id,
                'dns_agent_id' => $agent->id,
                'event_id' => $validated['event_id'],
                'payload_hash' => $payloadHash,
            ]);

            $locked->forceFill([
                'status' => $validated['status'],
                'started_at' => $validated['status'] === 'running'
                    ? now()
                    : $locked->started_at,
                'completed_at' => in_array(
                    $validated['status'],
                    ['succeeded', 'failed'],
                    true,
                ) ? now() : null,
                'result' => $result,
                'error' => $validated['status'] === 'failed' ? $error : null,
            ])->save();

            return 'updated';
        });

        if (in_array($outcome, ['replay', 'invalid_transition'], true)) {
            $this->audit($request, $agent, 'agent.bind_operation_rejected', 'rejected');

            return response()->json([
                'ok' => false,
                'error' => $outcome === 'replay'
                    ? 'event_replay'
                    : 'invalid_operation_transition',
                'message' => $outcome === 'replay'
                    ? 'O identificador do evento já foi utilizado.'
                    : 'A transição informada não corresponde ao estado da operação.',
            ], 409);
        }

        if ($outcome === 'updated') {
            $this->audit(
                $request,
                $agent,
                'agent.bind_operation_'.$validated['status'],
                $validated['status'] === 'failed' ? 'failed' : 'success',
            );
        }

        return response()->json([
            'ok' => true,
            'idempotent' => $outcome !== 'updated',
            'status' => $target->fresh()->status,
        ]);
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace(
            '/\b(token|secret|password|authorization|api[_-]?key)\s*[:=]\s*\S+/iu',
            '$1=[removido]',
            $value,
        ) ?? '';
        $value = preg_replace('/(?<!\w)(?:\/[A-Za-z0-9._-]+){2,}/u', '[caminho removido]', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, 1000, '');
    }

    private function sanitizeResult(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return collect($result)
            ->only([
                'bind_installed',
                'bind_version',
                'managed_include_created',
                'backup_created',
                'configuration_valid',
                'service_active',
                'tcp_53',
                'udp_53',
                'rolled_back',
            ])
            ->map(fn ($value) => is_bool($value)
                ? $value
                : (is_string($value) ? $this->sanitize($value) : null))
            ->all();
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
