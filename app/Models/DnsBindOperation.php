<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsBindOperation extends Model
{
    public const ACTIONS = ['install_bind', 'configure_bind'];

    public const STATUSES = [
        'planned',
        'authorized',
        'running',
        'succeeded',
        'failed',
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
}
