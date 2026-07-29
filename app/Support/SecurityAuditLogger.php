<?php

namespace App\Support;

use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Support\Str;

final class SecurityAuditLogger
{
    public static function record(
        string $event,
        ?User $user,
        string $result,
        string $actor,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SecurityAudit {
        return SecurityAudit::query()->create([
            'event' => $event,
            'user_id' => $user?->getKey(),
            'email_hash' => $user
                ? hash('sha256', mb_strtolower(trim($user->email)))
                : null,
            'actor' => $actor,
            'source' => $source,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null
                ? null
                : Str::limit(
                    preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent) ?? '',
                    500,
                    '',
                ),
            'result' => $result,
        ]);
    }
}
