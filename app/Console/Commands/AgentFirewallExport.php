<?php

namespace App\Console\Commands;

use App\Models\BlockedAgentSource;
use Illuminate\Console\Command;

class AgentFirewallExport extends Command
{
    protected $signature = 'dns-center:agent-firewall-export';

    protected $description = 'Exporta IPs bloqueados para o firewall do host';

    public function handle(): int
    {
        BlockedAgentSource::query()->whereNotNull('ip_address')->orderBy('ip_address')->pluck('ip_address')->each(fn (string $ip) => $this->line($ip));

        return self::SUCCESS;
    }
}
