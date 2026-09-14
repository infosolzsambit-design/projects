<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_validate_reports_every_valid_row_as_valid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/validate', [
            'rows' => [
                ['name' => 'Physics', 'code' => 'PHY'],
                ['name' => 'Chemistry', 'code' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
        $this->assertTrue($response->json('data.rows.0.valid'));
        $this->assertTrue($response->json('data.rows.1.valid'));
    }

    public function test_validate_flags_a_blank_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/validate', [
            'rows' => [['name' => '', 'code' => 'X']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Name is required.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_flags_duplicate_name_within_the_same_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/validate', [
            'rows' => [
                ['name' => 'Commerce', 'code' => ''],
                ['name' => 'commerce', 'code' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate name within this upload.', $response->json('data.rows.0.errors.name'));
        $this->assertSame('Duplicate name within this upload.', $response->json('data.rows.1.errors.name'));
    }

    public function test_validate_flags_a_name_already_used_by_an_existing_department(): void
    {
        $this->actingAdmin();
        Department::factory()->create(['name' => 'Existing Dept']);

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/validate', [
            'rows' => [['name' => 'Existing Dept', 'code' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This department name is already in use.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_allows_a_name_matching_a_soft_deleted_department(): void
    {
        $this->actingAdmin();
        $trashed = Department::factory()->create(['name' => 'Reused Name']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/validate', [
            'rows' => [['name' => 'Reused Name', 'code' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/store', [
            'rows' => [
                ['name' => 'Bulk Dept One', 'code' => 'BD1'],
                ['name' => 'Bulk Dept Two', 'code' => ''],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('departments', ['name' => 'Bulk Dept One', 'code' => 'BD1']);
        $this->assertDatabaseHas('departments', ['name' => 'Bulk Dept Two', 'code' => null]);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/store', [
            'rows' => [
                ['name' => 'Valid Dept', 'code' => ''],
                ['name' => '', 'code' => ''],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('departments', ['name' => 'Valid Dept']);
    }

    public function test_store_with_no_rows_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/departments/bulk/store', ['rows' => []]);

        $response->assertStatus(422);
    }
}
