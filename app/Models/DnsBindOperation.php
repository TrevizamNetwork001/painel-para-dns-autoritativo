<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsBindOperation extends Model
{
    public const ACTIONS = ['install_bind', 'configure_bind', 'discover_bind_zones', 'upgrade_agent'];

    public const STATUSES = [
        'planned',
        'authorized',
        'running',
        'succeeded',
        'failed',
        'expired',
    ];

    protected $guarded = [];

    protected $hidden = ['authorization_nonce'];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'result' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }

    public static function expireStaleAgentUpgrades(
        ?int $serverId = null,
        ?int $agentId = null,
    ): int {
        $ttlMinutes = max(
            1,
            (int) config('security.agent_upgrade.ttl_minutes', 10),
        );

        return self::query()
            ->where('action', 'upgrade_agent')
            ->where('status', 'authorized')
            ->where('authorized_at', '<=', now()->subMinutes($ttlMinutes))
            ->when($serverId !== null, fn ($query) => $query->where('dns_server_id', $serverId))
            ->when($agentId !== null, fn ($query) => $query->where('dns_agent_id', $agentId))
            ->update([
                'status' => 'expired',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
