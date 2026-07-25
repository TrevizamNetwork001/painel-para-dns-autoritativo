<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$allowedRoles
    ): Response {
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

        $role = $user->roleForOrganization(
            $user->current_organization_id
        );

        if (! in_array($role, $allowedRoles, true)) {
            abort(403, 'Você não possui permissão para esta operação.');
        }

        return $next($request);
    }
}
