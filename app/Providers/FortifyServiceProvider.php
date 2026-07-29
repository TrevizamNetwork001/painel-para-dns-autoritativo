<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Auth\Events\Lockout;
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

        Fortify::confirmPasswordView(
            fn () => view('auth.confirm-password')
        );

        RateLimiter::for('login', function (Request $request): array {
            $emailIpIdentifier = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username()))
                .'|'.$request->ip()
            );

            $decaySeconds = max(
                1,
                (int) config('security.login.decay_seconds'),
            );
            $response = function (
                Request $request,
                array $headers,
            ) {
                event(new Lockout($request));

                return back()
                    ->withErrors([
                        Fortify::username() => trans('auth.failed'),
                    ])
                    ->withInput($request->only(Fortify::username()))
                    ->withHeaders($headers);
            };

            return [
                Limit::perSecond(
                    max(1, (int) config('security.login.global_ip_limit')),
                    $decaySeconds,
                )->by('login-global-ip|'.$request->ip())
                    ->response($response),
                Limit::perSecond(
                    max(1, (int) config('security.login.email_ip_limit')),
                    $decaySeconds,
                )->by('login-email-ip|'.$emailIpIdentifier)
                    ->response($response),
            ];
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
