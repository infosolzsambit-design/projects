<?php

namespace App\Http\Middleware;

use App\Models\GeneralSetting;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the public APIs the main database calls (no user login, no X-API-KEY):
 * the request must send the "Security-Code" header matching General
 * Settings' "API Security Code" (sync_base_url_security_code) — the same
 * shared code this app already sends to the main database (MainDbApiService).
 * With no code set in General Settings every request is refused.
 */
class SecurityCodeAuth
{
    use ApiResponse;

    public const HEADER = 'Security-Code';

    public const SETTING = 'sync_base_url_security_code';

    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) GeneralSetting::where('field_name', self::SETTING)->where('status', true)->value('value');
        $provided = (string) $request->header(self::HEADER, '');

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            Log::warning('Rejected public API request with missing/invalid security code.', [
                'request_id' => $request->attributes->get('request_id'),
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorized('Invalid or missing security code.');
        }

        return $next($request);
    }
}
