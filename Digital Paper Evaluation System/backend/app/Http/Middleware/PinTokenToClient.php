<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bearer token visible in localStorage is, by definition, usable by
 * anyone who copies the exact string — no backend check can change that.
 * What this *does* stop: a copied token being replayed from a different
 * network or browser than the one it was issued to. Runs after auth:sanctum
 * so $request->user() is already resolved.
 *
 * Deliberately rejects rather than revokes on mismatch — an IP change can
 * be entirely innocent (WiFi to mobile data, a VPN, a flaky ISP), so this
 * doesn't punish the legitimate token for one bad request. The request is
 * just refused; the token keeps working again once used from its original
 * context.
 */
class PinTokenToClient
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return $next($request);
        }

        if ($token->ip_address && $token->ip_address !== $request->ip()) {
            Log::warning('Rejected request: token used from a different IP than it was issued to.', [
                'request_id' => $request->attributes->get('request_id'),
                'token_id' => $token->id,
                'issued_ip' => $token->ip_address,
                'request_ip' => $request->ip(),
            ]);

            return $this->unauthorized('This session is not valid from your current network.');
        }

        $currentAgent = substr((string) $request->userAgent(), 0, 255);

        if ($token->user_agent && $token->user_agent !== $currentAgent) {
            Log::warning('Rejected request: token used from a different browser than it was issued to.', [
                'request_id' => $request->attributes->get('request_id'),
                'token_id' => $token->id,
            ]);

            return $this->unauthorized('This session is not valid from your current browser.');
        }

        return $next($request);
    }
}
