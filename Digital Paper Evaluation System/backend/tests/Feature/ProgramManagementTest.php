<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_program_with_mapped_courses(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Bachelor of Technology',
            'department' => 'Engineering',
            'code' => 'BTECH-01',
            'course_ids' => [$course->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'BTECH-01')
            ->assertJsonPath('data.department', 'Engineering')
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.courses.0.id', $course->id);

        $this->assertDatabaseHas('programs', ['code' => 'BTECH-01']);
        $programId = $response->json('data.id');
        $this->assertDatabaseHas('program_course_mappings', ['program_id' => $programId, 'course_id' => $course->id]);
    }

    public function test_creating_a_program_without_course_ids_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'No Courses Program',
            'department' => 'Engineering',
            'code' => 'NOCOURSE-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('course_ids');
    }

    public function test_creating_a_program_with_a_nonexistent_course_id_is_rejected(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Bad Course Program',
            'department' => 'Engineering',
            'code' => 'BADCOURSE-01',
            'course_ids' => [999999],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('course_ids.0');
    }

    public function test_creating_a_program_with_a_soft_deleted_course_id_is_rejected(): void
    {
        $this->actingAdmin();
        $trashedCourse = Course::factory()->create();
        $trashedCourse->delete();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Trashed Course Program',
            'department' => 'Engineering',
            'code' => 'TRASHCOURSE-01',
            'course_ids' => [$trashedCourse->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('course_ids.0');
    }

    public function test_admin_can_resync_a_programs_courses_on_update(): void
    {
        $this->actingAdmin();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        $courseC = Course::factory()->create();

        $program = Program::factory()->create();
        $program->courses()->sync([$courseA->id, $courseB->id]);

        $response = $this->withApiKey()->putJson("/api/v1/programs/{$program->id}", [
            'course_ids' => [$courseC->id],
        ]);

        $response->assertOk();
        $courseIds = collect($response->json('data.courses'))->pluck('id')->all();
        $this->assertSame([$courseC->id], $courseIds);
        $this->assertDatabaseMissing('program_course_mappings', ['program_id' => $program->id, 'course_id' => $courseA->id]);
        $this->assertDatabaseHas('program_course_mappings', ['program_id' => $program->id, 'course_id' => $courseC->id]);
    }

    public function test_updating_a_program_with_an_empty_course_ids_array_is_rejected(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/programs/{$program->id}", [
            'course_ids' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('course_ids');
    }

    public function test_program_code_must_be_unique(): void
    {
        $this->actingAdmin();
        Program::factory()->create(['code' => 'DUPE-01']);

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Another Program',
            'department' => 'Science',
            'code' => 'DUPE-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_soft_deleted_programs_code_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Program::factory()->create(['code' => 'REUSE-01']);
        $trashed->delete();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Fresh Program',
            'department' => 'Arts',
            'code' => 'REUSE-01',
            'course_ids' => [$course->id],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.code', 'REUSE-01');
        $this->assertDatabaseHas('programs', ['name' => 'Fresh Program', 'code' => 'REUSE-01', 'deleted_at' => null]);
    }

    public function test_admin_can_update_a_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/programs/{$program->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/programs/{$program->id}");

        $response->assertOk();
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
    }

    public function test_soft_deleted_program_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $program->delete();

        $response = $this->withApiKey()->getJson('/api/v1/programs?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($program->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $program->delete();

        $response = $this->withApiKey()->postJson("/api/v1/programs/{$program->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('programs', ['id' => $program->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_program_by_id_query_param(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/programs?id={$program->id}");

        $response->assertOk()->assertJsonPath('data.id', $program->id);
    }
}
