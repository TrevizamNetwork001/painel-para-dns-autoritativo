<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->is_platform_admin) {
            return $next($request);
        }

        if (! $user->current_organization_id) {
            abort(403, 'Nenhuma empresa ativa foi selecionada.');
        }

        if (! $user->belongsToOrganization($user->current_organization_id)) {
            abort(403, 'O usuário não possui acesso à empresa selecionada.');
        }

        if ($user->currentOrganization?->status !== 'active') {
            abort(403, 'Esta empresa está desativada.');
        }

        return $next($request);
    }
}
