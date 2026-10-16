<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentBulkUploadTest extends TestCase
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
        $program = Program::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [
                ['name' => 'Alice', 'registration_no' => 'R1', 'batch' => '2024', 'program_name' => $program->name],
                ['name' => 'Bob', 'registration_no' => 'R2', 'batch' => '2024', 'program_name' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_validate_flags_a_blank_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => '', 'registration_no' => 'R1', 'batch' => '2024', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Name is required.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_flags_a_blank_registration_no(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'registration_no' => '', 'batch' => '2024', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Registration number is required.', $response->json('data.rows.0.errors.registration_no'));
    }

    public function test_validate_flags_duplicate_registration_no_within_the_same_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [
                ['name' => 'Alice', 'registration_no' => 'DUPE', 'batch' => '2024', 'program_name' => ''],
                ['name' => 'Bob', 'registration_no' => 'dupe', 'batch' => '2024', 'program_name' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate registration number within this upload.', $response->json('data.rows.0.errors.registration_no'));
        $this->assertSame('Duplicate registration number within this upload.', $response->json('data.rows.1.errors.registration_no'));
    }

    public function test_validate_flags_a_registration_no_already_used_by_an_existing_student(): void
    {
        $this->actingAdmin();
        Student::factory()->create(['registration_no' => 'TAKEN1']);

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'registration_no' => 'TAKEN1', 'batch' => '2024', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This registration number is already in use.', $response->json('data.rows.0.errors.registration_no'));
    }

    public function test_validate_flags_a_too_long_batch(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'registration_no' => 'R1', 'batch' => str_repeat('9', 21), 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Batch may not be longer than 20 characters.', $response->json('data.rows.0.errors.batch'));
    }

    public function test_validate_flags_a_program_name_that_does_not_exist(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'registration_no' => 'R1', 'batch' => '2024', 'program_name' => 'Nonexistent Program']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('No program with this exact name exists.', $response->json('data.rows.0.errors.program_name'));
    }

    public function test_validate_allows_a_blank_program_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'registration_no' => 'R1', 'batch' => '2024', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/store', [
            'rows' => [
                ['name' => 'Bulk Student One', 'registration_no' => 'BS1', 'batch' => '2024', 'program_name' => $program->name],
                ['name' => 'Bulk Student Two', 'registration_no' => 'BS2', 'batch' => '', 'program_name' => ''],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('students', ['name' => 'Bulk Student One', 'registration_no' => 'BS1', 'batch' => '2024', 'program_name' => $program->name]);
        $this->assertDatabaseHas('students', ['name' => 'Bulk Student Two', 'registration_no' => 'BS2', 'batch' => null, 'program_name' => null]);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/store', [
            'rows' => [
                ['name' => 'Valid Student', 'registration_no' => 'VS1', 'batch' => '2024', 'program_name' => ''],
                ['name' => '', 'registration_no' => 'VS2', 'batch' => '2024', 'program_name' => ''],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('students', ['name' => 'Valid Student']);
    }

    public function test_store_with_no_rows_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/store', ['rows' => []]);

        $response->assertStatus(422);
    }
}
