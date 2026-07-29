<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsNameserverProfile extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'is_default',
        'enabled',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
        );
    }

    public function zones(): HasMany
    {
        return $this->hasMany(
            DnsZone::class,
            'dns_nameserver_profile_id',
        );
    }

    public function identities(): BelongsToMany
    {
        return $this->belongsToMany(
            DnsNameserverIdentity::class,
            'dns_nameserver_profile_identity',
            'dns_nameserver_profile_id',
            'dns_nameserver_identity_id',
        )
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where(
            'organization_id',
            $organizationId,
        );
    }

    public function scopeEnabled(
        Builder $query,
    ): Builder {
        return $query->where(
            'enabled',
            true,
        );
    }

    public function scopeDefault(
        Builder $query,
    ): Builder {
        return $query->where(
            'is_default',
            true,
        );
    }
}
