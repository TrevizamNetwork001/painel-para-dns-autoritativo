<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\SecurityAuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecordAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
            'last_login_user_agent' => Str::limit(
                (string) request()->userAgent(),
                1000,
                ''
            ),
        ])->saveQuietly();

        Log::notice('auth.login.succeeded', [
            'user_id' => $event->user->getKey(),
            'organization_id' => $event->user->current_organization_id,
            'ip_address' => request()->ip(),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill([
            'last_logout_at' => now(),
        ])->saveQuietly();

        Log::notice('auth.logout', [
            'user_id' => $event->user->getKey(),
            'organization_id' => $event->user->current_organization_id,
            'ip_address' => request()->ip(),
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $email = mb_strtolower(
            trim((string) ($event->credentials['email'] ?? ''))
        );

        Log::warning('auth.login.failed', [
            'user_id' => $event->user?->getAuthIdentifier(),
            'email_hash' => $email !== ''
                ? hash('sha256', $email)
                : null,
            'ip_address' => request()->ip(),
        ]);

        $user = $event->user instanceof User ? $event->user : null;
        $this->recordFailure($email, $user, false);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = mb_strtolower(trim(
            (string) $event->request->input('email'),
        ));
        $user = $email === ''
            ? null
            : User::query()->where('email', $email)->first();

        $this->recordFailure($email, $user, true);
    }

    private function recordFailure(
        string $email,
        ?User $user,
        bool $rateLimitHit,
    ): void {
        $ip = (string) request()->ip();

        SecurityAuditLogger::record(
            event: 'auth.login_failed',
            user: $user,
            result: 'failed',
            actor: 'guest',
            source: 'web',
            ipAddress: $ip,
            userAgent: request()->userAgent(),
            organizationId: $user?->current_organization_id,
            reason: 'invalid_credentials_or_rate_limited',
            rateLimitHit: $rateLimitHit,
            deduplicationKey: implode('|', [
                'auth.login_failed',
                $ip,
                $email === '' ? '-' : hash('sha256', $email),
                $rateLimitHit ? 'limited' : 'failed',
            ]),
        );
    }
}
