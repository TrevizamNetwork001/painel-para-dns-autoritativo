<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsServer extends Model
{
    use HasFactory;

    public const ROLES = [
        'primary',
        'secondary',
    ];

    public const ENVIRONMENTS = [
        'production',
        'staging',
        'development',
    ];

    public const STATUSES = [
        'pending',
        'online',
        'warning',
        'offline',
        'maintenance',
    ];

    public const AGENT_STATUSES = [
        'not_installed',
        'pending',
        'online',
        'offline',
        'blocked',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'hostname',
        'ipv4_address',
        'ipv6_address',
        'role',
        'environment',
        'operating_system',
        'operating_system_version',
        'bind_version',
        'status',
        'enabled',
        'agent_uuid',
        'agent_version',
        'agent_status',
        'agent_fingerprint',
        'agent_registered_at',
        'last_seen_at',
        'capabilities',
        'inventory',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'agent_registered_at' => 'immutable_datetime',
            'capabilities' => 'array',
            'inventory' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function hasAgentContact(): bool
    {
        return $this->last_seen_at !== null;
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(
            DnsAgentEnrollment::class,
            'dns_server_id',
        );
    }


    public function agent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(
            \App\Models\DnsAgent::class,
            'dns_server_id',
        );
    }

    public function agentEnrollments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(
            \App\Models\DnsAgentEnrollment::class,
            'dns_server_id',
        );
    }

}
