<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedAgentSource extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['attempt_count' => 'integer', 'last_attempt_at' => 'immutable_datetime', 'firewall_synced_at' => 'immutable_datetime'];
    }
}
