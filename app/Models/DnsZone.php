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
        'dns_nameserver_profile_id',
        'dns_tsig_key_id',
        'name',
        'client',
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
        'ptr_name_template',
        'origin',
        'imported_at',
        'imported_by',
        'import_source_id',
        'import_batch_id',
    ];

    public const ORIGINS = ['managed', 'bind_import'];

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
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function nameserverProfile(): BelongsTo
    {
        return $this->belongsTo(
            DnsNameserverProfile::class,
            'dns_nameserver_profile_id',
        );
    }

    public function tsigKey(): BelongsTo
    {
        return $this->belongsTo(DnsTsigKey::class, 'dns_tsig_key_id');
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

    public function authoritativeObservations(): HasMany
    {
        return $this->hasMany(DnsAuthoritativeObservation::class, 'dns_zone_id');
    }

    public function importSource(): BelongsTo
    {
        return $this->belongsTo(DnsBindDiscoveredZone::class, 'import_source_id');
    }

    public function isImportedUnmanaged(): bool
    {
        return $this->origin === 'bind_import';
    }

    public function isReverseZone(): bool
    {
        $name = strtolower(rtrim(trim($this->name), '.'));

        return $name === 'in-addr.arpa' || str_ends_with($name, '.in-addr.arpa');
    }

    public function isIpv6ReverseZone(): bool
    {
        $name = strtolower(rtrim(trim($this->name), '.'));

        return $name === 'ip6.arpa' || str_ends_with($name, '.ip6.arpa');
    }

    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where('organization_id', $organizationId);
    }
}
