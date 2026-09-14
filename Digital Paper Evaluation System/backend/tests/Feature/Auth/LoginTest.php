<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
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

    /**
     * `permission_names` is a UI-only show/hide list (see DashboardView's
     * v-if="authStore.can(...)") — it must reflect permissions granted via
     * a role, not just ones assigned directly to the user, since that's
     * how every permission in this app is actually granted.
     */
    public function test_login_response_includes_role_granted_permission_names(): void
    {
        $role = Role::create(['name' => 'Dashboard Viewer', 'guard_name' => config('auth.defaults.guard')]);
        $permission = Permission::create(['name' => 'admin-dashboard', 'guard_name' => config('auth.defaults.guard')]);
        $role->givePermissionTo($permission);

        $user = User::factory()->create(['password' => 'Password!23']);
        $user->assignRole($role);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()->assertJsonPath('data.user.permission_names', ['admin-dashboard']);
    }

    public function test_login_reports_is_super_admin_by_role_id_not_name(): void
    {
        // Deliberately named unlike the real seeded role, then pointed at
        // by ID via config — proves this is checked by ID
        // (config('roles.super_admin_id')), never by name.
        $role = Role::create(['name' => 'Not The Usual Name', 'guard_name' => config('auth.defaults.guard')]);
        config(['roles.super_admin_id' => $role->id]);

        $user = User::factory()->create(['password' => 'Password!23']);
        $user->assignRole($role);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()->assertJsonPath('data.user.is_super_admin', true);
    }

    public function test_login_requires_profile_completion_for_a_teacher_with_no_esign_yet(): void
    {
        $detail = \App\Models\TeacherDetail::factory()->create();
        $detail->user->update(['password' => bcrypt('Password!23')]);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $detail->user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.is_super_admin', false)
            ->assertJsonPath('data.user.profile_completion_required', true);
    }

    public function test_login_does_not_require_profile_completion_once_esign_is_set(): void
    {
        $detail = \App\Models\TeacherDetail::factory()->create(['esign' => 'data:image/png;base64,AAAA']);
        $detail->user->update(['password' => bcrypt('Password!23')]);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $detail->user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()->assertJsonPath('data.user.profile_completion_required', false);
    }

    public function test_login_does_not_require_profile_completion_for_a_non_teacher_non_super_admin(): void
    {
        $user = User::factory()->create(['password' => 'Password!23']); // no teacherDetail

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'Password!23',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.is_super_admin', false)
            ->assertJsonPath('data.user.profile_completion_required', false);
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

    public function test_user_can_login_with_phone_number_instead_of_email(): void
    {
        $user = User::factory()->create(['password' => 'Password!23', 'phone_no' => '9876543210']);

        $response = $this->withApiKey()->postJson('/api/v1/login', [
            'login' => $user->phone_no,
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
