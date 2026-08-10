<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Auth::guard() caches the resolved user for the app container's
     * lifetime, which (unlike a real request) spans every simulated request
     * in a test method — without this, a second call with a *different*
     * token in the same test would wrongly return the first call's cached
     * user instead of re-resolving.
     */
    private function forgetCachedGuard(): void
    {
        Auth::forgetGuards();
    }

    public function test_refresh_rejects_a_request_without_a_token(): void
    {
        $response = $this->withApiKey()->postJson('/api/v1/refresh');

        $response->assertStatus(401);
    }

    public function test_refresh_issues_a_new_token_and_revokes_the_old_one(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $login = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ])->assertOk();

        $oldToken = $login->json('data.token');

        $refresh = $this->withApiKey()
            ->withHeader('Authorization', "Bearer {$oldToken}")
            ->postJson('/api/v1/refresh');

        $refresh->assertOk();
        $newToken = $refresh->json('data.token');

        $this->assertNotSame($oldToken, $newToken);

        // The old token must no longer work.
        $this->forgetCachedGuard();
        $this->withApiKey()
            ->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        // The new token must work.
        $this->forgetCachedGuard();
        $this->withApiKey()
            ->withHeader('Authorization', "Bearer {$newToken}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_a_token_older_than_the_configured_expiration_is_rejected(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $user->tokens()->update(['created_at' => now()->subMinutes(61)]);

        $this->withApiKey()
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }
}
