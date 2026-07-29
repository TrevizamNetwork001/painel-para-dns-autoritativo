<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectLastAdminSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdministrative()) {
            return $next($request);
        }

        if (
            $request->routeIs('two-factor.disable')
            && $user->hasEnabledTwoFactorAuthentication()
            && ! $user->hasPasskeysEnabled()
        ) {
            return back()->withErrors([
                'two_factor' => 'Cadastre outra passkey antes de remover o último fator.',
            ]);
        }

        if (
            $request->routeIs('passkey.destroy')
            && ! $user->hasEnabledTwoFactorAuthentication()
            && $user->passkeys()->count() <= 1
        ) {
            return back()->withErrors([
                'passkey' => 'Ative TOTP ou outra passkey antes de remover o último fator.',
            ]);
        }

        return $next($request);
    }
}
