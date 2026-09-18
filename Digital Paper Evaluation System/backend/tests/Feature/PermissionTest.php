<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_is_denied(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withApiKey()->getJson('/api/v1/users');

        $response->assertStatus(403)
            ->assertJson(['status' => false, 'message' => 'This action is unauthorized.']);
    }

    public function test_user_with_permission_is_allowed(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('user-list');
        Sanctum::actingAs($user);

        $response = $this->withApiKey()->getJson('/api/v1/users');

        $response->assertOk();
    }

    public function test_super_admin_bypasses_all_permission_checks_via_role_id(): void
    {
        $this->seed(PermissionSeeder::class);

        $superAdminRole = Role::forceCreate([
            'id' => ((array) config('roles.super_admin_id'))[0],
            'name' => 'Super Admin',
            'guard_name' => config('auth.defaults.guard'),
        ]);

        $user = User::factory()->create();
        $user->assignRole($superAdminRole->id);
        Sanctum::actingAs($user);

        // Zero direct permissions granted — access comes purely from
        // Gate::before matching the role ID.
        $response = $this->withApiKey()->getJson('/api/v1/users');

        $response->assertOk();
    }
}
