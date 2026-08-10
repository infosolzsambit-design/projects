<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_department(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments', [
            'name' => 'Computer Science Engineering',
            'code' => 'CSE',
            'short_description' => 'Department of Computer Science and Engineering',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Computer Science Engineering')
            ->assertJsonPath('data.code', 'CSE')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('departments', ['name' => 'Computer Science Engineering']);
    }

    public function test_name_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments', [
            'code' => 'CSE',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_department_name_must_be_unique(): void
    {
        $this->actingAdmin();
        Department::factory()->create(['name' => 'Civil Engineering']);

        $response = $this->withApiKey()->postJson('/api/v1/departments', [
            'name' => 'Civil Engineering',
            'code' => 'CE2',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_department_code_does_not_need_to_be_unique(): void
    {
        $this->actingAdmin();
        Department::factory()->create(['code' => 'DUP']);

        $response = $this->withApiKey()->postJson('/api/v1/departments', [
            'name' => 'Another Department',
            'code' => 'DUP',
        ]);

        $response->assertStatus(201);
    }

    public function test_a_soft_deleted_departments_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Department::factory()->create(['name' => 'Mechanical Engineering']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/departments', [
            'name' => 'Mechanical Engineering',
            'code' => 'ME',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Mechanical Engineering');
        $this->assertDatabaseHas('departments', ['id' => $trashed->id, 'name' => 'Mechanical Engineering']);
        $this->assertDatabaseHas('departments', ['name' => 'Mechanical Engineering', 'code' => 'ME', 'deleted_at' => null]);
    }

    public function test_admin_can_update_a_department(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/departments/{$department->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_department(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/departments/{$department->id}");

        $response->assertOk();
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_soft_deleted_department_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();
        $department->delete();

        $response = $this->withApiKey()->getJson('/api/v1/departments?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($department->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_department(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();
        $department->delete();

        $response = $this->withApiKey()->postJson("/api/v1/departments/{$department->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_department_by_id_query_param(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/departments?id={$department->id}");

        $response->assertOk()->assertJsonPath('data.id', $department->id);
    }
}
