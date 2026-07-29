<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserPasswordResetter
{
    public function reset(
        User $user,
        string $password,
        bool $mustChange,
        string $event,
        string $actor,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        return DB::transaction(function () use (
            $user,
            $password,
            $mustChange,
            $event,
            $actor,
            $source,
            $ipAddress,
            $userAgent,
        ): User {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => $mustChange,
                'temporary_password_expires_at' => null,
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();

            DB::table(
                config(
                    'auth.passwords.'.config('auth.defaults.passwords').'.table',
                    'password_reset_tokens',
                )
            )->where('email', $user->getEmailForPasswordReset())->delete();

            SecurityAuditLogger::record(
                $event,
                $user,
                'SUCCESS',
                $actor,
                $source,
                $ipAddress,
                $userAgent,
            );

            return $user->refresh();
        }, 3);
    }
}
