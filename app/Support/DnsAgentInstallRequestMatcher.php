<?php

namespace App\Support;

use App\Models\DnsServer;
use Illuminate\Database\Eloquent\Collection;

final class DnsAgentInstallRequestMatcher
{
    /** @return Collection<int, DnsServer> */
    public function candidates(string $hostname, ?string $ip = null): Collection
    {
        $hostname = mb_strtolower(rtrim($hostname, '.'));

        return DnsServer::query()
            ->where('enabled', true)
            ->whereDoesntHave(
                'agent',
                fn ($query) => $query->whereNull('revoked_at'),
            )
            ->where(function ($query) use ($hostname, $ip): void {
                $query->whereRaw('LOWER(hostname) = ?', [$hostname]);

                if (! str_contains($hostname, '.')) {
                    $query->orWhereRaw(
                        'LOWER(hostname) LIKE ?',
                        [$hostname.'.%'],
                    );
                }

                if ($ip) {
                    $query->orWhere('ipv4_address', $ip)
                        ->orWhere('ipv6_address', $ip);
                }
            })
            ->orderBy('hostname')
            ->get();
    }

    public function unique(string $hostname, ?string $ip = null): ?DnsServer
    {
        $candidates = $this->candidates($hostname, $ip)->take(2);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
