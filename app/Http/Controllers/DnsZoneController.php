<?php

namespace App\Http\Controllers;

use App\Models\DnsAgentPublication;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Services\BindZoneRenderer;
use App\Services\DnsReversePtrSynchronizer;
use App\Services\DnsZoneNameserverSynchronizer;
use App\Services\DnsZoneValidator;
use App\Services\ReverseZoneNameCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
                ->get(),
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
            ['kind' => 'primary'],
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
        ]);
    }

    public function storeRecord(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
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

        $this->validateRecord($validated);

        DB::transaction(function () use ($request, $zone, $validated, $organizationId, $renderer): void {
            $zone->records()->create([
                'organization_id' => $organizationId,
                'name' => $validated['name'] === '@'
                    ? $zone->name
                    : strtolower(rtrim(trim($validated['name']), '.')),
                'type' => $validated['type'],
                'ttl' => $validated['ttl'] ?? null,
                'priority' => $validated['type'] === 'MX'
                    ? ($validated['priority'] ?? 10)
                    : null,
                'content' => trim($validated['content']),
                'enabled' => true,
            ]);

            $this->bump($zone, $request, 'Registro adicionado.', $renderer);
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

        $this->validateRecord($validated);

        DB::transaction(function () use (
            $request,
            $zone,
            $record,
            $validated,
            $renderer,
        ): void {
            $record->forceFill([
                'name' => $validated['name'] === '@'
                    ? $zone->name
                    : strtolower(
                        rtrim(
                            trim($validated['name']),
                            '.',
                        ),
                    ),
                'type' => $validated['type'],
                'ttl' => $validated['ttl'] ?? null,
                'priority' => $validated['type'] === 'MX'
                    ? ($validated['priority'] ?? 10)
                    : null,
                'content' => trim($validated['content']),
            ])->save();

            $this->bump(
                $zone,
                $request,
                'Registro atualizado.',
                $renderer,
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

        DB::transaction(function () use ($request, $zone, $record, $renderer): void {
            $record->delete();
            $this->bump($zone, $request, 'Registro removido.', $renderer);
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
            $published = DB::transaction(function () use (
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

                return true;
            });
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

        return redirect(route('zones.index'))->with(
            'status',
            $published
                ? 'Publicação concluída. O artefato está disponível para os agentes configurados.'
                : 'Esta versão da zona já está publicada.',
        );
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
                'default_ttl' => $zoneAttributes['default_ttl'] ?? 3600,
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
