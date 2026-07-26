<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    public const KINDS = ['primary', 'secondary'];
    public const STATUSES = ['draft', 'ready', 'published', 'disabled'];

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'serial',
        'default_ttl',
        'soa_mname',
        'soa_rname',
        'soa_refresh',
        'soa_retry',
        'soa_expire',
        'soa_minimum',
        'status',
        'version',
        'enabled',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'serial' => 'integer',
            'default_ttl' => 'integer',
            'soa_refresh' => 'integer',
            'soa_retry' => 'integer',
            'soa_expire' => 'integer',
            'soa_minimum' => 'integer',
            'version' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DnsZoneVersion::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(DnsServer::class, 'dns_server_zone')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where('organization_id', $organizationId);
    }
}
