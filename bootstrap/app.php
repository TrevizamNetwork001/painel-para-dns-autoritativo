<?php

use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\EnsureOrganizationRole;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ProtectLastAdminSecondFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->web(append: [
            SecurityHeaders::class,
            ProtectLastAdminSecondFactor::class,
            EnsureAdminTwoFactor::class,
        ]);

        $middleware->alias([
            'organization' => EnsureOrganizationContext::class,
            'organization.role' => EnsureOrganizationRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
