<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsBindOperation extends Model
{
    public const ACTIONS = ['install_bind', 'configure_bind', 'discover_bind_zones', 'upgrade_agent', 'apply_zones', 'remove_legacy_zone_block'];

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
            'params' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }

    private static function ttlMinutesFor(string $action, string $status): int
    {
        return max(1, (int) match ($action) {
            'upgrade_agent' => $status === 'running'
                ? config('security.agent_upgrade.running_ttl_minutes', 20)
                : config('security.agent_upgrade.ttl_minutes', 10),
            default => config('security.agent_operations.ttl_minutes', 20),
        });
    }

    /**
     * Expire any operation of any action type that has been sitting in
     * 'authorized' (never collected by the agent) or 'running' (collected
     * but never reported a terminal status) for longer than its TTL. Called
     * on every agent poll (DnsBindRuntimeController::nextOperation) so a
     * server self-heals without manual database intervention.
     */
    public static function expireStaleOperations(
        ?int $serverId = null,
        ?int $agentId = null,
    ): int {
        $expired = 0;

        foreach (self::ACTIONS as $action) {
            $authorizedCutoff = now()->subMinutes(self::ttlMinutesFor($action, 'authorized'));
            $runningCutoff = now()->subMinutes(self::ttlMinutesFor($action, 'running'));

            $expired += self::query()
                ->where('action', $action)
                ->where(function ($query) use ($authorizedCutoff, $runningCutoff): void {
                    $query->where(function ($query) use ($authorizedCutoff): void {
                        $query->where('status', 'authorized')
                            ->where('authorized_at', '<=', $authorizedCutoff);
                    })->orWhere(function ($query) use ($runningCutoff): void {
                        $query->where('status', 'running')
                            ->where('started_at', '<=', $runningCutoff);
                    });
                })
                ->when($serverId !== null, fn ($query) => $query->where('dns_server_id', $serverId))
                ->when($agentId !== null, fn ($query) => $query->where('dns_agent_id', $agentId))
                ->update([
                    'status' => 'expired',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $expired;
    }
}
