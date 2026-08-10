<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_standard_hardening_headers_are_present_on_every_response(): void
    {
        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_hsts_header_is_only_set_for_secure_requests(): void
    {
        $insecure = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => 'nobody@example.com',
            'password' => 'wrong',
        ]);
        $insecure->assertHeaderMissing('Strict-Transport-Security');

        // trustProxies(at: '*') (bootstrap/app.php) means isSecure() is
        // resolved from X-Forwarded-Proto, not the raw HTTPS server var —
        // this app expects TLS to be terminated at a reverse proxy.
        $secure = $this->withApiKey()
            ->withHeader('X-Forwarded-Proto', 'https')
            ->postJson('/api/v1/login', [
                'login' => 'nobody@example.com',
                'password' => 'wrong',
            ]);
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
