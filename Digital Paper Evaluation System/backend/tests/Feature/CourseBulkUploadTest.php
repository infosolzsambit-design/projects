<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseBulkUploadTest extends TestCase
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

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [
                ['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Theory'],
                ['name' => 'Chemistry', 'code' => 'CHEM101', 'type' => 'Theory'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
        $this->assertTrue($response->json('data.rows.0.valid'));
        $this->assertTrue($response->json('data.rows.1.valid'));
    }

    public function test_validate_flags_a_blank_name(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [['name' => '', 'code' => 'X101', 'type' => 'Theory']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Name is required.', $response->json('data.rows.0.errors.name'));
    }

    public function test_validate_flags_a_blank_code(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [['name' => 'Some Course', 'code' => '', 'type' => 'Theory']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', false);
        $this->assertSame('Code is required.', $response->json('data.rows.0.errors.code'));
    }

    public function test_validate_flags_a_blank_type(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [['name' => 'Some Course', 'code' => 'X9', 'type' => '']],
        ]);

        $response->assertOk();
        $this->assertSame('Type is required.', $response->json('data.rows.0.errors.type'));
    }

    public function test_validate_flags_the_same_name_code_and_type_twice_within_the_upload(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [
                ['name' => 'Course A', 'code' => 'DUPE', 'type' => 'Theory'],
                ['name' => 'course a', 'code' => 'dupe', 'type' => 'THEORY'],
                // Same name + code, different type → fine.
                ['name' => 'Course A', 'code' => 'DUPE', 'type' => 'Practical'],
            ],
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.rows.0.valid'));
        $this->assertFalse($response->json('data.rows.1.valid'));
        $this->assertTrue($response->json('data.rows.2.valid'));
        $this->assertStringContainsString('more than once', $response->json('data.rows.0.errors.code'));
    }

    public function test_validate_flags_a_combination_already_used_by_an_existing_course(): void
    {
        $this->actingAdmin();
        Course::factory()->create(['name' => 'New Course', 'code' => 'TAKEN101', 'type' => 'Theory']);

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [
                ['name' => 'New Course', 'code' => 'TAKEN101', 'type' => 'Theory'],
                ['name' => 'Other Course', 'code' => 'TAKEN101', 'type' => 'Theory'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(Course::DUPLICATE_MESSAGE, $response->json('data.rows.0.errors.type'));
        // Same code alone is no longer a duplicate.
        $this->assertTrue($response->json('data.rows.1.valid'));
    }

    public function test_validate_allows_a_code_matching_a_soft_deleted_course(): void
    {
        $this->actingAdmin();
        $trashed = Course::factory()->create(['code' => 'REUSE101']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/validate', [
            'rows' => [['name' => 'Fresh Course', 'code' => 'REUSE101', 'type' => 'Theory']],
        ]);

        $response->assertOk()->assertJsonPath('data.all_valid', true);
    }

    public function test_store_creates_every_row_when_all_valid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/store', [
            'rows' => [
                ['name' => 'Bulk Course One', 'code' => 'BC1', 'type' => 'Theory'],
                ['name' => 'Bulk Course Two', 'code' => 'BC2', 'type' => 'Theory'],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('courses', ['name' => 'Bulk Course One', 'code' => 'BC1', 'type' => 'Theory']);
        $this->assertDatabaseHas('courses', ['name' => 'Bulk Course Two', 'code' => 'BC2', 'type' => 'Theory']);
    }

    public function test_store_rejects_the_whole_batch_when_any_row_is_invalid(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/store', [
            'rows' => [
                ['name' => 'Valid Course', 'code' => 'OK1', 'type' => 'Theory'],
                ['name' => '', 'code' => 'OK2', 'type' => 'Theory'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('courses', ['name' => 'Valid Course']);
    }

    public function test_store_with_no_rows_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses/bulk/store', ['rows' => []]);

        $response->assertStatus(422);
    }
}
