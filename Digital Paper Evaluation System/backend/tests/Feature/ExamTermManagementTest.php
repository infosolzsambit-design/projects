<?php

namespace Tests\Feature;

use App\Models\ExamTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTermManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_an_exam_term(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/exam-terms', [
            'name' => 'Autumn 2026',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Autumn 2026')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('exam_terms', ['name' => 'Autumn 2026']);
    }

    public function test_name_is_required(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/exam-terms', []);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_exam_term_name_must_be_unique(): void
    {
        $this->actingAdmin();
        ExamTerm::factory()->create(['name' => 'Spring 2026']);

        $response = $this->withApiKey()->postJson('/api/v1/exam-terms', [
            'name' => 'Spring 2026',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_soft_deleted_exam_terms_name_can_be_reused(): void
    {
        $this->actingAdmin();
        $trashed = ExamTerm::factory()->create(['name' => 'Winter 2025']);
        $trashed->delete();

        $response = $this->withApiKey()->postJson('/api/v1/exam-terms', [
            'name' => 'Winter 2025',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Winter 2025');
        $this->assertDatabaseHas('exam_terms', ['id' => $trashed->id, 'name' => 'Winter 2025']);
        $this->assertDatabaseHas('exam_terms', ['name' => 'Winter 2025', 'deleted_at' => null]);
    }

    public function test_admin_can_update_an_exam_term(): void
    {
        $this->actingAdmin();
        $examTerm = ExamTerm::factory()->create(['name' => 'Old Name']);

        $response = $this->withApiKey()->putJson("/api/v1/exam-terms/{$examTerm->id}", [
            'name' => 'New Name',
            'status' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.status', false);
    }

    public function test_admin_can_soft_delete_an_exam_term(): void
    {
        $this->actingAdmin();
        $examTerm = ExamTerm::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/exam-terms/{$examTerm->id}");

        $response->assertOk();
        $this->assertSoftDeleted('exam_terms', ['id' => $examTerm->id]);
    }

    public function test_soft_deleted_exam_term_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $examTerm = ExamTerm::factory()->create();
        $examTerm->delete();

        $response = $this->withApiKey()->getJson('/api/v1/exam-terms?per_page=100');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($examTerm->id, $ids);
    }

    public function test_admin_can_restore_a_soft_deleted_exam_term(): void
    {
        $this->actingAdmin();
        $examTerm = ExamTerm::factory()->create();
        $examTerm->delete();

        $response = $this->withApiKey()->postJson("/api/v1/exam-terms/{$examTerm->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('exam_terms', ['id' => $examTerm->id, 'deleted_at' => null]);
    }

    public function test_can_fetch_a_single_exam_term_by_id_query_param(): void
    {
        $this->actingAdmin();
        $examTerm = ExamTerm::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/exam-terms?id={$examTerm->id}");

        $response->assertOk()->assertJsonPath('data.id', $examTerm->id);
    }
}
