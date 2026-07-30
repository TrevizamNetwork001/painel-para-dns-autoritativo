<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsTsigKey extends Model
{
    public const ALGORITHMS = ['hmac-sha256', 'hmac-sha384', 'hmac-sha512'];

    protected $fillable = [
        'organization_id',
        'name',
        'algorithm',
        'secret',
        'enabled',
        'created_by',
        'rotated_by',
        'rotated_at',
        'disabled_at',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'rotated_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(DnsZone::class, 'dns_tsig_key_id');
    }
}
