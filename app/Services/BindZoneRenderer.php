<?php

namespace App\Services;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use InvalidArgumentException;

class BindZoneRenderer
{
    public function render(DnsZone $zone): string
    {
        $zone->loadMissing([
            'records' => fn ($query) => $query
                ->where('enabled', true)
                ->orderBy('type')
                ->orderBy('name'),
        ]);

        $lines = [
            '$ORIGIN '.$this->fqdn($zone->name),
            '$TTL '.$zone->default_ttl,
            '',
            '@ IN SOA '.$this->fqdn($zone->soa_mname).' '.$this->fqdn($zone->soa_rname).' (',
            '    '.$zone->serial.' ; serial',
            '    '.$zone->soa_refresh.' ; refresh',
            '    '.$zone->soa_retry.' ; retry',
            '    '.$zone->soa_expire.' ; expire',
            '    '.$zone->soa_minimum.' ; minimum',
            ')',
            '',
        ];

        foreach ($zone->records as $record) {
            $lines[] = $this->renderRecord($record, $zone);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function snapshot(DnsZone $zone): array
    {
        $zone->loadMissing(['records', 'servers']);

        return [
            'zone' => $zone->only([
                'name',
                'kind',
                'serial',
                'default_ttl',
                'soa_mname',
                'soa_rname',
                'soa_refresh',
                'soa_retry',
                'soa_expire',
                'soa_minimum',
                'status',
                'version',
            ]),
            'records' => $zone->records->map(
                fn (DnsRecord $record): array => $record->only([
                    'name',
                    'type',
                    'ttl',
                    'priority',
                    'content',
                    'enabled',
                ])
            )->values()->all(),
            'servers' => $zone->servers->map(
                fn ($server): array => [
                    'id' => $server->id,
                    'hostname' => $server->hostname,
                    'role' => $server->pivot->role,
                ]
            )->values()->all(),
            'zonefile' => $this->render($zone),
        ];
    }

    private function renderRecord(DnsRecord $record, DnsZone $zone): string
    {
        $name = $record->name === $zone->name ? '@' : $record->name;
        $ttl = $record->ttl ? ' '.$record->ttl : '';

        $content = match ($record->type) {
            'A' => $this->ip($record->content, FILTER_FLAG_IPV4),
            'AAAA' => $this->ip($record->content, FILTER_FLAG_IPV6),
            'CNAME', 'NS', 'PTR' => $this->fqdn($record->content),
            'MX' => ($record->priority ?? 10).' '.$this->fqdn($record->content),
            'CAA' => $this->caa($record->content),
            'TXT' => $this->quote($record->content),
            default => throw new InvalidArgumentException('Tipo DNS não suportado.'),
        };

        return sprintf('%s%s IN %s %s', $name, $ttl, $record->type, $content);
    }

    private function fqdn(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.').'.';
    }

    private function ip(string $value, int $flag): string
    {
        if (! filter_var($value, FILTER_VALIDATE_IP, $flag)) {
            throw new InvalidArgumentException('Endereço IP inválido.');
        }

        return $value;
    }

    private function caa(string $value): string
    {
        if (! preg_match('/^(\d+)\s+([a-z0-9-]+)\s+(.+)$/i', trim($value), $match)) {
            throw new InvalidArgumentException('CAA inválido.');
        }

        return $match[1].' '.$match[2].' '.$this->quote($match[3]);
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, "\\\"").'"';
    }
}
