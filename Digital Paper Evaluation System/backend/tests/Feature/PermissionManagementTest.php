<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $superAdminRole = Role::forceCreate([
            'id' => config('roles.super_admin_id'),
            'name' => 'Super Admin',
            'guard_name' => config('auth.defaults.guard'),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole($superAdminRole->id);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Regression test for the same class of bug already fixed on
     * courses.code/programs.code/roles.name: a plain DB-level unique
     * constraint on (name, guard_name) blocked reusing a name that only a
     * *trashed* permission still held.
     */
    public function test_a_soft_deleted_permissions_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Permission::create(['name' => 'paper-export', 'guard_name' => config('auth.defaults.guard')]);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'paper-export');
        $this->assertDatabaseHas('permissions', ['id' => $trashed->id, 'name' => 'paper-export']);
        $this->assertDatabaseHas('permissions', ['name' => 'paper-export', 'deleted_at' => null]);
    }

    public function test_permission_name_must_still_be_unique_among_active_permissions(): void
    {
        $this->actingAdmin();
        Permission::create(['name' => 'paper-export', 'guard_name' => config('auth.defaults.guard')]);

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
