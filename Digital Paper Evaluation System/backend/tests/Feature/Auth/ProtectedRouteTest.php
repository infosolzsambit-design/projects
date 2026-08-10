<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProtectedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_route_rejects_request_without_token(): void
    {
        $response = $this->withApiKey()->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson(['status' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_protected_route_accepts_request_with_valid_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withApiKey()->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $login = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ]);
        $token = $login->json('data.token');

        $this->assertSame(1, $user->tokens()->count());

        $response = $this->withApiKey()
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout');

        $response->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $user->createToken('one');
        $user->createToken('two');
        Sanctum::actingAs($user);

        $this->assertSame(2, $user->tokens()->count());

        $response = $this->withApiKey()->postJson('/api/v1/logout-all');

        $response->assertOk();
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
