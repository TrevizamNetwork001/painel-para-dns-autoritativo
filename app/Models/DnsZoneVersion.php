<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsZoneVersion extends Model
{
    protected $fillable = [
        'organization_id',
        'dns_zone_id',
        'created_by',
        'version',
        'serial',
        'reason',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'serial' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }
}
