<?php

namespace App\Http\Controllers;

use App\Models\DnsAgentPublication;
use App\Models\DnsBindOperation;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Services\BindZoneRenderer;
use App\Services\DnsBindConfigConflicts;
use App\Services\DnsReversePtrSynchronizer;
use App\Services\DnsZoneNameserverSynchronizer;
use App\Services\DnsZoneValidator;
use App\Services\ReverseZoneNameCalculator;
use App\Support\DnsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class DnsZoneController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        return view('zones.index', [
            'zones' => DnsZone::query()
                ->forOrganization($organizationId)
                ->withCount('records')
                ->with('servers')
                ->orderBy('name')
                ->get()
                ->reject(fn (DnsZone $zone) => $zone->isReverseZone() || $zone->isIpv6ReverseZone())
                ->values(),
            'clients' => DnsZone::query()
                ->forOrganization($organizationId)
                ->whereNotNull('client')
                ->distinct()
                ->orderBy('client')
                ->pluck('client'),
            'servers' => DnsServer::query()
                ->forOrganization($organizationId)
                ->enabled()
                ->orderBy('name')
                ->get(),
            'nameserverProfiles' => DnsNameserverProfile::query()
                ->forOrganization($organizationId)
                ->enabled()
                ->with([
                    'identities' => fn ($query) => $query->where(
                        'dns_nameserver_identities.enabled',
                        true,
                    ),
                ])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function reverse(Request $request, ReverseZoneNameCalculator $calculator): View
    {
        $organizationId = $this->organizationId($request);

        $zones = DnsZone::query()
            ->forOrganization($organizationId)
            ->withCount('records')
            ->with('servers')
            ->orderBy('name')
            ->get();

        $describe = fn (DnsZone $zone, bool $ipv6) => [
            'zone' => $zone,
            'block' => $ipv6
                ? $calculator->toIpv6Prefix($zone->name)
                : $calculator->toIpv4Cidr($zone->name),
        ];

        return view('zones.reverse', [
            'ipv4Zones' => $zones
                ->filter(fn (DnsZone $zone) => $zone->isReverseZone())
                ->map(fn (DnsZone $zone) => $describe($zone, false))
                ->values(),
            'ipv6Zones' => $zones
                ->filter(fn (DnsZone $zone) => $zone->isIpv6ReverseZone())
                ->map(fn (DnsZone $zone) => $describe($zone, true))
                ->values(),
        ]);
    }

    public function store(
        Request $request,
        BindZoneRenderer $renderer,
        DnsZoneNameserverSynchronizer $nameservers,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}\\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}\\.?$/i',
                Rule::unique('dns_zones', 'name')
                    ->where('organization_id', $organizationId),
            ],
            'client' => [
                'nullable',
                'string',
                'max:255',
            ],
            'kind' => [
                'required',
                Rule::in(DnsZone::KINDS),
            ],
            'dns_nameserver_profile_id' => [
                'required',
                'integer',
                Rule::exists(
                    'dns_nameserver_profiles',
                    'id',
                )->where(
                    'organization_id',
                    $organizationId,
                ),
            ],
            'default_ttl' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_rname' => [
                'nullable',
                'string',
                'max:255',
            ],
            'soa_refresh' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_retry' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_expire' => [
                'required',
                'integer',
                'min:3600',
                'max:2147483647',
            ],
            'soa_minimum' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'primary_server_id' => [
                'required',
                'integer',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'secondary_server_id' => [
                'nullable',
                'integer',
                'different:primary_server_id',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $zoneName = $this->domain($validated['name']);

        $nameserverProfile =
            $nameservers->profileForOrganization(
                (int) $validated['dns_nameserver_profile_id'],
                $organizationId,
            );

        $primaryServer = DnsServer::query()
            ->forOrganization($organizationId)
            ->enabled()
            ->findOrFail(
                (int) $validated['primary_server_id'],
            );

        $secondaryServer = null;

        if (! empty($validated['secondary_server_id'])) {
            $secondaryServer = DnsServer::query()
                ->forOrganization($organizationId)
                ->enabled()
                ->findOrFail(
                    (int) $validated['secondary_server_id'],
                );
        }

        $zone = $this->createZone(
            $request,
            $renderer,
            $nameservers,
            $organizationId,
            $zoneName,
            [
                'client' => $validated['client'] ?? null,
                'kind' => $validated['kind'],
                'default_ttl' => $validated['default_ttl'],
                'soa_refresh' => $validated['soa_refresh'],
                'soa_retry' => $validated['soa_retry'],
                'soa_expire' => $validated['soa_expire'],
                'soa_minimum' => $validated['soa_minimum'],
                'notes' => $validated['notes'] ?? null,
            ],
            $nameserverProfile,
            $primaryServer,
            $secondaryServer,
        );

        $nsCount = $zone->records()
            ->where('type', 'NS')
            ->where(function ($query) use ($zone): void {
                $query
                    ->where('name', '@')
                    ->orWhere('name', $zone->name);
            })
            ->count();

        return redirect()
            ->route('zones.show', $zone)
            ->with(
                'status',
                sprintf(
                    'Zona salva. %d registro(s) NS foram configurados automaticamente. As alterações ainda não foram publicadas.',
                    $nsCount,
                ),
            );
    }

    public function storeReverse(
        Request $request,
        BindZoneRenderer $renderer,
        DnsZoneNameserverSynchronizer $nameservers,
        ReverseZoneNameCalculator $calculator,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);

        $validated = $request->validate([
            'family' => [
                'required',
                Rule::in(['ipv4', 'ipv6']),
            ],
            'cidr' => [
                'required',
                'string',
                'max:64',
            ],
            'dns_nameserver_profile_id' => [
                'required',
                'integer',
                Rule::exists(
                    'dns_nameserver_profiles',
                    'id',
                )->where(
                    'organization_id',
                    $organizationId,
                ),
            ],
            'primary_server_id' => [
                'required',
                'integer',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'secondary_server_id' => [
                'nullable',
                'integer',
                'different:primary_server_id',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'ptr_name_template' => $this->ptrNameTemplateRules(),
        ]);

        try {
            $zoneName = $validated['family'] === 'ipv4'
                ? $calculator->fromIpv4Cidr($validated['cidr'])
                : $calculator->fromIpv6Prefix($validated['cidr']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'cidr' => $exception->getMessage(),
            ]);
        }

        $exists = DnsZone::query()
            ->forOrganization($organizationId)
            ->where('name', $zoneName)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'cidr' => sprintf(
                    'Já existe uma zona reversa %s nesta organização.',
                    $zoneName,
                ),
            ]);
        }

        $nameserverProfile = $nameservers->profileForOrganization(
            (int) $validated['dns_nameserver_profile_id'],
            $organizationId,
        );

        $primaryServer = DnsServer::query()
            ->forOrganization($organizationId)
            ->enabled()
            ->findOrFail((int) $validated['primary_server_id']);

        $secondaryServer = null;

        if (! empty($validated['secondary_server_id'])) {
            $secondaryServer = DnsServer::query()
                ->forOrganization($organizationId)
                ->enabled()
                ->findOrFail((int) $validated['secondary_server_id']);
        }

        $zone = $this->createZone(
            $request,
            $renderer,
            $nameservers,
            $organizationId,
            $zoneName,
            [
                'kind' => 'primary',
                'ptr_name_template' => $validated['family'] === 'ipv4'
                    ? ($validated['ptr_name_template'] ?? null)
                    : null,
            ],
            $nameserverProfile,
            $primaryServer,
            $secondaryServer,
        );

        return redirect()
            ->route('zones.show', $zone)
            ->with(
                'status',
                sprintf('Zona reversa %s criada.', $zoneName),
            );
    }

    public function generatePtrFromForwardZone(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsReversePtrSynchronizer $synchronizer,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless($zone->isReverseZone(), 404);

        $validated = $request->validate([
            'forward_zone_id' => [
                'required',
                'integer',
                Rule::exists('dns_zones', 'id')
                    ->where('organization_id', $organizationId),
            ],
        ]);

        $forwardZone = DnsZone::query()
            ->forOrganization($organizationId)
            ->findOrFail((int) $validated['forward_zone_id']);

        $summary = DB::transaction(function () use (
            $zone,
            $forwardZone,
            $synchronizer,
            $request,
            $renderer,
        ): array {
            $summary = $synchronizer->synchronizeFromForwardZone(
                $zone,
                $forwardZone,
                $zone->ptr_name_template,
            );

            if (($summary['created'] + $summary['updated']) > 0) {
                $zone->forceFill([
                    'serial' => $this->nextSerial($zone->serial),
                    'version' => $zone->version + 1,
                    'status' => $zone->status === 'published'
                        ? 'ready'
                        : $zone->status,
                ])->save();

                $this->saveVersion(
                    $zone,
                    $request,
                    sprintf(
                        'PTR gerado a partir de %s.',
                        $forwardZone->name,
                    ),
                    $renderer,
                );
            }

            return $summary;
        });

        return redirect(route('zones.show', $zone).'#publication')
            ->with(
                'status',
                sprintf(
                    'PTR a partir de %s: %d criado(s), %d atualizado(s), %d sem alteração, %d sem endereço dentro do bloco.',
                    $forwardZone->name,
                    $summary['created'],
                    $summary['updated'],
                    $summary['unchanged'],
                    $summary['unmatched'],
                ),
            );
    }

    public function update(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsZoneNameserverSynchronizer $nameservers,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        $validated = $request->validate([
            'client' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(DnsZone::KINDS)],
            'dns_nameserver_profile_id' => [
                'required',
                'integer',
                Rule::exists(
                    'dns_nameserver_profiles',
                    'id',
                )->where(
                    'organization_id',
                    $organizationId,
                ),
            ],
            'default_ttl' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_rname' => ['required', 'string', 'max:255'],
            'soa_refresh' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_retry' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'soa_expire' => [
                'required',
                'integer',
                'min:3600',
                'max:2147483647',
            ],
            'soa_minimum' => [
                'required',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'primary_server_id' => [
                'required',
                'integer',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'secondary_server_id' => [
                'nullable',
                'integer',
                'different:primary_server_id',
                Rule::exists('dns_servers', 'id')
                    ->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ptr_name_template' => $this->ptrNameTemplateRules(),
        ]);

        $nameserverProfile =
            $nameservers->profileForOrganization(
                (int) $validated['dns_nameserver_profile_id'],
                $organizationId,
            );

        DB::transaction(function () use (
            $request,
            $zone,
            $validated,
            $renderer,
            $nameservers,
            $nameserverProfile,
        ): void {
            $zone->forceFill([
                'client' => isset($validated['client'])
                    ? trim($validated['client']) ?: null
                    : null,
                'kind' => $validated['kind'],
                'dns_nameserver_profile_id' => $nameserverProfile->id,
                'default_ttl' => $validated['default_ttl'],
                'soa_mname' => $this->domain(
                    $nameserverProfile->identities
                        ->sortBy(
                            fn ($identity): int => (int) $identity->pivot->position,
                        )
                        ->firstOrFail()
                        ->hostname,
                ),
                'soa_rname' => $this->domain(
                    $validated['soa_rname'],
                ),
                'soa_refresh' => $validated['soa_refresh'],
                'soa_retry' => $validated['soa_retry'],
                'soa_expire' => $validated['soa_expire'],
                'soa_minimum' => $validated['soa_minimum'],
                'notes' => $validated['notes'] ?? null,
                'ptr_name_template' => $zone->isReverseZone()
                    ? ($validated['ptr_name_template'] ?? null)
                    : null,
            ])->save();

            $servers = [
                (int) $validated['primary_server_id'] => [
                    'role' => 'primary',
                ],
            ];

            if (! empty($validated['secondary_server_id'])) {
                $servers[
                    (int) $validated['secondary_server_id']
                ] = [
                    'role' => 'secondary',
                ];
            }

            $zone->servers()->sync($servers);

            $nameservers->synchronize(
                $zone,
                $nameserverProfile,
            );

            $this->bump(
                $zone,
                $request,
                'Parâmetros da zona atualizados.',
                $renderer,
            );

            DnsAuditLogger::record(
                organizationId: $zone->organization_id,
                user: $request->user(),
                action: 'zone.settings_updated',
                domain: $zone->name,
            );
        });

        return back()->with(
            'status',
            'Zona salva. As alterações ainda não foram publicadas.',
        );
    }

    public function show(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsZoneValidator $validator,
        ReverseZoneNameCalculator $calculator,
    ): View {
        $this->authorizeZone($request, $zone);

        $relations = [
            'records' => fn ($query) => $query
                ->orderBy('type')
                ->orderBy('name'),
            'servers' => fn ($query) => $query->orderBy('name'),
            'tsigKey',
            'nameserverProfile.identities',
            'versions' => fn ($query) => $query
                ->latest('version')
                ->limit(10),
        ];
        $hasAuthoritativeObservations = Schema::hasTable(
            'dns_authoritative_observations',
        );
        if ($hasAuthoritativeObservations) {
            $relations[] = 'authoritativeObservations.server';
        }
        $zone->load($relations);
        if (! $hasAuthoritativeObservations) {
            $zone->setRelation('authoritativeObservations', collect());
        }

        $lastPublication = $zone->versions()
            ->where('reason', 'Zona publicada.')
            ->with([
                'agentPublications.server',
            ])
            ->latest('version')
            ->first();

        return view('zones.show', [
            'zone' => $zone,
            'preview' => $renderer->render($zone),
            'validation' => $validator->validate($zone),
            'lastPublication' => $lastPublication,
            'clients' => DnsZone::query()
                ->forOrganization($zone->organization_id)
                ->whereNotNull('client')
                ->distinct()
                ->orderBy('client')
                ->pluck('client'),
            'servers' => DnsServer::query()
                ->forOrganization($zone->organization_id)
                ->enabled()
                ->orderBy('name')
                ->get(),
            'nameserverProfiles' => DnsNameserverProfile::query()
                ->forOrganization(
                    $zone->organization_id,
                )
                ->enabled()
                ->with([
                    'identities' => fn ($query) => $query->where(
                        'dns_nameserver_identities.enabled',
                        true,
                    ),
                ])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'tsigKeys' => DnsTsigKey::query()
                ->forOrganization((int) $zone->organization_id)
                ->where('enabled', true)
                ->orderBy('name')
                ->get(),
            'reverseZones' => $reverseZones = DnsZone::query()
                ->forOrganization($zone->organization_id)
                ->where(function ($query): void {
                    $query
                        ->where('name', 'like', '%.in-addr.arpa')
                        ->orWhere('name', 'like', '%.ip6.arpa');
                })
                ->withCount('records')
                ->orderBy('name')
                ->get(['id', 'name', 'organization_id']),
            'reverseBlocks' => $reverseZones->mapWithKeys(
                fn (DnsZone $reverseZone): array => [
                    $reverseZone->id => $reverseZone->isIpv6ReverseZone()
                        ? $calculator->toIpv6Prefix($reverseZone->name)
                        : $calculator->toIpv4Cidr($reverseZone->name),
                ],
            ),
            'currentReverseBlock' => $zone->isIpv6ReverseZone()
                ? $calculator->toIpv6Prefix($zone->name)
                : ($zone->isReverseZone() ? $calculator->toIpv4Cidr($zone->name) : null),
            'forwardZones' => $zone->isReverseZone() || $zone->isIpv6ReverseZone()
                ? DnsZone::query()
                    ->forOrganization($zone->organization_id)
                    ->where(function ($query): void {
                        $query
                            ->where('name', 'not like', '%.in-addr.arpa')
                            ->where('name', 'not like', '%.ip6.arpa');
                    })
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'legacyZoneConflicts' => $zone->servers
                ->flatMap(fn (DnsServer $server) => collect((new DnsBindConfigConflicts)->forServer($server))
                    ->where('zone_id', $zone->id)
                    ->map(fn (array $conflict) => $conflict + [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                    ]))
                ->values(),
        ]);
    }

    public function storeRecord(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        ReverseZoneNameCalculator $calculator,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DnsRecord::TYPES)],
            'ttl' => ['nullable', 'integer', 'min:60', 'max:2147483647'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'content' => ['required', 'string', 'max:4096'],
        ]);

        $normalizedName = $this->normalizeRecordName(
            $validated['name'],
            $validated['type'],
            $zone,
            $calculator,
        );

        $this->validateRecord([...$validated, 'name' => $normalizedName]);

        DB::transaction(function () use ($request, $zone, $validated, $normalizedName, $organizationId, $renderer): void {
            $zone->records()->create([
                'organization_id' => $organizationId,
                'name' => $normalizedName,
                'type' => $validated['type'],
                'ttl' => $validated['ttl'] ?? null,
                'priority' => $validated['type'] === 'MX'
                    ? ($validated['priority'] ?? 10)
                    : null,
                'content' => trim($validated['content']),
                'enabled' => true,
            ]);

            $this->bump(
                $zone,
                $request,
                sprintf(
                    'Registro adicionado: %s %s → %s.',
                    $normalizedName,
                    $validated['type'],
                    trim($validated['content']),
                ),
                $renderer,
            );

            DnsAuditLogger::record(
                organizationId: $organizationId,
                user: $request->user(),
                action: 'zone.record.created',
                domain: $zone->name,
                recordType: $validated['type'],
                recordName: $normalizedName,
                newValue: trim($validated['content']),
            );
        });

        return back()->with(
            'status',
            'Registro DNS adicionado. As alterações ainda não foram publicadas.',
        );
    }

    public function updateRecord(
        Request $request,
        DnsZone $zone,
        DnsRecord $record,
        BindZoneRenderer $renderer,
        ReverseZoneNameCalculator $calculator,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless(
            (int) $record->dns_zone_id === (int) $zone->id
            && (int) $record->organization_id
                === (int) $zone->organization_id,
            404,
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DnsRecord::TYPES)],
            'ttl' => [
                'nullable',
                'integer',
                'min:60',
                'max:2147483647',
            ],
            'priority' => [
                'nullable',
                'integer',
                'min:0',
                'max:65535',
            ],
            'content' => ['required', 'string', 'max:4096'],
        ]);

        $normalizedName = $this->normalizeRecordName(
            $validated['name'],
            $validated['type'],
            $zone,
            $calculator,
        );

        $this->validateRecord([...$validated, 'name' => $normalizedName]);

        $before = sprintf('%s %s → %s', $record->name, $record->type, $record->content);

        DB::transaction(function () use (
            $request,
            $zone,
            $record,
            $validated,
            $normalizedName,
            $renderer,
            $before,
        ): void {
            $record->forceFill([
                'name' => $normalizedName,
                'type' => $validated['type'],
                'ttl' => $validated['ttl'] ?? null,
                'priority' => $validated['type'] === 'MX'
                    ? ($validated['priority'] ?? 10)
                    : null,
                'content' => trim($validated['content']),
            ])->save();

            $after = sprintf(
                '%s %s → %s',
                $normalizedName,
                $validated['type'],
                trim($validated['content']),
            );

            $this->bump(
                $zone,
                $request,
                $before === $after
                    ? sprintf('Registro atualizado: %s.', $after)
                    : sprintf('Registro atualizado: %s (era %s).', $after, $before),
                $renderer,
            );

            DnsAuditLogger::record(
                organizationId: $zone->organization_id,
                user: $request->user(),
                action: 'zone.record.updated',
                domain: $zone->name,
                recordType: $validated['type'],
                recordName: $normalizedName,
                oldValue: $before,
                newValue: $after,
            );
        });

        return back()->with(
            'status',
            'Registro DNS salvo. As alterações ainda não foram publicadas.',
        );
    }

    public function ptrSync(
        Request $request,
        DnsZone $zone,
        DnsReversePtrSynchronizer $synchronizer,
        BindZoneRenderer $renderer,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless($zone->isReverseZone(), 404);

        $identities = DnsNameserverIdentity::query()
            ->forOrganization($organizationId)
            ->enabled()
            ->get();

        $summary = DB::transaction(function () use ($zone, $identities, $synchronizer, $request, $renderer): array {
            $result = $synchronizer->synchronize($zone, $identities);

            if (($result['created'] + $result['updated']) > 0) {
                $this->bump(
                    $zone,
                    $request,
                    'PTR vinculado às identidades de nameserver.',
                    $renderer,
                );
            }

            return $result;
        });

        $changed = $summary['created'] + $summary['updated'];

        return redirect(route('zones.show', $zone).'#publication')->with(
            'status',
            $changed > 0
                ? sprintf(
                    '%d registro(s) PTR vinculado(s) (%d criado(s), %d atualizado(s)). %d já estavam corretos.',
                    $changed,
                    $summary['created'],
                    $summary['updated'],
                    $summary['unchanged'],
                )
                : 'Nenhum registro PTR precisou de alteração.',
        );
    }

    public function destroyRecord(
        Request $request,
        DnsZone $zone,
        DnsRecord $record,
        BindZoneRenderer $renderer,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless(
            (int) $record->dns_zone_id === (int) $zone->id
            && (int) $record->organization_id === (int) $zone->organization_id,
            404,
        );

        $description = sprintf('%s %s → %s', $record->name, $record->type, $record->content);

        DB::transaction(function () use ($request, $zone, $record, $renderer, $description): void {
            $recordType = $record->type;
            $recordName = $record->name;
            $recordContent = $record->content;

            $record->delete();
            $this->bump(
                $zone,
                $request,
                sprintf('Registro removido: %s.', $description),
                $renderer,
            );

            DnsAuditLogger::record(
                organizationId: $zone->organization_id,
                user: $request->user(),
                action: 'zone.record.deleted',
                domain: $zone->name,
                recordType: $recordType,
                recordName: $recordName,
                oldValue: $recordContent,
            );
        });

        return back()->with(
            'status',
            'Registro DNS removido. As alterações ainda não foram publicadas.',
        );
    }

    public function adopt(
        Request $request,
        DnsZone $zone,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless($zone->isImportedUnmanaged(), 404);

        $zone->forceFill(['origin' => 'managed'])->save();

        return redirect(route('zones.show', $zone).'#publication')->with(
            'status',
            'Adoção concluída. A zona agora pode ser publicada normalmente.',
        );
    }

    public function publish(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsZoneValidator $validator,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_if(
            $zone->origin === 'bind_import',
            409,
            'Conclua a adoção do gerenciamento antes de publicar esta zona.',
        );

        try {
            $published = $this->performPublish($request, $zone, $renderer, $validator);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Falha controlada ao publicar zona DNS.', [
                'zone_id' => $zone->id,
                'organization_id' => $zone->organization_id,
                'exception' => $exception::class,
            ]);

            return redirect(route('zones.show', $zone).'#publication')->withErrors([
                'zone' => 'Não foi possível concluir a publicação. Verifique o servidor e o agente de publicação e tente novamente.',
            ]);
        }

        $response = redirect(route('zones.show', $zone).'#publication')->with(
            'status',
            $published
                ? 'Publicação concluída. O artefato está disponível para os agentes configurados.'
                : $this->nothingToPublishMessage($zone, $validator),
        );

        return $published ? $response : $response->with('status_go_tab', 'configuration');
    }

    /**
     * Publica a versão salva da zona (valida, renderiza, incrementa
     * serial/versão e agenda uma DnsAgentPublication "pending" por
     * servidor). Retorna true se publicou agora, false se já estava
     * publicada (nenhuma mudança feita). Lança ValidationException em
     * caso de zona inválida ou servidores de publicação indisponíveis.
     */
    private function performPublish(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsZoneValidator $validator,
    ): bool {
        return DB::transaction(function () use (
            $request,
            $zone,
            $renderer,
            $validator,
        ): bool {
            $lockedZone = DnsZone::query()
                ->whereKey($zone->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeZone($request, $lockedZone);

            if ($lockedZone->status === 'published') {
                return false;
            }

            $lockedZone->load([
                'records',
                'servers.agent',
                'nameserverProfile.identities',
            ]);

            $result = $validator->validate($lockedZone);

            if (! $result['ok']) {
                throw ValidationException::withMessages([
                    'zone' => $result['errors'],
                ]);
            }

            $this->ensurePublishingServersAvailable($lockedZone);

            // A renderização também valida o artefato antes de expô-lo.
            $renderer->render($lockedZone);

            $lockedZone->forceFill([
                'status' => 'published',
                'serial' => $this->nextSerial($lockedZone->serial),
                'version' => $lockedZone->version + 1,
            ])->save();

            $version = $this->saveVersion(
                $lockedZone,
                $request,
                'Zona publicada.',
                $renderer,
            );

            foreach ($lockedZone->servers as $server) {
                DnsAgentPublication::query()->create([
                    'organization_id' => $lockedZone->organization_id,
                    'dns_zone_version_id' => $version->id,
                    'dns_server_id' => $server->id,
                    'dns_agent_id' => $server->agent->id,
                    'status' => 'pending',
                ]);
            }

            DnsAuditLogger::record(
                organizationId: $lockedZone->organization_id,
                user: $request->user(),
                action: 'zone.published',
                domain: $lockedZone->name,
            );

            return true;
        });
    }

    public function publishAndSync(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
        DnsZoneValidator $validator,
    ): JsonResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_if(
            $zone->origin === 'bind_import',
            409,
            'Conclua a adoção do gerenciamento antes de publicar esta zona.',
        );

        try {
            $published = $this->performPublish($request, $zone, $renderer, $validator);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => collect($exception->errors())
                    ->flatten()
                    ->first() ?? 'Não foi possível publicar esta zona.',
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Falha controlada ao publicar zona DNS.', [
                'zone_id' => $zone->id,
                'organization_id' => $zone->organization_id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Não foi possível concluir a publicação. Verifique o servidor e o agente de publicação e tente novamente.',
            ], 409);
        }

        $zone->refresh()->load('servers.agent');

        // O secundário só tem o serial novo depois de transferir a zona do primário.
        // Como cada agente consulta o painel no próprio relógio, aplicar nos dois ao
        // mesmo tempo fazia o secundário desistir antes de o primário terminar de
        // carregar. Enquanto a publicação desta versão não estiver aplicada no
        // primário, o secundário fica "adiado" e a tela o libera depois.
        $latestVersionId = $zone->versions()->max('id');
        $primaryServerIds = $zone->servers
            ->filter(fn (DnsServer $server): bool => $server->pivot->role === 'primary')
            ->pluck('id');

        $primaryNotApplied = $latestVersionId !== null
            && $primaryServerIds->isNotEmpty()
            && DnsAgentPublication::query()
                ->where('dns_zone_version_id', $latestVersionId)
                ->whereIn('dns_server_id', $primaryServerIds)
                ->where('status', '!=', 'applied')
                ->exists();

        $targets = $zone->servers->map(function (DnsServer $server) use ($zone, $request, $primaryNotApplied): array {
            $hasPending = DnsAgentPublication::query()
                ->where('dns_server_id', $server->id)
                ->whereIn('status', ['pending', 'downloaded', 'applying', 'failed'])
                ->exists();

            if (! $hasPending) {
                return [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'skipped' => 'já sincronizado',
                    'deferred' => false,
                    'status_url' => route('servers.bind.apply.status', $server),
                ];
            }

            if ($server->pivot->role === 'secondary' && $primaryNotApplied) {
                return [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'skipped' => null,
                    'deferred' => true,
                    'status_url' => route('servers.bind.apply.status', $server),
                ];
            }

            DnsBindOperation::expireStaleOperations($server->id);

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'apply_zones')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            if (! $inFlight) {
                DnsBindOperation::query()->create([
                    'organization_id' => $zone->organization_id,
                    'dns_server_id' => $server->id,
                    'dns_agent_id' => $server->agent->id,
                    'action' => 'apply_zones',
                    'status' => 'authorized',
                    'authorization_nonce' => (string) Str::uuid(),
                    'authorized_by' => $request->user()->id,
                    'authorized_at' => now(),
                ]);
            }

            return [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'skipped' => null,
                'deferred' => false,
                'status_url' => route('servers.bind.apply.status', $server),
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'published' => $published,
            // Nada novo para publicar e nenhum servidor pendente: a tela deve dizer
            // isso claramente (e apontar para a aba Configuração) em vez de um "ok" mudo.
            'nothing_new' => ! $published
                && $targets->isNotEmpty()
                && $targets->every(fn (array $target): bool => $target['skipped'] !== null),
            'nothing_new_message' => $published ? null : $this->nothingToPublishMessage($zone, $validator),
            'targets' => $targets,
        ]);
    }

    /**
     * Explica por que não há nada a publicar. Se a zona tem NS repetido (sintoma de
     * uma configuração que precisa ser salva de novo), diz isso em vez de um "ok" mudo.
     */
    private function nothingToPublishMessage(DnsZone $zone, DnsZoneValidator $validator): string
    {
        $duplicates = $validator->apexNameserverDuplicates($zone->refresh());

        if ($duplicates !== []) {
            return sprintf(
                'Nada novo para publicar, mas o apex desta zona tem NS repetido (%s). Salve a configuração na aba Configuração para normalizar e depois publique.',
                implode(', ', $duplicates),
            );
        }

        return 'Sem alterações desde a última publicação. Para mudar servidores, perfil de nameservers ou SOA, salve na aba Configuração; se editou registros, publique de novo.';
    }

    private function normalizeRecordName(
        string $name,
        string $type,
        DnsZone $zone,
        ReverseZoneNameCalculator $calculator,
    ): string {
        if ($name === '@') {
            return $zone->name;
        }

        $trimmed = strtolower(rtrim(trim($name), '.'));

        $isReverse = $zone->isReverseZone() || $zone->isIpv6ReverseZone();

        if (
            $type === 'PTR'
            && $isReverse
            && filter_var($trimmed, FILTER_VALIDATE_IP)
        ) {
            $ptrName = $zone->isIpv6ReverseZone()
                ? $calculator->ipv6ToPtrName($trimmed)
                : $calculator->ipv4ToPtrName($trimmed);

            if ($ptrName !== null) {
                return $ptrName;
            }
        }

        return $trimmed;
    }

    private function validateRecord(array $record): void
    {
        $name = trim($record['name']);
        $type = $record['type'];
        $content = trim($record['content']);

        if (
            $name !== '@'
            && ! preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(?:\.(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?))*\.?$/i', $name)
        ) {
            throw ValidationException::withMessages([
                'name' => 'Informe um nome DNS válido.',
            ]);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $content)) {
            throw ValidationException::withMessages([
                'content' => 'O valor do registro contém caracteres inválidos.',
            ]);
        }

        if ($type === 'A' && ! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw ValidationException::withMessages(['content' => 'Informe um IPv4 válido.']);
        }

        if ($type === 'AAAA' && ! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw ValidationException::withMessages(['content' => 'Informe um IPv6 válido.']);
        }

        if (
            in_array($type, ['CNAME', 'MX', 'NS', 'PTR'], true)
            && ! preg_match('/^(?=.{1,253}\.?$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9_-]{1,63}\.?$/i', $content)
        ) {
            throw ValidationException::withMessages(['content' => 'Informe um hostname válido.']);
        }

        if ($type === 'CAA' && ! preg_match('/^\d+\s+[a-z0-9-]+\s+.+$/i', $content)) {
            throw ValidationException::withMessages(['content' => 'Use: 0 issue letsencrypt.org.']);
        }
    }

    private function bump(
        DnsZone $zone,
        Request $request,
        string $reason,
        BindZoneRenderer $renderer,
    ): void {
        $zone->forceFill([
            'serial' => $this->nextSerial($zone->serial),
            'version' => $zone->version + 1,
            'status' => $zone->status === 'published' ? 'ready' : $zone->status,
        ])->save();

        $this->saveVersion($zone, $request, $reason, $renderer);
    }

    private function ensurePublishingServersAvailable(DnsZone $zone): void
    {
        if ($zone->servers->isEmpty()) {
            throw ValidationException::withMessages([
                'zone' => 'Configure ao menos um servidor de publicação.',
            ]);
        }

        foreach ($zone->servers as $server) {
            $agent = $server->agent;
            $available = $server->enabled
                && $server->status === 'online'
                && $server->agent_status === 'online'
                && $agent !== null
                && $agent->isActive()
                && (int) $agent->organization_id
                    === (int) $zone->organization_id;

            if (! $available) {
                throw ValidationException::withMessages([
                    'zone' => sprintf(
                        'O servidor de publicação %s ou seu agente está indisponível.',
                        $server->name,
                    ),
                ]);
            }
        }
    }

    /**
     * @param  array{
     *     client?: string|null,
     *     kind: string,
     *     default_ttl?: int,
     *     soa_refresh?: int,
     *     soa_retry?: int,
     *     soa_expire?: int,
     *     soa_minimum?: int,
     *     notes?: string|null,
     * }  $zoneAttributes
     */
    private function createZone(
        Request $request,
        BindZoneRenderer $renderer,
        DnsZoneNameserverSynchronizer $nameservers,
        int $organizationId,
        string $zoneName,
        array $zoneAttributes,
        DnsNameserverProfile $nameserverProfile,
        DnsServer $primaryServer,
        ?DnsServer $secondaryServer,
    ): DnsZone {
        return DB::transaction(function () use (
            $request,
            $zoneAttributes,
            $organizationId,
            $renderer,
            $zoneName,
            $primaryServer,
            $secondaryServer,
            $nameservers,
            $nameserverProfile,
        ): DnsZone {
            $zone = DnsZone::query()->create([
                'organization_id' => $organizationId,
                'dns_nameserver_profile_id' => $nameserverProfile->id,
                'name' => $zoneName,
                'client' => isset($zoneAttributes['client'])
                    ? trim($zoneAttributes['client']) ?: null
                    : null,
                'kind' => $zoneAttributes['kind'],
                'serial' => $this->nextSerial(),
                'default_ttl' => $zoneAttributes['default_ttl'] ?? 300,
                'soa_mname' => $this->domain(
                    $nameserverProfile->identities
                        ->sortBy(
                            fn ($identity): int => (int) $identity->pivot->position,
                        )
                        ->firstOrFail()
                        ->hostname,
                ),
                'soa_rname' => 'hostmaster.'.$zoneName,
                'soa_refresh' => $zoneAttributes['soa_refresh'] ?? 3600,
                'soa_retry' => $zoneAttributes['soa_retry'] ?? 900,
                'soa_expire' => $zoneAttributes['soa_expire'] ?? 1209600,
                'soa_minimum' => $zoneAttributes['soa_minimum'] ?? 300,
                'status' => 'draft',
                'version' => 1,
                'enabled' => true,
                'notes' => isset($zoneAttributes['notes'])
                    ? trim($zoneAttributes['notes'])
                    : null,
                'ptr_name_template' => $zoneAttributes['ptr_name_template'] ?? null,
            ]);

            $sync = [
                (int) $primaryServer->id => [
                    'role' => 'primary',
                ],
            ];

            if ($secondaryServer !== null) {
                $sync[(int) $secondaryServer->id] = [
                    'role' => 'secondary',
                ];
            }

            $zone->servers()->sync($sync);

            $nameservers->synchronize(
                $zone,
                $nameserverProfile,
            );

            $nameServers = $nameserverProfile
                ->identities
                ->pluck('hostname')
                ->filter()
                ->map(
                    fn (string $hostname): string => $this->domain($hostname),
                )
                ->unique()
                ->values();

            $this->saveVersion(
                $zone,
                $request,
                sprintf(
                    'Domínio criado com %d registro(s) NS automático(s).',
                    $nameServers->count(),
                ),
                $renderer,
            );

            DnsAuditLogger::record(
                organizationId: $organizationId,
                user: $request->user(),
                action: 'zone.created',
                domain: $zoneName,
            );

            return $zone;
        });
    }

    private function saveVersion(
        DnsZone $zone,
        Request $request,
        string $reason,
        BindZoneRenderer $renderer,
    ): DnsZoneVersion {
        $zone->refresh()->load([
            'records',
            'servers',
            'nameserverProfile.identities',
        ]);

        return DnsZoneVersion::query()->create([
            'organization_id' => $zone->organization_id,
            'dns_zone_id' => $zone->id,
            'created_by' => $request->user()->id,
            'version' => $zone->version,
            'serial' => $zone->serial,
            'reason' => $reason,
            'snapshot' => $renderer->snapshot($zone),
        ]);
    }

    private function nextSerial(?int $current = null): int
    {
        return max((int) now()->format('Ymd').'00', ($current ?? 0) + 1);
    }

    private function domain(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }

    /**
     * @return array<int, string>
     */
    private function ptrNameTemplateRules(): array
    {
        return [
            'nullable',
            'string',
            'max:50',
            'regex:/^[a-z0-9_-]*\$[a-z0-9_-]*$/i',
        ];
    }

    private function authorizeZone(Request $request, DnsZone $zone): void
    {
        abort_unless(
            (int) $zone->organization_id === $this->organizationId($request),
            404,
        );
    }

    private function authorizeWrite(Request $request): int
    {
        $organizationId = $this->organizationId($request);
        $user = $request->user();
        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin || $role === 'organization_admin',
            403,
        );

        return $organizationId;
    }

    private function organizationId(Request $request): int
    {
        $organizationId = (int) $request->user()->current_organization_id;
        abort_unless($organizationId > 0, 403);

        return $organizationId;
    }
}
