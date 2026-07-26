<?php

namespace App\Http\Middleware;

use App\Models\DnsAgent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDnsAgent
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $plainToken = $request->bearerToken();

        if (! is_string($plainToken) || $plainToken === '') {
            return $this->unauthorized(
                'missing_agent_token',
                'Token do agente não informado.',
            );
        }

        $agent = DnsAgent::query()
            ->where(
                'token_hash',
                hash('sha256', $plainToken),
            )
            ->first();

        if (! $agent) {
            return $this->unauthorized(
                'invalid_agent_token',
                'Token do agente inválido.',
            );
        }

        if ($agent->revoked_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'revoked_agent_token',
                'message' => 'A credencial do agente foi revogada.',
            ], 403);
        }

        $request->attributes->set('dns_agent', $agent);

        return $next($request);
    }

    private function unauthorized(
        string $error,
        string $message,
    ): JsonResponse {
        return response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ], 401);
    }
}
