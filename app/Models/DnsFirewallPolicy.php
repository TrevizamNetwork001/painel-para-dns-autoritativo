<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsFirewallPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'administrative_acls' => 'array',
            'administrative_ports' => 'array',
            'public_ports' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }
}
