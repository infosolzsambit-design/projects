<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
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
        $department = Department::factory()->create(['name' => 'Engineering']);

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Bachelor of Technology',
            'department_id' => $department->id,
            'code' => 'BTECH-01',
            'course_ids' => [$course->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'BTECH-01')
            ->assertJsonPath('data.department', 'Engineering')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.courses.0.id', $course->id);

        $this->assertDatabaseHas('programs', ['code' => 'BTECH-01', 'department' => 'Engineering', 'department_id' => $department->id]);
        $programId = $response->json('data.id');
        $this->assertDatabaseHas('program_course_mappings', ['program_id' => $programId, 'course_id' => $course->id]);
    }

    public function test_department_id_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'No Department Program',
            'code' => 'NODEPT-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_department_id_must_reference_an_existing_non_deleted_department(): void
    {
        $this->actingAdmin();
        $trashedDepartment = Department::factory()->create();
        $trashedDepartment->delete();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Bad Department Program',
            'department_id' => $trashedDepartment->id,
            'code' => 'BADDEPT-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_a_program_can_be_created_without_any_course_ids(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'No Courses Program',
            'department_id' => $department->id,
            'code' => 'NOCOURSE-01',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.courses', []);
        $programId = $response->json('data.id');
        $this->assertDatabaseMissing('program_course_mappings', ['program_id' => $programId]);
    }

    public function test_creating_a_program_with_a_nonexistent_course_id_is_rejected(): void
    {
        $this->actingAdmin();
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Bad Course Program',
            'department_id' => $department->id,
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
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Trashed Course Program',
            'department_id' => $department->id,
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

    public function test_updating_a_program_with_an_empty_course_ids_array_clears_its_courses(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $program = Program::factory()->create();
        $program->courses()->sync([$course->id]);

        $response = $this->withApiKey()->putJson("/api/v1/programs/{$program->id}", [
            'course_ids' => [],
        ]);

        $response->assertOk()->assertJsonPath('data.courses', []);
        $this->assertDatabaseMissing('program_course_mappings', ['program_id' => $program->id, 'course_id' => $course->id]);
    }

    public function test_admin_can_change_a_programs_department_on_update(): void
    {
        $this->actingAdmin();
        $science = Department::factory()->create(['name' => 'Science']);
        $arts = Department::factory()->create(['name' => 'Arts']);
        $program = Program::factory()->create(['department' => 'Science', 'department_id' => $science->id]);

        $response = $this->withApiKey()->putJson("/api/v1/programs/{$program->id}", [
            'department_id' => $arts->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.department', 'Arts')
            ->assertJsonPath('data.department_id', $arts->id);
        $this->assertDatabaseHas('programs', ['id' => $program->id, 'department' => 'Arts', 'department_id' => $arts->id]);
    }

    public function test_program_code_must_be_unique(): void
    {
        $this->actingAdmin();
        Program::factory()->create(['code' => 'DUPE-01']);
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Another Program',
            'department_id' => $department->id,
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
        $department = Department::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/programs', [
            'name' => 'Fresh Program',
            'department_id' => $department->id,
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
