<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 'api' rate limit (AppServiceProvider): a page fires ~8-10 calls, so a
 * signed-in user gets 300 a minute; anonymous calls stay at 60 per IP.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_is_not_cut_off_at_60_a_minute(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        for ($i = 1; $i <= 70; $i++) {
            $this->withApiKey()->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/branding')
                ->assertOk();
        }
    }

    public function test_anonymous_calls_are_still_limited_to_60_a_minute(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->withApiKey()->getJson('/api/v1/branding')->assertOk();
        }
        $this->withApiKey()->getJson('/api/v1/branding')->assertStatus(429)->assertHeader('Retry-After');
    }
}
