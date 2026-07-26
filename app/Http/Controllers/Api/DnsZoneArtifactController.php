<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsZone;
use App\Services\BindZoneRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DnsZoneArtifactController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        $zones = DnsZone::query()
            ->where('organization_id', $agent->organization_id)
            ->where('status', 'published')
            ->where('enabled', true)
            ->whereHas('servers', fn ($query) => $query->where(
                'dns_servers.id',
                $agent->dns_server_id,
            ))
            ->orderBy('name')
            ->get();

        return response()->json([
            'ok' => true,
            'zones' => $zones->map(fn (DnsZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'kind' => $zone->kind,
                'serial' => $zone->serial,
                'version' => $zone->version,
                'artifact_url' => route('api.agent.zones.artifact', $zone, false),
            ])->values(),
        ]);
    }

    public function artifact(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
    ): Response {
        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        abort_unless(
            (int) $zone->organization_id === (int) $agent->organization_id
            && $zone->status === 'published'
            && $zone->enabled
            && $zone->servers()->where('dns_servers.id', $agent->dns_server_id)->exists(),
            404,
        );

        return response($renderer->render($zone), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-DNS-Zone-Serial' => (string) $zone->serial,
            'X-DNS-Zone-Version' => (string) $zone->version,
        ]);
    }
}
