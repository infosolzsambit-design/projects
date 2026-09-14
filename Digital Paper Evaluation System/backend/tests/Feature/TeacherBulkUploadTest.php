<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeacherDetail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function row(Department $department, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Row Teacher',
            'email' => 'row.teacher@example.com',
            'phone_no' => '9876511111',
            'emp_code' => 'BULK-0001',
            'department_id' => $department->id,
            'designation' => 'Lecturer',
        ], $overrides);
    }

    public function test_validate_reports_every_valid_row_as_valid(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row($department, ['email' => 'a@example.com', 'phone_no' => '9876500001', 'emp_code' => 'BULK-0001']),
                $this->row($department, ['email' => 'b@example.com', 'phone_no' => '9876500002', 'emp_code' => 'BULK-0002']),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
        $this->assertTrue($response->json('data.rows.0.valid'));
        $this->assertTrue($response->json('data.rows.1.valid'));
    }

    public function test_validate_flags_missing_required_fields(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                ['name' => '', 'email' => '', 'phone_no' => '', 'emp_code' => '', 'department_id' => null, 'designation' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $errors = $response->json('data.rows.0.errors');
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('phone_no', $errors);
        $this->assertArrayHasKey('emp_code', $errors);
        $this->assertArrayHasKey('department_id', $errors);
        $this->assertArrayHasKey('designation', $errors);
    }

    public function test_validate_flags_duplicate_email_within_the_same_upload(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row($department, ['email' => 'same@example.com', 'phone_no' => '9876500003', 'emp_code' => 'BULK-0003']),
                $this->row($department, ['email' => 'same@example.com', 'phone_no' => '9876500004', 'emp_code' => 'BULK-0004']),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate email within this upload.', $response->json('data.rows.0.errors.email'));
        $this->assertSame('Duplicate email within this upload.', $response->json('data.rows.1.errors.email'));
    }

    public function test_validate_flags_duplicate_emp_code_within_the_same_upload(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row($department, ['email' => 'x@example.com', 'phone_no' => '9876500005', 'emp_code' => 'SAME-CODE']),
                $this->row($department, ['email' => 'y@example.com', 'phone_no' => '9876500006', 'emp_code' => 'SAME-CODE']),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate employee code within this upload.', $response->json('data.rows.0.errors.emp_code'));
    }

    public function test_validate_flags_an_email_already_used_by_an_existing_user(): void
    {
        $this->actingAdmin();
        User::factory()->create(['email' => 'existing@example.com']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [$this->row($department, ['email' => 'existing@example.com'])],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This email is already in use.', $response->json('data.rows.0.errors.email'));
    }

    public function test_validate_flags_an_emp_code_already_used_by_an_existing_teacher(): void
    {
        $this->actingAdmin();
        TeacherDetail::factory()->create(['emp_code' => 'TAKEN-1']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [$this->row($department, ['emp_code' => 'TAKEN-1'])],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This employee code is already in use.', $response->json('data.rows.0.errors.emp_code'));
    }

    public function test_validate_flags_a_department_id_that_does_not_exist(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [array_merge($this->row(Department::factory()->create()), ['department_id' => 999999])],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Select a valid department from the list.', $response->json('data.rows.0.errors.department_id'));
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create(['name' => 'Mathematics']);

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/store', [
            'rows' => [
                $this->row($department, ['name' => 'Teacher One', 'email' => 'one@example.com', 'phone_no' => '9876500010', 'emp_code' => 'BULK-A']),
                $this->row($department, ['name' => 'Teacher Two', 'email' => 'two@example.com', 'phone_no' => '9876500011', 'emp_code' => 'BULK-B']),
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('users', ['email' => 'one@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'two@example.com']);
        $this->assertDatabaseHas('teacher_details', ['emp_code' => 'BULK-A', 'department' => 'Mathematics']);
        $this->assertDatabaseHas('teacher_details', ['emp_code' => 'BULK-B', 'department' => 'Mathematics']);

        $user = User::where('email', 'one@example.com')->first();
        $this->assertTrue($user->hasRole((int) config('roles.teacher_id')));
        $this->assertNull($user->password);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/store', [
            'rows' => [
                $this->row($department, ['email' => 'valid@example.com', 'phone_no' => '9876500012', 'emp_code' => 'BULK-C']),
                $this->row($department, ['name' => '', 'email' => 'invalid@example.com', 'phone_no' => '9876500013', 'emp_code' => 'BULK-D']),
            ],
        ]);

        $response->assertStatus(422);
        // All-or-nothing — the valid row must not have been created either.
        $this->assertDatabaseMissing('users', ['email' => 'valid@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
    }

    public function test_store_with_no_rows_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/store', ['rows' => []]);

        $response->assertStatus(422);
    }
}
