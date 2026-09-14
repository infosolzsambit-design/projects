<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramBulkUploadTest extends TestCase
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
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [
                ['name' => 'B.Tech CSE', 'department_id' => $department->id, 'code' => 'BTCSE'],
                ['name' => 'B.Tech ECE', 'department_id' => $department->id, 'code' => 'BTECE'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
        $this->assertTrue($response->json('data.rows.0.valid'));
        $this->assertTrue($response->json('data.rows.1.valid'));
    }

    public function test_validate_flags_a_blank_name(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [['name' => '', 'department_id' => $department->id, 'code' => 'X1']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Name is required.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_flags_a_missing_department_id(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [['name' => 'Some Program', 'department_id' => null, 'code' => 'X2']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Select a department.', $response->json('data.rows.0.errors.department_id'));
    }

    public function test_validate_flags_a_department_id_that_does_not_exist(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [['name' => 'Some Program', 'department_id' => 999999, 'code' => 'X3']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Select a valid department from the list.', $response->json('data.rows.0.errors.department_id'));
    }

    public function test_validate_flags_a_blank_code(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [['name' => 'Some Program', 'department_id' => $department->id, 'code' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Code is required.', $response->json('data.rows.0.errors.code'));
    }

    public function test_validate_flags_duplicate_code_within_the_same_upload(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [
                ['name' => 'Program A', 'department_id' => $department->id, 'code' => 'DUPE'],
                ['name' => 'Program B', 'department_id' => $department->id, 'code' => 'dupe'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate code within this upload.', $response->json('data.rows.0.errors.code'));
        $this->assertSame('Duplicate code within this upload.', $response->json('data.rows.1.errors.code'));
    }

    public function test_validate_flags_a_code_already_used_by_an_existing_program(): void
    {
        $this->actingAdmin();
        Program::factory()->create(['code' => 'TAKEN1']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/validate', [
            'rows' => [['name' => 'New Program', 'department_id' => $department->id, 'code' => 'TAKEN1']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This code is already in use.', $response->json('data.rows.0.errors.code'));
    }

    public function test_store_creates_every_row_when_all_valid_with_no_courses_mapped(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create(['name' => 'Engineering']);

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/store', [
            'rows' => [
                ['name' => 'Bulk Program One', 'department_id' => $department->id, 'code' => 'BP1'],
                ['name' => 'Bulk Program Two', 'department_id' => $department->id, 'code' => 'BP2'],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('programs', ['name' => 'Bulk Program One', 'code' => 'BP1', 'department' => 'Engineering']);
        $this->assertDatabaseHas('programs', ['name' => 'Bulk Program Two', 'code' => 'BP2', 'department' => 'Engineering']);

        $program = Program::where('code', 'BP1')->first();
        $this->assertCount(0, $program->courses);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/store', [
            'rows' => [
                ['name' => 'Valid Program', 'department_id' => $department->id, 'code' => 'OK1'],
                ['name' => '', 'department_id' => $department->id, 'code' => 'OK2'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('programs', ['name' => 'Valid Program']);
    }

    public function test_store_with_no_rows_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs/bulk/store', ['rows' => []]);

        $response->assertStatus(422);
    }
}
