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
                ['name' => 'Alice', 'roll_no' => 'R1', 'semester' => '3', 'program_name' => $program->name],
                ['name' => 'Bob', 'roll_no' => 'R2', 'semester' => '1', 'program_name' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_validate_flags_a_blank_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => '', 'roll_no' => 'R1', 'semester' => '1', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Name is required.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_flags_a_blank_roll_no(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'roll_no' => '', 'semester' => '1', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Roll number is required.', $response->json('data.rows.0.errors.roll_no'));
    }

    public function test_validate_flags_duplicate_roll_no_within_the_same_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [
                ['name' => 'Alice', 'roll_no' => 'DUPE', 'semester' => '1', 'program_name' => ''],
                ['name' => 'Bob', 'roll_no' => 'dupe', 'semester' => '1', 'program_name' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate roll number within this upload.', $response->json('data.rows.0.errors.roll_no'));
        $this->assertSame('Duplicate roll number within this upload.', $response->json('data.rows.1.errors.roll_no'));
    }

    public function test_validate_flags_a_roll_no_already_used_by_an_existing_student(): void
    {
        $this->actingAdmin();
        Student::factory()->create(['roll_no' => 'TAKEN1']);

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'roll_no' => 'TAKEN1', 'semester' => '1', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This roll number is already in use.', $response->json('data.rows.0.errors.roll_no'));
    }

    public function test_validate_flags_a_non_numeric_semester(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'roll_no' => 'R1', 'semester' => 'abc', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Semester must be a whole number of at least 1.', $response->json('data.rows.0.errors.semester'));
    }

    public function test_validate_flags_a_program_name_that_does_not_exist(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'roll_no' => 'R1', 'semester' => '2', 'program_name' => 'Nonexistent Program']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('No program with this exact name exists.', $response->json('data.rows.0.errors.program_name'));
    }

    public function test_validate_allows_a_blank_program_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/validate', [
            'rows' => [['name' => 'Alice', 'roll_no' => 'R1', 'semester' => '2', 'program_name' => '']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/store', [
            'rows' => [
                ['name' => 'Bulk Student One', 'roll_no' => 'BS1', 'semester' => '1', 'program_name' => $program->name],
                ['name' => 'Bulk Student Two', 'roll_no' => 'BS2', 'semester' => '2', 'program_name' => ''],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('students', ['name' => 'Bulk Student One', 'roll_no' => 'BS1', 'semester' => 1, 'program_name' => $program->name]);
        $this->assertDatabaseHas('students', ['name' => 'Bulk Student Two', 'roll_no' => 'BS2', 'semester' => 2, 'program_name' => null]);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students/bulk/store', [
            'rows' => [
                ['name' => 'Valid Student', 'roll_no' => 'VS1', 'semester' => '1', 'program_name' => ''],
                ['name' => '', 'roll_no' => 'VS2', 'semester' => '1', 'program_name' => ''],
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
