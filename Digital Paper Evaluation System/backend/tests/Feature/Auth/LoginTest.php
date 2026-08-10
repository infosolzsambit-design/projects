<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);
    }

    public function test_user_can_login_with_username_instead_of_email(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->username,
            'password' => 'Password!23',
        ]);

        $response->assertOk()->assertJsonPath('status', true);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['status' => false, 'message' => 'Invalid credentials.']);
    }

    public function test_login_fails_for_nonexistent_user_with_same_generic_message(): void
    {
        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => 'nobody@example.com',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['status' => false, 'message' => 'Invalid credentials.']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'Password!23']);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->username,
            'password' => 'Password!23',
        ]);

        // Same generic message as any other failure — never reveals the
        // account exists but is disabled.
        $response->assertStatus(401)
            ->assertJson(['status' => false, 'message' => 'Invalid credentials.']);
    }

    public function test_login_requires_api_key(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'login' => 'x@example.com',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(401);
    }
}
