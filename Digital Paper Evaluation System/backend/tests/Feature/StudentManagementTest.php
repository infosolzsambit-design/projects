<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_a_student(): void
    {
        $this->actingAdmin();
        Program::factory()->create(['name' => 'B.Tech in Computer Science Engineering']);

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Rahul Kumar',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 3,
            'program_name' => 'B.Tech in Computer Science Engineering',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Rahul Kumar')
            ->assertJsonPath('data.semester', 3)
            ->assertJsonPath('data.program_name', 'B.Tech in Computer Science Engineering')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('students', ['name' => 'Rahul Kumar', 'semester' => 3]);
    }

    public function test_program_name_must_match_an_existing_program(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Rahul Kumar',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 3,
            'program_name' => 'Made Up Program That Does Not Exist',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('program_name');
    }

    public function test_program_name_matching_a_soft_deleted_program_is_rejected(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['name' => 'Trashed Program']);
        $program->delete();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Rahul Kumar',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 3,
            'program_name' => 'Trashed Program',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('program_name');
    }

    public function test_roll_no_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Priya Singh',
            'semester' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('roll_no');
    }

    public function test_roll_no_must_be_unique(): void
    {
        $this->actingAdmin();
        Student::factory()->create(['roll_no' => 'TAKEN-1']);

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Priya Singh',
            'roll_no' => 'TAKEN-1',
            'semester' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('roll_no');
    }

    public function test_roll_no_matching_a_soft_deleted_student_is_allowed(): void
    {
        $this->actingAdmin();
        $trashed = Student::factory()->create(['roll_no' => 'REUSE-1']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Priya Singh',
            'roll_no' => 'REUSE-1',
            'semester' => 1,
        ]);

        $response->assertStatus(201);
    }

    public function test_name_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_semester_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Priya Singh',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('semester');
    }

    public function test_semester_must_be_an_integer(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Priya Singh',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => '3rd Semester',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('semester');
    }

    public function test_program_name_is_optional(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Amit Verma',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 2,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.program_name', null);
    }

    /**
     * Deliberately not unique — multiple students can share the same name,
     * unlike departments.name.
     */
    public function test_multiple_students_can_share_the_same_name(): void
    {
        $this->actingAdmin();
        Student::factory()->create(['name' => 'Rahul Kumar']);

        $response = $this->withApiKey()->postJson('/api/v1/students', [
            'name' => 'Rahul Kumar',
            'roll_no' => 'RN-' . uniqid(),
            'semester' => 5,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, Student::where('name', 'Rahul Kumar')->count());
    }

    public function test_admin_can_update_a_student(): void
    {
        $this->actingAdmin();
        $student = Student::factory()->create(['semester' => 1]);

        $response = $this->withApiKey()->putJson("/api/v1/students/{$student->id}", [
            'semester' => 2,
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.semester', 2)
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_a_student(): void
    {
        $this->actingAdmin();
        $student = Student::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_soft_deleted_student_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $student = Student::factory()->create();
        $student->delete();

        $response = $this->withApiKey()->getJson('/api/v1/students?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($student->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_student(): void
    {
        $this->actingAdmin();
        $student = Student::factory()->create();
        $student->delete();

        $response = $this->withApiKey()->postJson("/api/v1/students/{$student->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_student_by_id_query_param(): void
    {
        $this->actingAdmin();
        $student = Student::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/students?id={$student->id}");

        $response->assertOk()->assertJsonPath('data.id', $student->id);
    }
}
