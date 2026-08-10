<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_course(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/courses', [
            'name' => 'Bachelor of Computer Applications',
            'code' => 'BCA-01',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'BCA-01')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('courses', ['code' => 'BCA-01']);
    }

    public function test_course_code_must_be_unique(): void
    {
        $this->actingAdmin();
        Course::factory()->create(['code' => 'DUPE-01']);

        $response = $this->withApiKey()->postJson('/api/v1/courses', [
            'name' => 'Another Course',
            'code' => 'DUPE-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_soft_deleted_courses_code_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Course::factory()->create(['code' => 'REUSE-01']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/courses', [
            'name' => 'Fresh Course',
            'code' => 'REUSE-01',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.code', 'REUSE-01');
        $this->assertDatabaseHas('courses', ['id' => $trashed->id, 'code' => 'REUSE-01']);
        $this->assertDatabaseHas('courses', ['name' => 'Fresh Course', 'code' => 'REUSE-01', 'deleted_at' => null]);
    }

    public function test_admin_can_update_a_course(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/courses/{$course->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_course(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/courses/{$course->id}");

        $response->assertOk();
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }

    public function test_soft_deleted_course_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $course->delete();

        $response = $this->withApiKey()->getJson('/api/v1/courses?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($course->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_course(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $course->delete();

        $response = $this->withApiKey()->postJson("/api/v1/courses/{$course->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'deleted_at' => null]);
    }

    public function test_cannot_delete_a_course_mapped_to_a_program(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $program = Program::factory()->create();
        $program->courses()->sync([$course->id]);

        $response = $this->withApiKey()->deleteJson("/api/v1/courses/{$course->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'deleted_at' => null]);
    }

    public function test_can_delete_a_course_once_it_is_unmapped_from_every_program(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $program = Program::factory()->create();
        $program->courses()->sync([$course->id]);
        $program->courses()->sync([]);

        $response = $this->withApiKey()->deleteJson("/api/v1/courses/{$course->id}");

        $response->assertOk();
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }

    public function test_can_delete_a_course_whose_only_mapped_program_is_soft_deleted(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $program = Program::factory()->create();
        $program->courses()->sync([$course->id]);
        $program->delete();

        $response = $this->withApiKey()->deleteJson("/api/v1/courses/{$course->id}");

        $response->assertOk();
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }
}
