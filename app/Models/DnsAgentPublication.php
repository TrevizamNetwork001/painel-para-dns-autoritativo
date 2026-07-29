<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAgentPublication extends Model
{
    public const STATUSES = [
        'pending',
        'downloaded',
        'applying',
        'applied',
        'failed',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installed_version' => 'integer',
            'downloaded_at' => 'immutable_datetime',
            'last_apply_at' => 'immutable_datetime',
            'agent_reported_at' => 'immutable_datetime',
        ];
    }

    public function zoneVersion(): BelongsTo
    {
        return $this->belongsTo(DnsZoneVersion::class, 'dns_zone_version_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(DnsAgent::class, 'dns_agent_id');
    }
}
