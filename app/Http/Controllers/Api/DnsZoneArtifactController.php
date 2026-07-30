<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsZone;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DnsZoneArtifactController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $destinations = DnsAgentPublication::query()
            ->with([
                'zoneVersion.zone.servers',
                'zoneVersion.zone.tsigKey',
            ])
            ->where('organization_id', $agent->organization_id)
            ->where('dns_server_id', $agent->dns_server_id)
            ->where('dns_agent_id', $agent->id)
            ->whereHas(
                'zoneVersion.zone',
                fn ($query) => $query->where('enabled', true),
            )
            ->orderByDesc('dns_zone_version_id')
            ->get();

        return response()->json([
            'ok' => true,
            'zones' => $destinations
                ->unique(fn (DnsAgentPublication $item) => $item->zoneVersion->dns_zone_id)
                ->map(function (DnsAgentPublication $item) use ($destinations): array {
                    $zone = $item->zoneVersion->zone;
                    $currentServer = $zone->servers->firstWhere(
                        'id',
                        $item->dns_server_id,
                    );
                    $role = (string) ($currentServer?->pivot?->role ?? '');
                    abort_unless(in_array($role, ['primary', 'secondary'], true), 409);

                    $primary = $zone->servers->first(
                        fn ($server): bool => $server->pivot?->role === 'primary',
                    );
                    $secondaries = $zone->servers->filter(
                        fn ($server): bool => $server->pivot?->role === 'secondary',
                    );
                    $tsigKey = $zone->tsigKey;
                    $artifact = (string) data_get(
                        $item->zoneVersion->snapshot,
                        'zonefile',
                    );
                    $installedVersion = $destinations
                        ->first(
                            fn (DnsAgentPublication $candidate): bool => $candidate->status === 'applied'
                                && $candidate->zoneVersion->dns_zone_id
                                    === $item->zoneVersion->dns_zone_id,
                        )?->installed_version;

                    return [
                        'id' => $zone->id,
                        'publication_id' => $item->id,
                        'name' => $zone->name,
                        'kind' => $role,
                        'type' => $role,
                        'serial' => $item->zoneVersion->serial,
                        'version' => $item->zoneVersion->version,
                        'desired_version' => $item->zoneVersion->version,
                        'installed_version' => $installedVersion,
                        'apply_status' => $item->status,
                        'update_available' => $installedVersion
                            !== $item->zoneVersion->version,
                        'reported_serial' => $item->reported_serial,
                        'serial_confirmed_at' => $item->serial_confirmed_at?->toIso8601String(),
                        'artifact_checksum' => $role === 'primary'
                            ? hash('sha256', $artifact)
                            : null,
                        'artifact_size' => $role === 'primary' ? strlen($artifact) : 0,
                        'artifact_url' => $role === 'primary'
                            ? route(
                                'api.agent.zones.artifact',
                                [$zone, 'publication' => $item->id],
                                false,
                            )
                            : null,
                        'transfer' => [
                            'primary_addresses' => array_values(array_filter([
                                $primary?->ipv4_address,
                                $primary?->ipv6_address,
                            ])),
                            'secondary_addresses' => $secondaries
                                ->flatMap(fn ($server): array => array_filter([
                                    $server->ipv4_address,
                                    $server->ipv6_address,
                                ]))
                                ->values()
                                ->all(),
                            'tsig' => $tsigKey && $tsigKey->enabled
                                ? [
                                    'name' => $tsigKey->name,
                                    'algorithm' => $tsigKey->algorithm,
                                    'secret' => $tsigKey->secret,
                                ]
                                : null,
                        ],
                    ];
                })->values(),
        ]);
    }

    public function artifact(
        Request $request,
        DnsZone $zone,
    ): Response {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $destinationQuery = DnsAgentPublication::query()
            ->with('zoneVersion')
            ->where('organization_id', $agent->organization_id)
            ->where('dns_server_id', $agent->dns_server_id)
            ->where('dns_agent_id', $agent->id)
            ->whereHas(
                'zoneVersion',
                fn ($query) => $query->where('dns_zone_id', $zone->id),
            );

        if ($request->filled('publication')) {
            $destinationQuery->whereKey($request->integer('publication'));
        } else {
            $destinationQuery->latest('dns_zone_version_id');
        }

        $destination = $destinationQuery->firstOrFail();

        $role = $zone->servers()
            ->where('dns_servers.id', $agent->dns_server_id)
            ->first()?->pivot?->role;

        abort_unless(
            (int) $zone->organization_id === (int) $agent->organization_id
            && $zone->enabled
            && $role === 'primary',
            404,
        );

        if ($destination->status === 'pending') {
            $destination->forceFill([
                'status' => 'downloaded',
                'downloaded_at' => now(),
            ])->save();
        } elseif ($destination->downloaded_at === null) {
            $destination->forceFill(['downloaded_at' => now()])->save();
        }

        SecurityAuditLogger::record(
            event: 'agent.artifact_downloaded',
            user: null,
            result: 'success',
            actor: 'dns_agent:'.$agent->id,
            source: 'agent_api',
            ipAddress: $request->ip(),
        );

        $artifact = (string) data_get(
            $destination->zoneVersion->snapshot,
            'zonefile',
        );

        return response(
            $artifact,
            200,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Length' => (string) strlen($artifact),
                'X-DNS-Artifact-SHA256' => hash('sha256', $artifact),
                'X-DNS-Zone-Serial' => (string) $destination->zoneVersion->serial,
                'X-DNS-Zone-Version' => (string) $destination->zoneVersion->version,
                'X-DNS-Publication-Id' => (string) $destination->id,
            ],
        );
    }
}
