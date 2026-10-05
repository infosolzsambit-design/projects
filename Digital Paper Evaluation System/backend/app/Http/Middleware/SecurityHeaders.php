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
        // storage/* (question paper / answer sheet PDFs, branding images) is
        // deliberately fetched cross-origin by the frontend's own domain —
        // see routes/web.php's storage route — so it needs 'cross-origin'
        // here or the browser blocks the fetch regardless of any CORS header.
        $response->headers->set(
            'Cross-Origin-Resource-Policy',
            $request->is('storage/*') ? 'cross-origin' : 'same-origin'
        );
        // Every response here is per-user/authenticated JSON — never worth
        // letting a browser or intermediate proxy cache it.
        $response->headers->set('Cache-Control', 'no-store, private');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
