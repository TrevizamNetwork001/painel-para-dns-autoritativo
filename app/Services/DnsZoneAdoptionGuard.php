<?php

namespace App\Services;

use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsZone;

final class DnsZoneAdoptionGuard
{
    public function __construct(private readonly DnsZoneValidator $validator) {}

    public function inspect(DnsZone $zone): array
    {
        $issues = [];
        $validation = $this->validator->validate($zone);

        foreach ($validation['errors'] as $error) {
            $issues[] = $error;
        }

        $source = $zone->importSource;
        if (! $source) {
            $issues[] = 'A origem da importação não está mais disponível.';

            return ['ok' => false, 'issues' => $issues, 'latest' => null];
        }

        $latest = DnsBindDiscoveredZone::query()
            ->where('organization_id', $zone->organization_id)
            ->where('dns_server_id', $source->dns_server_id)
            ->whereRaw('lower(name) = ?', [strtolower($zone->name)])
            ->latest('id')
            ->first();

        if (! $latest || $latest->created_at->lt(now()->subDay())) {
            $issues[] = 'Execute uma descoberta nova no servidor de origem antes de adotar.';
        } elseif ($latest->validation_status !== 'ok') {
            $issues[] = 'A descoberta mais recente da zona não foi validada com sucesso.';
        } elseif ($this->snapshotHash($source) !== $this->snapshotHash($latest)) {
            $issues[] = 'A zona mudou no BIND desde a importação. Importe/revise novamente antes de adotar.';
        }

        return ['ok' => $issues === [], 'issues' => array_values(array_unique($issues)), 'latest' => $latest];
    }

    private function snapshotHash(DnsBindDiscoveredZone $zone): string
    {
        $records = collect($zone->records ?? [])->map(function ($record): array {
            $record = is_array($record) ? $record : [];

            return [
                'name' => strtolower(rtrim(trim((string) ($record['name'] ?? '')), '.')),
                'type' => strtoupper(trim((string) ($record['type'] ?? ''))),
                'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
                'content' => preg_replace('/\s+/', ' ', trim((string) ($record['content'] ?? ''))),
            ];
        })->sortBy(fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR))->values()->all();

        return hash('sha256', json_encode([
            'serial' => $zone->serial,
            'soa' => $zone->soa,
            'records' => $records,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
