<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_emails_a_reset_link_for_an_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        $response->assertOk()
            ->assertJson(['status' => true, 'message' => 'If an account exists for that email, a password reset link has been sent.']);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_gives_the_same_generic_response_for_an_unknown_email(): void
    {
        Notification::fake();

        $response = $this->withApiKey()->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']);

        // Same response either way — never reveals whether the account
        // exists (mirrors login()'s single "Invalid credentials." message).
        $response->assertOk()
            ->assertJson(['status' => true, 'message' => 'If an account exists for that email, a password reset link has been sent.']);
        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $response = $this->withApiKey()->postJson('/api/v1/forgot-password', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_emailed_reset_link_points_at_the_frontend_reset_password_page(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        config(['app.frontend_url' => 'https://app.example.com']);

        $this->withApiKey()->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);
            $url = $mail->viewData['url'] ?? null;

            return $mail->view === 'emails.reset-password'
                && str_starts_with($url, 'https://app.example.com/reset-password?token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_the_reset_password_email_renders_the_branded_template(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Jane Doe']);

        $this->withApiKey()->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $rendered = $notification->toMail($user)->render();

            return str_contains($rendered, 'Reset Your Password')
                && str_contains($rendered, 'Jane Doe')
                && str_contains($rendered, 'logo-image.png');
        });
    }

    public function test_verify_reset_token_reports_valid_for_a_fresh_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password/verify', [
            'token' => $token,
            'email' => $user->email,
        ]);

        $response->assertOk()->assertJsonPath('data.valid', true);
    }

    public function test_verify_reset_token_reports_invalid_for_a_bogus_token(): void
    {
        $user = User::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/reset-password/verify', [
            'token' => 'not-the-real-token',
            'email' => $user->email,
        ]);

        $response->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_verify_reset_token_reports_invalid_for_an_unknown_email(): void
    {
        $response = $this->withApiKey()->postJson('/api/v1/reset-password/verify', [
            'token' => 'whatever',
            'email' => 'nobody@example.com',
        ]);

        $response->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_verify_reset_token_does_not_consume_the_token(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        $token = Password::createToken($user);

        // Checking validity twice, then actually resetting, all still work
        // — verify() never touches/deletes the stored token.
        $this->withApiKey()->postJson('/api/v1/reset-password/verify', ['token' => $token, 'email' => $user->email]);
        $this->withApiKey()->postJson('/api/v1/reset-password/verify', ['token' => $token, 'email' => $user->email]);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_reset_password_with_a_valid_token_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        $token = Password::createToken($user);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk()->assertJson(['status' => true]);
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_reset_password_revokes_every_existing_session(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);
        $user->createToken('api-token');
        $this->assertSame(1, $user->tokens()->count());
        $token = Password::createToken($user);

        $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_password_fails_with_an_invalid_token(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword1!']);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => 'not-the-real-token',
            'email' => $user->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    public function test_reset_password_requires_the_password_to_meet_the_policy(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_password_requires_the_confirmation_to_match(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->withApiKey()->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'SomethingElse1!',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
