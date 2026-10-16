<?php

namespace Tests\Feature;

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

    // No department — a teacher's departments are set afterwards on the
    // Teacher form (or by "Sync Teacher").
    private function row(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Row Teacher',
            'email' => 'row.teacher@example.com',
            'phone_no' => '9876511111',
            'emp_code' => 'BULK-0001',
            'designation' => 'Lecturer',
        ], $overrides);
    }

    public function test_validate_reports_every_valid_row_as_valid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row(['email' => 'a@example.com', 'phone_no' => '9876500001', 'emp_code' => 'BULK-0001']),
                $this->row(['email' => 'b@example.com', 'phone_no' => '9876500002', 'emp_code' => 'BULK-0002']),
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
                ['name' => '', 'email' => '', 'phone_no' => '', 'emp_code' => '', 'designation' => ''],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $errors = $response->json('data.rows.0.errors');
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('phone_no', $errors);
        $this->assertArrayHasKey('emp_code', $errors);
        $this->assertArrayHasKey('designation', $errors);
    }

    public function test_validate_flags_duplicate_email_within_the_same_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row(['email' => 'same@example.com', 'phone_no' => '9876500003', 'emp_code' => 'BULK-0003']),
                $this->row(['email' => 'same@example.com', 'phone_no' => '9876500004', 'emp_code' => 'BULK-0004']),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate email within this upload.', $response->json('data.rows.0.errors.email'));
        $this->assertSame('Duplicate email within this upload.', $response->json('data.rows.1.errors.email'));
    }

    public function test_validate_flags_duplicate_emp_code_within_the_same_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [
                $this->row(['email' => 'x@example.com', 'phone_no' => '9876500005', 'emp_code' => 'SAME-CODE']),
                $this->row(['email' => 'y@example.com', 'phone_no' => '9876500006', 'emp_code' => 'SAME-CODE']),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Duplicate employee code within this upload.', $response->json('data.rows.0.errors.emp_code'));
    }

    public function test_validate_flags_an_email_already_used_by_an_existing_user(): void
    {
        $this->actingAdmin();
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [$this->row(['email' => 'existing@example.com'])],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This email is already in use.', $response->json('data.rows.0.errors.email'));
    }

    public function test_validate_flags_an_emp_code_already_used_by_an_existing_teacher(): void
    {
        $this->actingAdmin();
        TeacherDetail::factory()->create(['emp_code' => 'TAKEN-1']);

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [$this->row(['emp_code' => 'TAKEN-1'])],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('This employee code is already in use.', $response->json('data.rows.0.errors.emp_code'));
    }

    public function test_a_department_column_is_not_needed_or_checked(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/validate', [
            'rows' => [$this->row()],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
        $this->assertArrayNotHasKey('department_id', $response->json('data.rows.0.errors'));
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/store', [
            'rows' => [
                $this->row(['name' => 'Teacher One', 'email' => 'one@example.com', 'phone_no' => '9876500010', 'emp_code' => 'BULK-A']),
                $this->row(['name' => 'Teacher Two', 'email' => 'two@example.com', 'phone_no' => '9876500011', 'emp_code' => 'BULK-B']),
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('users', ['email' => 'one@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'two@example.com']);
        $this->assertDatabaseHas('teacher_details', ['emp_code' => 'BULK-A']);
        $this->assertDatabaseHas('teacher_details', ['emp_code' => 'BULK-B']);
        $this->assertSame(0, \DB::table('teacher_department_mappings')->count()); // departments are set afterwards

        $user = User::where('email', 'one@example.com')->first();
        $this->assertTrue($user->hasRole('Teacher'));
        $this->assertNull($user->password);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/teachers/bulk/store', [
            'rows' => [
                $this->row(['email' => 'valid@example.com', 'phone_no' => '9876500012', 'emp_code' => 'BULK-C']),
                $this->row(['name' => '', 'email' => 'invalid@example.com', 'phone_no' => '9876500013', 'emp_code' => 'BULK-D']),
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
