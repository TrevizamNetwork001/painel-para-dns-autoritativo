<?php

namespace App\Console\Commands;

use App\Models\BlockedAgentSource;
use Illuminate\Console\Command;

class AgentFirewallConfirm extends Command
{
    protected $signature = 'dns-center:agent-firewall-confirm';

    protected $description = 'Confirma a aplicação dos IPs no firewall';

    public function handle(): int
    {
        BlockedAgentSource::query()->whereNotNull('ip_address')->update(['firewall_synced_at' => now()]);

        return self::SUCCESS;
    }
}
