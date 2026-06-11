<?php

namespace App\Http\Middleware;

use App\Data\CurrentTenant;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the tenant for public, login-free booking routes from the
 * {tenant} slug (FR-BOOK-1, ARCH-ROUTING-3). An unknown slug is a plain
 * 404; a known one sets the request-scoped CurrentTenant so the
 * fail-closed tenant scope applies to everything the page reads.
 */
class ResolvePublicTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        $team = is_string($slug)
            ? Team::query()->where('slug', $slug)->first()
            : null;

        abort_if($team === null, 404);

        app(CurrentTenant::class)->set($team);

        $request->attributes->set('publicTenant', $team);

        return $next($request);
    }
}
