<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.headers.enabled')) {
            return $next($request);
        }

        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Referrer-Policy',
            (string) config('security.headers.referrer_policy'),
        );
        $response->headers->set(
            'Permissions-Policy',
            (string) config('security.headers.permissions_policy'),
        );
        $response->headers->set('X-Frame-Options', 'DENY');

        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.headers.hsts_max_age')
                    .'; includeSubDomains',
            );
        }

        if ($this->shouldApplyCsp($response)) {
            $scriptSources = ["'self'", "'nonce-{$nonce}'"];
            $styleSources = ["'self'"];
            $connectSources = ["'self'"];

            if (app()->environment('local')) {
                $scriptSources[] = 'http://localhost:5173';
                $scriptSources[] = 'http://127.0.0.1:5173';
                $styleSources[] = 'http://localhost:5173';
                $styleSources[] = 'http://127.0.0.1:5173';
                $connectSources = [
                    ...$connectSources,
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                    'ws://localhost:5173',
                    'ws://127.0.0.1:5173',
                ];
            }

            $directives = [
                "default-src 'self'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                'script-src '.implode(' ', $scriptSources),
                'style-src '.implode(' ', $styleSources),
                "img-src 'self' data:",
                "font-src 'self'",
                'connect-src '.implode(' ', $connectSources),
            ];

            if ($request->isSecure() && app()->environment('production')) {
                $directives[] = 'upgrade-insecure-requests';
            }

            $response->headers->set(
                'Content-Security-Policy',
                implode('; ', $directives),
            );
        }

        return $response;
    }

    private function shouldApplyCsp(Response $response): bool
    {
        if ($response->headers->has('Content-Disposition')) {
            return false;
        }

        $contentType = strtolower(
            (string) $response->headers->get('Content-Type'),
        );

        return $contentType === ''
            || str_contains($contentType, 'text/html');
    }
}
