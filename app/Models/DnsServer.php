<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsServer extends Model
{
    use HasFactory;

    public const ROLES = [
        'primary',
        'secondary',
        'standalone',
    ];

    public const ENVIRONMENTS = [
        'production',
        'staging',
        'laboratory',
    ];

    public const STATUSES = [
        'pending',
        'online',
        'warning',
        'offline',
        'maintenance',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'hostname',
        'ipv4_address',
        'ipv6_address',
        'role',
        'environment',
        'status',
        'enabled',
        'agent_uuid',
        'agent_version',
        'last_seen_at',
        'capabilities',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'capabilities' => 'array',
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
}
