<?php

namespace App\Services;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsZone;
use Illuminate\Support\Collection;

class DnsReversePtrSynchronizer
{
    public function recordNameFor(string $ip, string $zoneName): ?string
    {
        $network = $this->ipv4ZoneNetwork($zoneName);

        if ($network === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $ipOctets = array_map('intval', explode('.', $ip));

        if (count($ipOctets) !== 4) {
            return null;
        }

        for ($i = 0; $i < $network['labelCount']; $i++) {
            if ($ipOctets[$i] !== $network['octets'][$i]) {
                return null;
            }
        }

        return implode('.', array_reverse($ipOctets)).'.in-addr.arpa.';
    }

    /**
     * @param  Collection<int, DnsNameserverIdentity>  $identities
     * @return array{created: int, updated: int, unchanged: int, unmatched: int}
     */
    public function synchronize(DnsZone $zone, Collection $identities): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unmatched' => 0,
        ];

        foreach ($identities as $identity) {
            $ip = $identity->ipv4_address;
            $recordName = filled($ip) ? $this->recordNameFor($ip, $zone->name) : null;

            if ($recordName === null) {
                $summary['unmatched']++;

                continue;
            }

            $hostname = $identity->normalizedHostname().'.';

            $record = $zone->records()
                ->where('name', $recordName)
                ->where('type', 'PTR')
                ->first();

            if ($record === null) {
                $zone->records()->create([
                    'organization_id' => $zone->organization_id,
                    'name' => $recordName,
                    'type' => 'PTR',
                    'ttl' => null,
                    'priority' => null,
                    'content' => $hostname,
                    'enabled' => true,
                ]);
                $summary['created']++;
            } elseif ($record->content !== $hostname) {
                $record->forceFill(['content' => $hostname])->save();
                $summary['updated']++;
            } else {
                $summary['unchanged']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{octets: int[], labelCount: int}|null
     */
    private function ipv4ZoneNetwork(string $zoneName): ?array
    {
        $name = strtolower(rtrim(trim($zoneName), '.'));

        if (! str_ends_with($name, '.in-addr.arpa')) {
            return null;
        }

        $labels = explode('.', substr($name, 0, -strlen('.in-addr.arpa')));
        $labelCount = count($labels);

        if ($labelCount < 1 || $labelCount > 4) {
            return null;
        }

        foreach ($labels as $label) {
            if (! ctype_digit($label) || (int) $label > 255) {
                return null;
            }
        }

        return [
            'octets' => array_map('intval', array_reverse($labels)),
            'labelCount' => $labelCount,
        ];
    }
}
