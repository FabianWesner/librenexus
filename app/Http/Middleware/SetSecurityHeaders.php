<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the SEC-HEADERS response headers on every response.
 *
 * The CSP keeps 'unsafe-inline' and 'unsafe-eval' for scripts because
 * Livewire and Alpine evaluate inline expressions and Flux injects inline
 * style/script fragments; everything else is restricted to same-origin.
 */
class SetSecurityHeaders
{
    private const string CONTENT_SECURITY_POLICY = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."frame-ancestors 'none'; "
        ."form-action 'self'";

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        return $response;
    }
}
