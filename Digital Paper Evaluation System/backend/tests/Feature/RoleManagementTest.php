<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleManagementTest extends TestCase
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
     * courses.code/programs.code: a plain DB-level unique constraint on
     * (name, guard_name) blocked reusing a name that only a *trashed* role
     * still held.
     */
    public function test_a_soft_deleted_roles_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Role::create(['name' => 'Evaluator', 'guard_name' => config('auth.defaults.guard')]);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/roles', [
            'name' => 'Evaluator',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Evaluator');
        $this->assertDatabaseHas('roles', ['id' => $trashed->id, 'name' => 'Evaluator']);
        $this->assertDatabaseHas('roles', ['name' => 'Evaluator', 'deleted_at' => null]);
    }

    public function test_role_name_must_still_be_unique_among_active_roles(): void
    {
        $this->actingAdmin();
        Role::create(['name' => 'Evaluator', 'guard_name' => config('auth.defaults.guard')]);

        $response = $this->withApiKey()->postJson('/api/v1/roles', [
            'name' => 'Evaluator',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
