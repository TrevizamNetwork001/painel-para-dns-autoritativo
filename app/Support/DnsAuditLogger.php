<?php

namespace App\Support;

use App\Models\DnsAuditLog;
use App\Models\User;

final class DnsAuditLogger
{
    public static function record(
        ?int $organizationId,
        ?User $user,
        string $action,
        ?string $domain = null,
        ?string $recordType = null,
        ?string $recordName = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        string $status = 'ok',
        ?string $message = null,
        ?string $ipAddress = null,
    ): DnsAuditLog {
        return DnsAuditLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user?->getKey(),
            'actor' => $user?->name ?: ($user?->email ?: 'desconhecido'),
            'action' => $action,
            'domain' => $domain,
            'record_type' => $recordType,
            'record_name' => $recordName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'status' => $status,
            'message' => $message,
            'ip_address' => $ipAddress ?? request()->ip(),
        ]);
    }
}
