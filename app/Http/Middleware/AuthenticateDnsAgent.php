<?php

namespace App\Http\Middleware;

use App\Models\BlockedAgentSource;
use App\Models\DnsAgent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
                $request,
                'missing_agent_token',
                'Token do agente não informado.',
            );
        }

        $tokenHash = hash('sha256', $plainToken);
        $blocked = BlockedAgentSource::query()->where('token_hash', $tokenHash)->first();
        if ($blocked) {
            $blocked->increment('attempt_count');
            $blocked->forceFill(['last_attempt_at' => now()])->save();

            return $this->unauthorized($request, 'blocked_agent_token', 'Credencial bloqueada.', 403);
        }

        $agent = DnsAgent::query()
            ->where(
                'token_hash',
                $tokenHash,
            )
            ->first();

        if (! $agent) {
            return $this->unauthorized(
                $request,
                'invalid_agent_token',
                'Token do agente inválido.',
            );
        }

        if ($agent->revoked_at !== null) {
            return $this->unauthorized(
                $request,
                'revoked_agent_token',
                'A credencial do agente foi revogada.',
                403,
            );
        }

        // The proxy presents all agents with the same IP. Count requests by
        // authenticated agent so one noisy client cannot block the others.
        $key = 'dns-agent-api:'.$agent->id;
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return $this->tooManyRequests($key);
        }
        RateLimiter::hit($key, 60);

        $request->attributes->set('dns_agent', $agent);

        return $next($request);
    }

    private function unauthorized(
        Request $request,
        string $error,
        string $message,
        int $status = 401,
    ): JsonResponse {
        // Invalid credentials still share a bounded IP limit before any
        // authenticated agent key exists.
        $key = 'dns-agent-auth:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return $this->tooManyRequests($key);
        }
        RateLimiter::hit($key, 60);

        return response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ], $status);
    }

    private function tooManyRequests(string $key): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'rate_limited',
            'message' => 'Muitas requisições. Aguarde e tente novamente.',
        ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
    }
}
