<?php

namespace Tests\Feature;

use App\Models\PermissionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_permission_group(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/permission-groups', [
            'name' => 'User Management',
            'sort_order' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'User Management')
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('permission_groups', ['name' => 'User Management']);
    }

    public function test_name_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/permission-groups', [
            'sort_order' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_permission_group_name_must_be_unique(): void
    {
        $this->actingAdmin();
        PermissionGroup::factory()->create(['name' => 'Master Data']);

        $response = $this->withApiKey()->postJson('/api/v1/permission-groups', [
            'name' => 'Master Data',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_soft_deleted_groups_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = PermissionGroup::factory()->create(['name' => 'Reports']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/permission-groups', [
            'name' => 'Reports',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Reports');
        $this->assertDatabaseHas('permission_groups', ['id' => $trashed->id, 'name' => 'Reports']);
        $this->assertDatabaseHas('permission_groups', ['name' => 'Reports', 'deleted_at' => null]);
    }

    public function test_admin_can_update_a_permission_group(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/permission-groups/{$group->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_permission_group(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/permission-groups/{$group->id}");

        $response->assertOk();
        $this->assertSoftDeleted('permission_groups', ['id' => $group->id]);
    }

    public function test_soft_deleted_group_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();
        $group->delete();

        $response = $this->withApiKey()->getJson('/api/v1/permission-groups?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($group->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_permission_group(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();
        $group->delete();

        $response = $this->withApiKey()->postJson("/api/v1/permission-groups/{$group->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('permission_groups', ['id' => $group->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_permission_group_by_id_query_param(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/permission-groups?id={$group->id}");

        $response->assertOk()->assertJsonPath('data.id', $group->id);
    }
}
