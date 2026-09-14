<?php

namespace Tests\Feature;

use App\Models\PermissionGroup;
use App\Models\PermissionSubGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionSubGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_permission_sub_group(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/permission-sub-groups', [
            'permission_group_id' => $group->id,
            'name' => 'View Users',
            'sort_order' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'View Users')
            ->assertJsonPath('data.permission_group_id', $group->id)
            ->assertJsonPath('data.permission_group.name', $group->name)
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('permission_sub_groups', ['name' => 'View Users', 'permission_group_id' => $group->id]);
    }

    public function test_name_and_permission_group_id_are_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/permission-sub-groups', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'permission_group_id']);
    }

    public function test_permission_group_id_must_reference_an_existing_group(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/permission-sub-groups', [
            'permission_group_id' => 999999,
            'name' => 'Orphan Sub Group',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('permission_group_id');
    }

    public function test_sub_group_name_must_be_unique(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();
        PermissionSubGroup::factory()->create(['permission_group_id' => $group->id, 'name' => 'Edit Users']);

        $response = $this->withApiKey()->postJson('/api/v1/permission-sub-groups', [
            'permission_group_id' => $group->id,
            'name' => 'Edit Users',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_soft_deleted_sub_groups_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $group = PermissionGroup::factory()->create();
        $trashed = PermissionSubGroup::factory()->create(['permission_group_id' => $group->id, 'name' => 'Delete Users']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/permission-sub-groups', [
            'permission_group_id' => $group->id,
            'name' => 'Delete Users',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Delete Users');
        $this->assertDatabaseHas('permission_sub_groups', ['id' => $trashed->id, 'name' => 'Delete Users']);
        $this->assertDatabaseHas('permission_sub_groups', ['name' => 'Delete Users', 'deleted_at' => null]);
    }

    public function test_admin_can_update_a_permission_sub_group(): void
    {
        $this->actingAdmin();
        $subGroup = PermissionSubGroup::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/permission-sub-groups/{$subGroup->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_permission_sub_group(): void
    {
        $this->actingAdmin();
        $subGroup = PermissionSubGroup::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/permission-sub-groups/{$subGroup->id}");

        $response->assertOk();
        $this->assertSoftDeleted('permission_sub_groups', ['id' => $subGroup->id]);
    }

    public function test_admin_can_restore_a_soft_deleted_permission_sub_group(): void
    {
        $this->actingAdmin();
        $subGroup = PermissionSubGroup::factory()->create();
        $subGroup->delete();

        $response = $this->withApiKey()->postJson("/api/v1/permission-sub-groups/{$subGroup->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('permission_sub_groups', ['id' => $subGroup->id, 'deleted_at' => null]);
    }

    public function test_can_filter_sub_groups_by_permission_group_id(): void
    {
        $this->actingAdmin();
        $groupA = PermissionGroup::factory()->create();
        $groupB = PermissionGroup::factory()->create();
        $inGroupA = PermissionSubGroup::factory()->create(['permission_group_id' => $groupA->id]);
        PermissionSubGroup::factory()->create(['permission_group_id' => $groupB->id]);

        $response = $this->withApiKey()->getJson("/api/v1/permission-sub-groups?permission_group_id={$groupA->id}&per_page=100");

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$inGroupA->id], $ids);
    }

    public function test_can_fetch_a_single_permission_sub_group_by_id_query_param(): void
    {
        $this->actingAdmin();
        $subGroup = PermissionSubGroup::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/permission-sub-groups?id={$subGroup->id}");

        $response->assertOk()->assertJsonPath('data.id', $subGroup->id);
    }
}
