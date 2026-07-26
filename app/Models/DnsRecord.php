<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    public const TYPES = [
        'A',
        'AAAA',
        'CNAME',
        'MX',
        'TXT',
        'CAA',
        'NS',
        'PTR',
    ];

    protected $fillable = [
        'organization_id',
        'dns_zone_id',
        'name',
        'type',
        'ttl',
        'priority',
        'content',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }
}
