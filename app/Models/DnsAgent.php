<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAgent extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(
            DnsServer::class,
            'dns_server_id',
        );
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
