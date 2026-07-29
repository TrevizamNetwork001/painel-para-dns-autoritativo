<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAudit extends Model
{
    protected $fillable = [
        'event',
        'user_id',
        'email_hash',
        'actor',
        'source',
        'ip_address',
        'user_agent',
        'result',
    ];
}
