<?php

namespace Tests\Feature;

use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamSubTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_admin_can_create_an_exam_sub_type(): void
    {
        $type = ExamType::factory()->create(['name' => 'Odd Sem']);
        $term = ExamTerm::factory()->create(['name' => 'Term 2025']);

        $this->withApiKey()->postJson('/api/v1/exam-sub-types', ['name' => 'Internal 1', 'exam_type_id' => $type->id, 'exam_term_id' => $term->id])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Internal 1')
            ->assertJsonPath('data.exam_type', 'Odd Sem')
            ->assertJsonPath('data.exam_term', 'Term 2025')
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('exam_sub_types', ['name' => 'Internal 1', 'exam_type_id' => $type->id, 'exam_term_id' => $term->id]);
    }

    public function test_name_term_and_type_are_required(): void
    {
        $this->withApiKey()->postJson('/api/v1/exam-sub-types', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'exam_type_id', 'exam_term_id']);
    }

    public function test_name_is_unique_per_term_and_type_only(): void
    {
        $existing = ExamSubType::factory()->create(['name' => 'Internal 1']);
        $otherType = ExamType::factory()->create();

        $this->withApiKey()->postJson('/api/v1/exam-sub-types', ['name' => 'Internal 1', 'exam_type_id' => $existing->exam_type_id, 'exam_term_id' => $existing->exam_term_id])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->withApiKey()->postJson('/api/v1/exam-sub-types', ['name' => 'Internal 1', 'exam_type_id' => $otherType->id, 'exam_term_id' => $existing->exam_term_id])
            ->assertStatus(201);

        // A soft-deleted one's name is free again.
        $existing->delete();
        $this->withApiKey()->postJson('/api/v1/exam-sub-types', ['name' => 'Internal 1', 'exam_type_id' => $existing->exam_type_id, 'exam_term_id' => $existing->exam_term_id])
            ->assertStatus(201);
    }

    public function test_admin_can_update_delete_and_restore(): void
    {
        $subType = ExamSubType::factory()->create(['name' => 'Old']);

        $this->withApiKey()->putJson("/api/v1/exam-sub-types/{$subType->id}", ['name' => 'New', 'status' => false])
            ->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.status', false);

        $this->withApiKey()->deleteJson("/api/v1/exam-sub-types/{$subType->id}")->assertOk();
        $this->assertSoftDeleted($subType);
        $this->withApiKey()->getJson('/api/v1/exam-sub-types')->assertOk()->assertJsonCount(0, 'data.items');

        $this->withApiKey()->postJson("/api/v1/exam-sub-types/{$subType->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($subType);
    }

    public function test_list_searches_type_name_and_filters_by_type(): void
    {
        $type = ExamType::factory()->create(['name' => 'Odd Sem']);
        ExamSubType::factory()->create(['name' => 'Internal 1', 'exam_type_id' => $type->id]);
        ExamSubType::factory()->create(['name' => 'Something Else']);

        $this->withApiKey()->getJson('/api/v1/exam-sub-types?search=odd')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Internal 1');

        $this->withApiKey()->getJson("/api/v1/exam-sub-types?status=all&exam_type_id={$type->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
