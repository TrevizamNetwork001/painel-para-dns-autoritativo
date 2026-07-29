<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAudit extends Model
{
    protected $fillable = [
        'event',
        'organization_id',
        'user_id',
        'email_hash',
        'actor',
        'source',
        'ip_address',
        'user_agent',
        'result',
        'reason',
        'rate_limit_hit',
    ];

    protected function casts(): array
    {
        return [
            'rate_limit_hit' => 'boolean',
        ];
    }
}
