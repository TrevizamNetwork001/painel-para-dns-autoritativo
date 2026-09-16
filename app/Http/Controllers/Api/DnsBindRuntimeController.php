<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentBindReadinessEvent;
use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsBindOperationEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
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
            'include_wired' => ['nullable', 'array'],
            'include_wired.expected_include' => ['nullable', 'string', 'max:255'],
            'include_wired.statement_found' => ['nullable', 'boolean'],
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

        DnsBindOperation::expireStaleOperations(
            $agent->dns_server_id,
            $agent->id,
        );

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
            'result' => ['nullable', 'array'],
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

        $isDiscovery = $target->action === 'discover_bind_zones';
        $error = $this->sanitize($validated['error'] ?? null);
        $discoveredZones = $isDiscovery
            ? $this->sanitizeDiscoveryZones($validated['result']['zones'] ?? null)
            : null;
        $result = $isDiscovery
            ? $this->sanitizeDiscoverySummary($discoveredZones)
            : $this->sanitizeResult($validated['result'] ?? null);

        $hashPayload = $this->operationEventFingerprintPayload(
            $target,
            $validated['status'],
            $result,
            $error,
            $discoveredZones,
        );
        $payloadHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));

        $outcome = DB::transaction(function () use (
            $target,
            $agent,
            $validated,
            $payloadHash,
            $result,
            $error,
            $isDiscovery,
            $discoveredZones,
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

            if (in_array($locked->status, ['succeeded', 'failed', 'expired'], true)) {
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

            if ($isDiscovery && $validated['status'] === 'succeeded' && $discoveredZones !== null) {
                $this->ingestDiscoveredZones($locked, $agent, $discoveredZones);
            }

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

            if ($isDiscovery) {
                if ($validated['status'] === 'running') {
                    $this->audit($request, $agent, 'dns.bind_discovery_started', 'success');
                } elseif ($validated['status'] === 'succeeded') {
                    $this->audit($request, $agent, 'dns.bind_discovery_completed', 'success');
                }
            }
        }

        return response()->json([
            'ok' => true,
            'idempotent' => $outcome !== 'updated',
            'status' => $target->fresh()->status,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeDiscoveryZones(mixed $zones): array
    {
        if (! is_array($zones)) {
            return [];
        }

        $sanitized = [];

        foreach (array_slice($zones, 0, 200) as $zone) {
            if (! is_array($zone) || ! is_string($zone['name'] ?? null) || $zone['name'] === '') {
                continue;
            }

            $fileMeta = is_array($zone['file_metadata'] ?? null) ? $zone['file_metadata'] : [];
            $records = is_array($zone['records'] ?? null)
                ? array_values(array_filter(array_map(
                    fn ($record) => $this->sanitizeDiscoveredRecord($record),
                    array_slice($zone['records'], 0, 20000),
                )))
                : null;

            $sanitized[] = [
                'name' => Str::limit($zone['name'], 255, ''),
                'detected_type' => in_array($zone['detected_type'] ?? null, ['primary', 'secondary'], true)
                    ? $zone['detected_type'] : null,
                'detected_syntax' => is_string($zone['detected_syntax'] ?? null)
                    ? Str::limit($zone['detected_syntax'], 20, '') : null,
                'file_path' => is_string($zone['file'] ?? null)
                    ? Str::limit($zone['file'], 500, '') : null,
                'serial' => is_int($zone['serial'] ?? null) ? $zone['serial'] : null,
                'node_count' => is_int($zone['node_count'] ?? null) ? $zone['node_count'] : null,
                'dynamic' => (bool) ($zone['dynamic'] ?? false),
                'secure' => (bool) ($zone['secure'] ?? false),
                'file_owner' => is_string($fileMeta['owner'] ?? null) ? Str::limit($fileMeta['owner'], 80, '') : null,
                'file_group' => is_string($fileMeta['group'] ?? null) ? Str::limit($fileMeta['group'], 80, '') : null,
                'file_mode' => is_string($fileMeta['mode'] ?? null) ? Str::limit($fileMeta['mode'], 10, '') : null,
                'file_size' => is_int($fileMeta['size'] ?? null) ? $fileMeta['size'] : null,
                'file_mtime' => is_string($fileMeta['mtime'] ?? null) ? $fileMeta['mtime'] : null,
                'file_sha256' => is_string($fileMeta['sha256'] ?? null)
                    && preg_match('/\A[0-9a-f]{64}\z/', $fileMeta['sha256'])
                    ? $fileMeta['sha256'] : null,
                'validation_status' => in_array($zone['validation_status'] ?? null, ['ok', 'warning', 'error'], true)
                    ? $zone['validation_status'] : 'ok',
                'validation_message' => is_string($zone['validation_message'] ?? null)
                    ? $this->sanitize(Str::limit($zone['validation_message'], 500, '')) : null,
                'unsupported_record_types' => is_array($zone['unsupported_record_types'] ?? null)
                    ? array_values(array_slice(array_map('strval', $zone['unsupported_record_types']), 0, 20))
                    : [],
                'soa' => $this->sanitizeSoa($zone['soa'] ?? null),
                'records' => $records,
                'warnings' => is_array($zone['warnings'] ?? null)
                    ? array_values(array_map(
                        fn ($warning) => $this->sanitize((string) $warning),
                        array_slice($zone['warnings'], 0, 20),
                    ))
                    : [],
            ];
        }

        return $sanitized;
    }

    private function sanitizeDiscoveredRecord(mixed $record): ?array
    {
        if (! is_array($record)) {
            return null;
        }

        $name = $record['name'] ?? null;
        $type = $record['type'] ?? null;
        $content = $record['rdata'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($type) || ! is_string($content)) {
            return null;
        }

        if (! in_array($type, DnsRecord::TYPES, true)) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $content)) {
            return null;
        }

        return [
            'name' => Str::limit($name, 255, ''),
            'ttl' => is_int($record['ttl'] ?? null) ? $record['ttl'] : null,
            'type' => $type,
            'content' => Str::limit($content, 4096, ''),
        ];
    }

    private function sanitizeSoa(mixed $soa): ?array
    {
        if (! is_array($soa)) {
            return null;
        }

        foreach (['mname', 'rname'] as $key) {
            if (! is_string($soa[$key] ?? null)) {
                return null;
            }
        }
        foreach (['serial', 'refresh', 'retry', 'expire', 'minimum'] as $key) {
            if (! is_int($soa[$key] ?? null)) {
                return null;
            }
        }

        return [
            'mname' => Str::limit($soa['mname'], 255, ''),
            'rname' => Str::limit($soa['rname'], 255, ''),
            'serial' => $soa['serial'],
            'refresh' => $soa['refresh'],
            'retry' => $soa['retry'],
            'expire' => $soa['expire'],
            'minimum' => $soa['minimum'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     */
    private function sanitizeDiscoverySummary(array $zones): array
    {
        return [
            'zones_total' => count($zones),
            'zones_primary' => count(array_filter($zones, fn ($zone) => $zone['detected_type'] === 'primary')),
            'zones_secondary' => count(array_filter($zones, fn ($zone) => $zone['detected_type'] === 'secondary')),
            'discovered_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build the stable, semantic identity of an operation report.
     *
     * Processing metadata generated by this backend must not participate in
     * replay detection. In particular, discovery summaries persist a local
     * discovered_at timestamp for observability, but the same agent event may
     * be retried after that timestamp has changed.
     *
     * @param  array<string, mixed>|null  $result
     * @param  list<array<string, mixed>>|null  $discoveredZones
     * @return array<string, mixed>
     */
    private function operationEventFingerprintPayload(
        DnsBindOperation $operation,
        string $status,
        ?array $result,
        ?string $error,
        ?array $discoveredZones,
    ): array {
        $semanticResult = $result;

        if ($operation->action === 'discover_bind_zones' && $semanticResult !== null) {
            unset($semanticResult['discovered_at']);
        }

        $payload = [
            'operation' => $operation->id,
            'status' => $status,
            'result' => $semanticResult,
            'error' => $error,
        ];

        if ($operation->action === 'discover_bind_zones') {
            $payload['zones'] = $discoveredZones;
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     */
    private function ingestDiscoveredZones(DnsBindOperation $operation, DnsAgent $agent, array $zones): void
    {
        foreach ($zones as $zone) {
            $existing = DnsZone::query()
                ->where('organization_id', $operation->organization_id)
                ->where('name', $zone['name'])
                ->first();

            $comparisonState = $this->computeComparisonState($existing, $zone);

            DnsBindDiscoveredZone::query()->create([
                'organization_id' => $operation->organization_id,
                'dns_server_id' => $operation->dns_server_id,
                'dns_agent_id' => $agent->id,
                'dns_bind_operation_id' => $operation->id,
                'name' => $zone['name'],
                'detected_type' => $zone['detected_type'],
                'detected_syntax' => $zone['detected_syntax'],
                'file_path' => $zone['file_path'],
                'serial' => $zone['serial'],
                'node_count' => $zone['node_count'],
                'dynamic' => $zone['dynamic'],
                'secure' => $zone['secure'],
                'file_owner' => $zone['file_owner'],
                'file_group' => $zone['file_group'],
                'file_mode' => $zone['file_mode'],
                'file_size' => $zone['file_size'],
                'file_mtime' => $zone['file_mtime'],
                'file_sha256' => $zone['file_sha256'],
                'validation_status' => $zone['validation_status'],
                'validation_message' => $zone['validation_message'],
                'comparison_state' => $comparisonState,
                'unsupported_record_types' => $zone['unsupported_record_types'] ?: null,
                'soa' => $zone['soa'],
                'records' => $zone['records'],
                'warnings' => $zone['warnings'] ?: null,
            ]);

            SecurityAuditLogger::record(
                event: 'dns.bind_zone_discovered',
                user: null,
                result: $comparisonState,
                actor: 'dns_agent:'.$agent->id,
                source: 'agent_api',
                organizationId: $operation->organization_id,
                reason: 'dns_bind_operation:'.$operation->id.';zone:'.$zone['name'],
            );
        }
    }

    private function computeComparisonState(?DnsZone $existing, array $zone): string
    {
        if ($zone['detected_type'] === 'secondary') {
            return 'secondary_external';
        }

        if (! empty($zone['unsupported_record_types']) || $zone['validation_status'] === 'error') {
            return 'not_supported';
        }

        if (! $existing) {
            return 'new';
        }

        if ($existing->origin === 'bind_import') {
            return 'imported';
        }

        $recordCount = is_array($zone['records']) ? count($zone['records']) : null;
        $sameSerial = $zone['serial'] !== null && (int) $zone['serial'] === (int) $existing->serial;
        $sameCount = $recordCount !== null && $recordCount === $existing->records()->count();

        return ($sameSerial && $sameCount) ? 'exists' : 'conflict';
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
                'binary_changed',
                'units_changed',
                'changed',
                'previous_version',
                'diagnostics',
            ])
            ->map(fn ($value, $key) => $key === 'diagnostics'
                ? $this->sanitizeDiagnostics($value)
                : (is_bool($value)
                    ? $value
                    : (is_string($value) ? $this->sanitize($value) : null)))
            ->all();
    }

    /**
     * Structured tool output (named-checkconf/named-checkzone/rndc
     * stdout+stderr) legitimately contains file paths — that's the whole
     * diagnostic value. Unlike sanitize(), this intentionally skips the
     * path-redaction rule, since without it every BIND config error comes
     * back empty and the operator is back to SSH+journalctl to find out
     * why an apply failed.
     */
    private function sanitizeDiagnostics(mixed $diagnostics): ?array
    {
        if (! is_array($diagnostics)) {
            return null;
        }

        return collect($diagnostics)
            ->only(['command', 'returncode', 'stdout', 'stderr'])
            ->map(function ($value) {
                if (is_int($value) || is_bool($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $value = strip_tags($value);
                    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
                    $value = preg_replace(
                        '/\b(token|secret|password|authorization|api[_-]?key)\s*[:=]\s*\S+/iu',
                        '$1=[removido]',
                        $value,
                    ) ?? '';
                    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

                    return $value === '' ? null : Str::limit($value, 4000, '');
                }

                return null;
            })
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
