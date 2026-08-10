<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a unique request ID to every request (reusing one supplied by the
 * client via X-Request-ID when present) for tracing across logs and audits.
 */
class RequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER);

        if (! is_string($requestId) || $requestId === '' || strlen($requestId) > 100) {
            $requestId = (string) str()->uuid();
        }

        $request->attributes->set('request_id', $requestId);

        app()->instance('request_id', $requestId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
