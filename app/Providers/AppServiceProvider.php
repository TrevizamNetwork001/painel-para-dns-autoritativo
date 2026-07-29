<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationActivity;
use App\Support\TestingDatabaseGuard;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            TestingDatabaseGuard::assertSafe(
                (string) $this->app->environment(),
                (string) config('database.connections.pgsql.database'),
            );
        }

        Event::listen(
            Login::class,
            [RecordAuthenticationActivity::class, 'handleLogin']
        );

        Event::listen(
            Logout::class,
            [RecordAuthenticationActivity::class, 'handleLogout']
        );

        Event::listen(
            Failed::class,
            [RecordAuthenticationActivity::class, 'handleFailed']
        );
    }
}
