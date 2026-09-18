<?php

namespace App\Services;

use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compara a última descoberta de um servidor com a última descoberta dos
 * demais servidores da mesma organização. Só lê dados já coletados: nunca
 * fala com o agente nem altera zonas.
 */
class DnsBindDiscoveryDivergence
{
    /**
     * @param  Collection<int, DnsBindDiscoveredZone>  $zones  zonas (da página) deste servidor
     * @param  Collection<int, string>  $allNames  todos os nomes descobertos neste servidor
     * @return array{peers: array<int, array<string, mixed>>, by_zone: array<string, array<string, mixed>>, missing_here: array<int, array<string, mixed>>}
     */
    public function compare(DnsServer $server, Collection $zones, Collection $allNames): array
    {
        $peers = $this->relatedPeers($server, $this->peerDiscoveries($server), $allNames);

        if ($peers === []) {
            return ['peers' => [], 'by_zone' => [], 'missing_here' => []];
        }

        $byZone = [];

        foreach ($zones as $zone) {
            $findings = [];

            foreach ($peers as $peer) {
                $other = $peer['zones'][strtolower($zone->name)] ?? null;

                if ($other === null) {
                    $findings[] = ['server' => $peer['name'], 'state' => 'missing'];

                    continue;
                }

                $sameSerial = $zone->serial === null
                    || $other->serial === null
                    || (int) $zone->serial === (int) $other->serial;

                $findings[] = [
                    'server' => $peer['name'],
                    'state' => $sameSerial ? 'ok' : 'serial',
                    'serial' => $other->serial,
                    'type' => $other->detected_type,
                ];
            }

            $byZone[$zone->name] = [
                'status' => collect($findings)->contains(fn (array $f) => $f['state'] !== 'ok') ? 'diverged' : 'synced',
                'findings' => $findings,
            ];
        }

        $known = $allNames->map(fn (string $name) => strtolower($name))->flip();
        $missingHere = [];

        foreach ($peers as $peer) {
            foreach ($peer['zones'] as $lowerName => $other) {
                if (! $known->has($lowerName)) {
                    $missingHere[] = [
                        'name' => $other->name,
                        'server' => $peer['name'],
                        'type' => $other->detected_type,
                    ];
                }
            }
        }

        usort($missingHere, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'peers' => array_map(fn (array $peer) => [
                'name' => $peer['name'],
                'collected_at' => $peer['collected_at'],
            ], $peers),
            'by_zone' => $byZone,
            'missing_here' => $missingHere,
        ];
    }

    /**
     * Um servidor só é comparável se tem relação com este: compartilha ao menos
     * uma zona descoberta ou uma zona gerenciada atribuída aos dois. Sem isso,
     * servidores de outro cliente/ambiente da mesma empresa virariam "divergentes".
     *
     * @param  array<int, array<string, mixed>>  $peers
     * @param  Collection<int, string>  $allNames
     * @return array<int, array<string, mixed>>
     */
    private function relatedPeers(DnsServer $server, array $peers, Collection $allNames): array
    {
        $known = $allNames->map(fn (string $name) => strtolower($name))->flip();

        $sharedAssignments = DB::table('dns_server_zone as mine')
            ->join('dns_server_zone as theirs', 'theirs.dns_zone_id', '=', 'mine.dns_zone_id')
            ->where('mine.dns_server_id', $server->id)
            ->where('theirs.dns_server_id', '!=', $server->id)
            ->pluck('theirs.dns_server_id')
            ->flip();

        return array_values(array_filter($peers, function (array $peer) use ($known, $sharedAssignments): bool {
            if ($sharedAssignments->has($peer['id'])) {
                return true;
            }

            foreach (array_keys($peer['zones']) as $lowerName) {
                if ($known->has($lowerName)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<int, array{id: int, name: string, collected_at: mixed, zones: array<string, DnsBindDiscoveredZone>}>
     */
    private function peerDiscoveries(DnsServer $server): array
    {
        $peers = [];

        $others = DnsServer::query()
            ->where('organization_id', $server->organization_id)
            ->where('id', '!=', $server->id)
            ->orderBy('name')
            ->get();

        foreach ($others as $other) {
            $operation = DnsBindOperation::query()
                ->where('dns_server_id', $other->id)
                ->where('action', 'discover_bind_zones')
                ->where('status', 'succeeded')
                ->latest('id')
                ->first();

            if (! $operation) {
                continue;
            }

            $peers[] = [
                'id' => $other->id,
                'name' => $other->name,
                'collected_at' => $operation->completed_at,
                'zones' => DnsBindDiscoveredZone::query()
                    ->where('dns_bind_operation_id', $operation->id)
                    ->get(['name', 'serial', 'detected_type'])
                    ->keyBy(fn (DnsBindDiscoveredZone $zone) => strtolower($zone->name))
                    ->all(),
            ];
        }

        return $peers;
    }
}
