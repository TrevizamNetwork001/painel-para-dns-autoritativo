<?php

namespace App\Providers;

use App\Actions\ResetUserPassword;
use App\Actions\UpdateUserPassword;
use App\Actions\UpdateUserProfileInformation;
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

        Fortify::resetUserPasswordsUsing(
            ResetUserPassword::class
        );

        Fortify::loginView(
            fn () => view('auth.login')
        );

        Fortify::requestPasswordResetLinkView(
            fn () => view('auth.forgot-password')
        );

        Fortify::resetPasswordView(
            fn (Request $request) => view('auth.reset-password', [
                'request' => $request,
            ])
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
    }
}
