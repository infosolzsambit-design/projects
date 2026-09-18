<?php

namespace Tests\Feature;

use App\Models\ExamType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_an_exam_type(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/exam-types', [
            'name' => 'Regular',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Regular')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('exam_types', ['name' => 'Regular']);
    }

    public function test_name_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/exam-types', []);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_exam_type_name_must_be_unique(): void
    {
        $this->actingAdmin();
        ExamType::factory()->create(['name' => 'Backlog']);

        $response = $this->withApiKey()->postJson('/api/v1/exam-types', [
            'name' => 'Backlog',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_soft_deleted_exam_types_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = ExamType::factory()->create(['name' => 'Supplementary']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/exam-types', [
            'name' => 'Supplementary',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Supplementary');
        $this->assertDatabaseHas('exam_types', ['id' => $trashed->id, 'name' => 'Supplementary']);
        $this->assertDatabaseHas('exam_types', ['name' => 'Supplementary', 'deleted_at' => null]);
    }

    public function test_admin_can_update_an_exam_type(): void
    {
        $this->actingAdmin();
        $examType = ExamType::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/exam-types/{$examType->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_an_exam_type(): void
    {
        $this->actingAdmin();
        $examType = ExamType::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/exam-types/{$examType->id}");

        $response->assertOk();
        $this->assertSoftDeleted('exam_types', ['id' => $examType->id]);
    }

    public function test_soft_deleted_exam_type_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $examType = ExamType::factory()->create();
        $examType->delete();

        $response = $this->withApiKey()->getJson('/api/v1/exam-types?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($examType->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_exam_type(): void
    {
        $this->actingAdmin();
        $examType = ExamType::factory()->create();
        $examType->delete();

        $response = $this->withApiKey()->postJson("/api/v1/exam-types/{$examType->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('exam_types', ['id' => $examType->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_exam_type_by_id_query_param(): void
    {
        $this->actingAdmin();
        $examType = ExamType::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/exam-types?id={$examType->id}");

        $response->assertOk()->assertJsonPath('data.id', $examType->id);
    }
}
