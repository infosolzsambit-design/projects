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
            'type' => 'Theory',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'BCA-01')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('courses', ['code' => 'BCA-01']);
    }

    public function test_type_is_required_and_max_50_characters(): void
    {
        $this->actingAdmin();
        $payload = ['name' => 'Physics', 'code' => 'PHY101'];

        $this->withApiKey()->postJson('/api/v1/courses', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->withApiKey()->postJson('/api/v1/courses', $payload + ['type' => str_repeat('X', 51)])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->withApiKey()->postJson('/api/v1/courses', $payload + ['type' => 'T'])
            ->assertStatus(201)->assertJsonPath('data.type', 'T');
    }

    public function test_name_code_and_type_must_be_unique_together(): void
    {
        $this->actingAdmin();
        Course::factory()->create(['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Theory']);

        // Exact same combination (case-insensitive) → rejected on all three fields.
        $this->withApiKey()->postJson('/api/v1/courses', ['name' => 'physics', 'code' => 'phy101', 'type' => 'theory'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code', 'type'])
            ->assertJsonPath('errors.code.0', Course::DUPLICATE_MESSAGE);

        // Any one of the three different → allowed (same code is fine now).
        $this->withApiKey()->postJson('/api/v1/courses', ['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Practical'])->assertStatus(201);
        $this->withApiKey()->postJson('/api/v1/courses', ['name' => 'Physics', 'code' => 'PHY102', 'type' => 'Theory'])->assertStatus(201);
        $this->withApiKey()->postJson('/api/v1/courses', ['name' => 'Applied Physics', 'code' => 'PHY101', 'type' => 'Theory'])->assertStatus(201);
    }

    public function test_update_checks_the_combination_but_ignores_the_course_itself(): void
    {
        $this->actingAdmin();
        Course::factory()->create(['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Theory']);
        $practical = Course::factory()->create(['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Practical']);

        $this->withApiKey()->putJson("/api/v1/courses/{$practical->id}", ['name' => 'Physics', 'code' => 'PHY101', 'type' => 'Practical'])->assertOk();
        $this->withApiKey()->putJson("/api/v1/courses/{$practical->id}", ['type' => 'Theory'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'code', 'type']);
        $this->assertSame('Practical', $practical->fresh()->type);
    }

    public function test_restore_is_blocked_when_an_active_course_has_the_same_combination(): void
    {
        $this->actingAdmin();
        $old = Course::factory()->create(['name' => 'Chemistry', 'code' => 'CHE101', 'type' => 'P']);
        $old->delete();
        Course::factory()->create(['name' => 'Chemistry', 'code' => 'CHE101', 'type' => 'P']);

        $this->withApiKey()->postJson("/api/v1/courses/{$old->id}/restore")->assertStatus(422);
        $this->assertSoftDeleted('courses', ['id' => $old->id]);
    }

    public function test_a_soft_deleted_courses_code_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = Course::factory()->create(['code' => 'REUSE-01']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/courses', [
            'name' => 'Fresh Course',
            'code' => 'REUSE-01',
            'type' => 'Theory',
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
