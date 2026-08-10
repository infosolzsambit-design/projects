<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard hardening headers on every response. This is a JSON-only API (no
 * HTML ever gets served — see routes/web.php), so a Content-Security-Policy
 * isn't meaningful here; that belongs on whatever serves the frontend's HTML
 * page, which doesn't have a production home yet (still Vite's dev server).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        // Every response here is per-user/authenticated JSON — never worth
        // letting a browser or intermediate proxy cache it.
        $response->headers->set('Cache-Control', 'no-store, private');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
