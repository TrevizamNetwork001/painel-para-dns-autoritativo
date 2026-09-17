<?php

namespace App\Services;

use App\Models\DnsServer;
use App\Models\DnsZone;

class DnsBindConfigConflicts
{
    /**
     * Cross-reference the legacy zone blocks the agent found outside the
     * DNS Center managed include (readiness's ``legacy_zone_blocks``)
     * against the zones this server actually manages here.
     *
     * A block whose name doesn't match anything DNS Center manages on
     * this server is somebody else's business (out of scope for v1) and
     * is left out.
     *
     * @return array<int, array{
     *     zone_id: int,
     *     zone_name: string,
     *     source_file: string,
     *     start_line: int,
     *     end_line: int,
     *     declared_type: string|null,
     *     snippet: string|null,
     *     hash: string,
     * }>
     */
    public function forServer(DnsServer $server): array
    {
        $blocks = data_get($server->bind_readiness, 'legacy_zone_blocks.blocks', []);

        if (! is_array($blocks) || $blocks === []) {
            return [];
        }

        $managedZones = DnsZone::query()
            ->forOrganization($server->organization_id)
            ->whereHas(
                'servers',
                fn ($query) => $query->where('dns_servers.id', $server->id),
            )
            ->get(['id', 'name'])
            ->keyBy(fn (DnsZone $zone) => $this->normalize($zone->name));

        $conflicts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $zone = $managedZones->get($this->normalize((string) ($block['name'] ?? '')));

            if ($zone === null) {
                continue;
            }

            $conflicts[] = [
                'zone_id' => $zone->id,
                'zone_name' => $zone->name,
                'source_file' => (string) ($block['source_file'] ?? ''),
                'start_line' => (int) ($block['start_line'] ?? 0),
                'end_line' => (int) ($block['end_line'] ?? 0),
                'declared_type' => $block['declared_type'] ?? null,
                'snippet' => $block['snippet'] ?? null,
                'hash' => (string) ($block['hash'] ?? ''),
            ];
        }

        return $conflicts;
    }

    private function normalize(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }
}
