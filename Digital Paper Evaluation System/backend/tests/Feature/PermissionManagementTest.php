<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\PermissionSubGroup;
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

    private function makeSubGroup(): PermissionSubGroup
    {
        return PermissionSubGroup::factory()->create();
    }

    public function test_admin_can_create_a_permission_with_a_group_and_sub_group(): void
    {
        $this->actingAdmin();
        $subGroup = $this->makeSubGroup();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_group_id' => $subGroup->permission_group_id,
            'permission_sub_group_id' => $subGroup->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'paper-export')
            ->assertJsonPath('data.permission_sub_group_id', $subGroup->id);
        $this->assertDatabaseHas('permissions', ['name' => 'paper-export', 'permission_sub_group_id' => $subGroup->id]);
    }

    public function test_permission_group_id_is_required(): void
    {
        $this->actingAdmin();
        $subGroup = $this->makeSubGroup();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_sub_group_id' => $subGroup->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('permission_group_id');
    }

    public function test_permission_sub_group_id_is_required(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_group_id' => $group->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('permission_sub_group_id');
    }

    public function test_permission_sub_group_id_must_reference_an_existing_sub_group(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_group_id' => $group->id,
            'permission_sub_group_id' => 999999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('permission_sub_group_id');
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
        $subGroup = $this->makeSubGroup();
        $trashed = Permission::create([
            'name' => 'paper-export',
            'guard_name' => config('auth.defaults.guard'),
            'permission_group_id' => $subGroup->permission_group_id,
            'permission_sub_group_id' => $subGroup->id,
        ]);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_group_id' => $subGroup->permission_group_id,
            'permission_sub_group_id' => $subGroup->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'paper-export');
        $this->assertDatabaseHas('permissions', ['id' => $trashed->id, 'name' => 'paper-export']);
        $this->assertDatabaseHas('permissions', ['name' => 'paper-export', 'deleted_at' => null]);
    }

    public function test_permission_name_must_be_lowercase_hyphenated_slug(): void
    {
        $this->actingAdmin();
        $subGroup = $this->makeSubGroup();

        foreach (['Paper View', 'paper_view', 'paper.view', 'paper--view', '-paper-view', 'paper-view-'] as $invalid) {
            $response = $this->withApiKey()->postJson('/api/v1/permissions', [
                'name' => $invalid,
                'permission_group_id' => $subGroup->permission_group_id,
                'permission_sub_group_id' => $subGroup->id,
            ]);
            $response->assertStatus(422)->assertJsonValidationErrors('name');
        }
    }

    public function test_permission_name_must_still_be_unique_among_active_permissions(): void
    {
        $this->actingAdmin();
        $subGroup = $this->makeSubGroup();
        Permission::create([
            'name' => 'paper-export',
            'guard_name' => config('auth.defaults.guard'),
            'permission_group_id' => $subGroup->permission_group_id,
            'permission_sub_group_id' => $subGroup->id,
        ]);

        $response = $this->withApiKey()->postJson('/api/v1/permissions', [
            'name' => 'paper-export',
            'permission_group_id' => $subGroup->permission_group_id,
            'permission_sub_group_id' => $subGroup->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
