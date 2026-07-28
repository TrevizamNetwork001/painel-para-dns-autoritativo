<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DnsNameserverIdentity extends Model
{
    protected $fillable = [
        'organization_id',
        'dns_server_id',
        'name',
        'hostname',
        'ipv4_address',
        'ipv6_address',
        'enabled',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(
            DnsServer::class,
            'dns_server_id',
        );
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(
            DnsNameserverProfile::class,
            'dns_nameserver_profile_identity',
            'dns_nameserver_identity_id',
            'dns_nameserver_profile_id',
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

    public function normalizedHostname(): string
    {
        return strtolower(
            rtrim(
                trim($this->hostname),
                '.',
            ),
        );
    }

    public function hasAddress(): bool
    {
        return filled($this->ipv4_address)
            || filled($this->ipv6_address);
    }
}
