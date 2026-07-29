<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(
            UpdateUserProfileInformation::class
        );

        Fortify::updateUserPasswordsUsing(
            UpdateUserPassword::class
        );

        Fortify::loginView(
            fn () => view('auth.login')
        );

        Fortify::twoFactorChallengeView(
            fn () => view('auth.two-factor-challenge')
        );

        RateLimiter::for('login', function (Request $request): Limit {
            $identifier = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username()))
                .'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($identifier);
        });

        RateLimiter::for(
            'two-factor',
            fn (Request $request): Limit => Limit::perMinute(5)
                ->by((string) $request->session()->get('login.id'))
        );

        RateLimiter::for('password-reset-request', function (
            Request $request
        ): array {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute((int) env('PASSWORD_RESET_IP_LIMIT', 10))
                    ->by('password-reset-request-ip|'.$ip),
                Limit::perMinute((int) env('PASSWORD_RESET_EMAIL_IP_LIMIT', 3))
                    ->by('password-reset-request|'.$ip.'|'.$email),
            ];
        });

        RateLimiter::for(
            'password-reset-submit',
            fn (Request $request): Limit => Limit::perMinute(
                (int) env('PASSWORD_RESET_SUBMIT_IP_LIMIT', 10)
            )->by('password-reset-submit|'.$request->ip())
        );
    }
}
