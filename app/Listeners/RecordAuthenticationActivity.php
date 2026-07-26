<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
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
    }
}
