<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach a per-request correlation ID (FR-OPS-2, NFR-OBS-2).
 *
 * Reuses an inbound X-Request-Id when it is a safe identifier, otherwise
 * generates a UUID. The ID is shared with every log channel and echoed back
 * in the X-Request-Id response header.
 */
class AddCorrelationId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolveCorrelationId($request);

        $request->attributes->set('correlation_id', $correlationId);

        Log::shareContext(['correlation_id' => $correlationId]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $inbound = $request->header(self::HEADER);

        if (is_string($inbound) && preg_match('/\A[A-Za-z0-9._-]{8,128}\z/', $inbound) === 1) {
            return $inbound;
        }

        return (string) Str::uuid();
    }
}
