<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAuthoritativeObservation extends Model
{
    public const STATUSES = [
        'synchronized',
        'awaiting_transfer',
        'transferring',
        'transfer_failed',
        'serial_mismatch',
        'expired',
        'primary_unreachable',
        'unknown',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_serial' => 'integer',
            'observed_serial' => 'integer',
            'sequence' => 'integer',
            'last_refresh_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_transfer_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'agent_observed_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }
}
