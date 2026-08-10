<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TokenPinningTest extends TestCase
{
    use RefreshDatabase;

    private function forgetCachedGuard(): void
    {
        Auth::forgetGuards();
    }

    private function login(User $user, string $ip, string $userAgent): string
    {
        $response = $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $userAgent)
            ->postJson('/api/v1/login', [
                'login' => $user->email,
                'password' => 'Password!23',
            ]);

        return $response->json('data.token');
    }

    public function test_login_pins_the_token_to_the_requesting_ip_and_browser(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $this->login($user, '203.0.113.10', 'TestBrowser/1.0');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'TestBrowser/1.0',
        ]);
    }

    public function test_request_from_the_same_ip_and_browser_succeeds(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);
        $token = $this->login($user, '203.0.113.10', 'TestBrowser/1.0');

        $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'TestBrowser/1.0')
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_request_from_a_different_ip_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);
        $token = $this->login($user, '203.0.113.10', 'TestBrowser/1.0');

        $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'TestBrowser/1.0')
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_request_from_a_different_browser_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);
        $token = $this->login($user, '203.0.113.10', 'TestBrowser/1.0');

        $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'DifferentBrowser/9.0')
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_a_stolen_token_cannot_be_re_pinned_via_refresh_from_a_different_context(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);
        $token = $this->login($user, '203.0.113.10', 'TestBrowser/1.0');

        // An attacker with the copied token, calling /refresh from their
        // own machine, must not be able to rotate it into a token pinned
        // to *their* context.
        $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'AttackerBrowser/1.0')
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/refresh')
            ->assertStatus(401);

        // The legitimate context can still refresh normally.
        $this->forgetCachedGuard();
        $this->withApiKey()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'TestBrowser/1.0')
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/refresh')
            ->assertOk();
    }
}
