<?php

namespace App\Support;

use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
        ?int $organizationId = null,
        ?string $reason = null,
        bool $rateLimitHit = false,
        ?string $deduplicationKey = null,
    ): SecurityAudit {
        if ($deduplicationKey !== null) {
            $added = Cache::add(
                'security-audit:'.hash('sha256', $deduplicationKey),
                true,
                max(
                    1,
                    (int) config('security.login.audit_dedup_seconds'),
                ),
            );

            if (! $added) {
                return new SecurityAudit;
            }
        }

        $attributes = [
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
        ];

        if (Schema::hasColumn('security_audits', 'organization_id')) {
            $attributes += [
                'organization_id' => $organizationId,
                'reason' => $reason,
                'rate_limit_hit' => $rateLimitHit,
            ];
        }

        return SecurityAudit::query()->create($attributes);
    }
}
