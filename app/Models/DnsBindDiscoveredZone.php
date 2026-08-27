<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DnsBindDiscoveredZone extends Model
{
    public const DETECTED_TYPES = ['primary', 'secondary'];

    public const COMPARISON_STATES = [
        'new',
        'exists',
        'conflict',
        'secondary_external',
        'not_supported',
        'imported',
    ];

    public const VALIDATION_STATUSES = ['ok', 'warning', 'error'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dynamic' => 'boolean',
            'secure' => 'boolean',
            'file_mtime' => 'immutable_datetime',
            'unsupported_record_types' => 'array',
            'soa' => 'array',
            'records' => 'array',
            'warnings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DnsServer::class, 'dns_server_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(DnsBindOperation::class, 'dns_bind_operation_id');
    }

    public function importedZone(): HasOne
    {
        return $this->hasOne(DnsZone::class, 'import_source_id');
    }
}
