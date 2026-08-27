<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAgentInstallRequest extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'request_token_hash',
        'agent_token',
    ];

    protected function casts(): array
    {
        return [
            'agent_token' => 'encrypted',
            'review_warnings' => 'array',
            'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function enrollmentCode(): BelongsTo
    {
        return $this->belongsTo(DnsAgentEnrollmentCode::class);
    }
}
