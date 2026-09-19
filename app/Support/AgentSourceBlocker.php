<?php

namespace App\Support;

use App\Models\BlockedAgentSource;
use App\Models\DnsAgent;
use App\Models\DnsServer;

class AgentSourceBlocker
{
    public function blockToken(DnsAgent $agent, string $reason = 'credential_revoked'): void
    {
        BlockedAgentSource::query()->updateOrCreate(['token_hash' => $agent->token_hash], ['reason' => $reason]);
    }

    public function blockDeletedOrganization(int $organizationId): void
    {
        DnsAgent::query()->where('organization_id', $organizationId)->each(fn (DnsAgent $agent) => $this->blockToken($agent, 'organization_deleted'));
        $addresses = DnsServer::query()->where('organization_id', $organizationId)->get(['ipv4_address', 'ipv6_address'])
            ->flatMap(fn (DnsServer $server): array => [$server->ipv4_address, $server->ipv6_address])
            ->filter(fn ($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false)->unique();
        foreach ($addresses as $ip) {
            BlockedAgentSource::query()->updateOrCreate(['ip_address' => $ip], ['reason' => 'organization_deleted', 'firewall_synced_at' => null]);
        }
    }
}
