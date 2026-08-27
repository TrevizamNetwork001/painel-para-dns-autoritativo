<?php

namespace App\Http\Controllers;

use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DnsBindDiscoveryController extends Controller
{
    public function store(Request $request, DnsServer $server): RedirectResponse|JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        $agent = $server->agent;
        abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');

        $inFlight = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->where('action', 'discover_bind_zones')
            ->whereIn('status', ['authorized', 'running'])
            ->exists();

        abort_if($inFlight, 409, 'Já existe uma descoberta em andamento para este servidor.');

        $operation = DnsBindOperation::query()->create([
            'organization_id' => $organizationId,
            'dns_server_id' => $server->id,
            'dns_agent_id' => $agent->id,
            'action' => 'discover_bind_zones',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $request->user()->id,
            'authorized_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'operation_id' => $operation->id,
                'status' => $operation->status,
            ]);
        }

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Descoberta de BIND solicitada. O agente executa na próxima janela do timer, somente leitura.');
    }

    public function status(Request $request, DnsServer $server): JsonResponse
    {
        $this->authorizeServer($request, $server);

        $operation = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->where('action', 'discover_bind_zones')
            ->latest('id')
            ->first();

        if (! $operation) {
            return response()->json(['ok' => true, 'status' => null]);
        }

        $summary = null;

        if ($operation->status === 'succeeded') {
            $zones = DnsBindDiscoveredZone::query()
                ->where('dns_bind_operation_id', $operation->id)
                ->get(['name', 'detected_type', 'comparison_state']);

            $summary = [
                'total' => $zones->count(),
                'primary' => $zones->where('detected_type', 'primary')->count(),
                'secondary' => $zones->where('detected_type', 'secondary')->count(),
                'new' => $zones->where('comparison_state', 'new')->count(),
                'exists' => $zones->where('comparison_state', 'exists')->count(),
                'conflict' => $zones->where('comparison_state', 'conflict')->count(),
                'not_supported' => $zones->where('comparison_state', 'not_supported')->count(),
                'zones' => $zones->take(20)->map(fn ($zone) => [
                    'name' => $zone->name,
                    'state' => $zone->comparison_state,
                ])->values(),
            ];
        }

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'error' => $operation->status === 'failed' ? $operation->error : null,
            'summary' => $summary,
            'discovery_url' => route('servers.bind.discovery.show', $server),
        ]);
    }

    public function show(Request $request, DnsServer $server): View
    {
        $organizationId = $this->authorizeServer($request, $server);

        $lastOperation = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->where('action', 'discover_bind_zones')
            ->latest('id')
            ->first();

        $zones = $lastOperation && $lastOperation->status === 'succeeded'
            ? DnsBindDiscoveredZone::query()
                ->where('dns_bind_operation_id', $lastOperation->id)
                ->orderBy('name')
                ->paginate(20)
            : DnsBindDiscoveredZone::query()->whereRaw('1 = 0')->paginate(20);

        $summary = [
            'total' => $zones->total(),
            'primary' => (clone $zones->getCollection())->where('detected_type', 'primary')->count(),
            'secondary' => (clone $zones->getCollection())->where('detected_type', 'secondary')->count(),
        ];

        return view('servers.bind-discovery', [
            'server' => $server,
            'lastOperation' => $lastOperation,
            'zones' => $zones,
            'summary' => $summary,
        ]);
    }

    public function showZone(
        Request $request,
        DnsServer $server,
        DnsBindDiscoveredZone $zone,
    ): View {
        $organizationId = $this->authorizeServer($request, $server);

        abort_unless(
            (int) $zone->organization_id === $organizationId
                && (int) $zone->dns_server_id === (int) $server->id,
            404,
        );

        $allRecords = collect($zone->records ?? []);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $records = $allRecords->forPage($page, $perPage)->values();

        return view('servers.bind-discovery-zone', [
            'server' => $server,
            'zone' => $zone,
            'records' => $records,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $allRecords->count(),
        ]);
    }

    public function import(Request $request, DnsServer $server): RedirectResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        $validated = $request->validate([
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer'],
        ]);

        $batchId = (string) Str::uuid();
        $imported = 0;
        $skipped = 0;

        foreach ($validated['zone_ids'] as $zoneId) {
            $outcome = $this->importOne($request, $server, $organizationId, (int) $zoneId, $batchId);
            $outcome === 'imported' ? $imported++ : $skipped++;
        }

        return redirect()
            ->route('servers.bind.discovery.show', $server)
            ->with('status', "Importação concluída: {$imported} zona(s) importada(s), {$skipped} ignorada(s).");
    }

    private function importOne(
        Request $request,
        DnsServer $server,
        int $organizationId,
        int $discoveredZoneId,
        string $batchId,
    ): string {
        try {
            return DB::transaction(function () use ($request, $server, $organizationId, $discoveredZoneId, $batchId): string {
                $discovered = DnsBindDiscoveredZone::query()
                    ->whereKey($discoveredZoneId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $discovered
                    || (int) $discovered->organization_id !== $organizationId
                    || (int) $discovered->dns_server_id !== (int) $server->id
                ) {
                    return 'skipped';
                }

                if (
                    $discovered->detected_type !== 'primary'
                    || ! empty($discovered->unsupported_record_types)
                    || $discovered->validation_status === 'error'
                    || ! is_array($discovered->records)
                ) {
                    $this->auditImport($request, $discovered, 'dns.bind_zone_import_skipped', 'skipped');

                    return 'skipped';
                }

                $existing = DnsZone::query()
                    ->where('organization_id', $organizationId)
                    ->where('name', $discovered->name)
                    ->first();

                if ($existing) {
                    $this->auditImport($request, $discovered, 'dns.bind_zone_import_conflict', 'conflict');

                    return 'skipped';
                }

                $soaData = is_array($discovered->soa) ? $discovered->soa : [];

                $zone = DnsZone::query()->create([
                    'organization_id' => $organizationId,
                    'name' => $discovered->name,
                    'kind' => 'primary',
                    'serial' => $discovered->serial ?? 1,
                    'default_ttl' => 3600,
                    'soa_mname' => $soaData['mname'] ?? ($discovered->name.'.'),
                    'soa_rname' => $soaData['rname'] ?? ('hostmaster.'.$discovered->name.'.'),
                    'soa_refresh' => $soaData['refresh'] ?? 3600,
                    'soa_retry' => $soaData['retry'] ?? 900,
                    'soa_expire' => $soaData['expire'] ?? 1209600,
                    'soa_minimum' => $soaData['minimum'] ?? 300,
                    'status' => 'draft',
                    'version' => 1,
                    'enabled' => true,
                    'origin' => 'bind_import',
                    'imported_at' => now(),
                    'imported_by' => $request->user()->id,
                    'import_source_id' => $discovered->id,
                    'import_batch_id' => $batchId,
                ]);

                foreach ($discovered->records as $record) {
                    if (! is_array($record) || ! in_array($record['type'] ?? null, DnsRecord::TYPES, true)) {
                        continue;
                    }

                    [$content, $priority] = $this->splitRecordContent($record['type'], $record['content'] ?? '');

                    DnsRecord::query()->create([
                        'organization_id' => $organizationId,
                        'dns_zone_id' => $zone->id,
                        'name' => $record['name'] ?? '@',
                        'type' => $record['type'],
                        'ttl' => $record['ttl'] ?? null,
                        'priority' => $priority,
                        'content' => $content,
                        'enabled' => true,
                    ]);
                }

                $discovered->forceFill(['comparison_state' => 'imported'])->save();

                $this->auditImport($request, $discovered, 'dns.bind_zone_imported', 'success');

                return 'imported';
            });
        } catch (\Throwable $exception) {
            report($exception);

            return 'skipped';
        }
    }

    private function splitRecordContent(string $type, string $content): array
    {
        if ($type !== 'MX') {
            return [$content, null];
        }

        $parts = preg_split('/\s+/', trim($content), 2);

        if (count($parts) === 2 && ctype_digit($parts[0])) {
            return [$parts[1], (int) $parts[0]];
        }

        return [$content, 10];
    }

    private function auditImport(Request $request, DnsBindDiscoveredZone $discovered, string $event, string $result): void
    {
        SecurityAuditLogger::record(
            event: $event,
            user: $request->user(),
            result: $result,
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $discovered->organization_id,
            reason: 'discovered_zone:'.$discovered->id.';name:'.$discovered->name,
        );
    }

    private function authorizeServer(Request $request, DnsServer $server): int
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        abort_unless($organizationId > 0, 403);
        abort_unless((int) $server->organization_id === $organizationId, 404);

        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin || $role === 'organization_admin',
            403,
        );

        return $organizationId;
    }
}
