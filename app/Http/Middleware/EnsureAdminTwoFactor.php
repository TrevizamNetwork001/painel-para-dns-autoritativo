<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! config('security.admin_2fa.required')
            || ! $user
            || ! $user->isAdministrative()
            || $user->hasAdministrativeSecondFactor()
            || $request->routeIs([
                'security.two-factor.*',
                'password.*',
                'logout',
                'two-factor.*',
                'passkey.*',
            ])
        ) {
            return $next($request);
        }

        if (! Schema::hasColumn('users', 'admin_2fa_grace_expires_at')) {
            return $next($request);
        }

        if ($user->admin_2fa_grace_expires_at === null) {
            $user->forceFill([
                'admin_2fa_grace_expires_at' => now()->addDays(
                    max(0, (int) config('security.admin_2fa.grace_days')),
                ),
            ])->saveQuietly();
        }

        if (
            $user->admin_2fa_grace_expires_at?->isFuture()
            && $request->session()->get('admin_2fa_grace_acknowledged') === true
            && ! $this->isCriticalOperation($request)
        ) {
            return $next($request);
        }

        return redirect()
            ->route('security.two-factor.setup')
            ->with('warning', 'Configure um segundo fator para continuar.');
    }

    private function isCriticalOperation(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return false;
        }

        return $request->routeIs([
            'users.*',
            'servers.store',
            'servers.update',
            'servers.status',
            'servers.agent.install-requests.*',
            'servers.agent.enrollment-codes.*',
            'servers.agent.revoke',
            'servers.bind.*',
            'nameservers.*',
            'zones.publish',
            'tsig.*',
        ]);
    }
}
