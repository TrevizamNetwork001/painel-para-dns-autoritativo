<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->must_change_password) {
            return $next($request);
        }

        if (
            $user->temporary_password_expires_at
            && $user->temporary_password_expires_at->isPast()
        ) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'A senha temporária expirou. Solicite uma nova senha ao administrador.',
                ]);
        }

        return redirect()->route('password.change');
    }
}
