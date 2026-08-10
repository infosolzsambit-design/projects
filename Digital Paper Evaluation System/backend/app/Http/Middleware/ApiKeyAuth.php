<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires every API request to present a valid X-API-KEY header, as a
 * first line of defense in front of Sanctum's per-user authentication.
 */
class ApiKeyAuth
{
    use ApiResponse;

    public const HEADER = 'X-API-KEY';

    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('app.api_key');
        $providedKey = (string) $request->header(self::HEADER, '');

        if ($configuredKey === '' || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            Log::warning('Rejected API request with missing/invalid API key.', [
                'request_id' => $request->attributes->get('request_id'),
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorized('Invalid or missing API key.');
        }

        return $next($request);
    }
}
