<?php

namespace Tests\Feature;

use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherEsignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_save_an_esign_for_a_teacher(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/esign", [
            'esign' => 'data:image/png;base64,AAAA',
        ]);

        $response->assertOk()->assertJsonPath('data.has_esign', true);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'esign' => 'data:image/png;base64,AAAA']);
    }

    public function test_saving_an_esign_requires_the_field(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/esign", []);

        $response->assertStatus(422)->assertJsonValidationErrors('esign');
    }

    public function test_saving_an_esign_for_a_non_teacher_user_is_rejected(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$plainUser->id}/esign", [
            'esign' => 'data:image/png;base64,AAAA',
        ]);

        $response->assertStatus(404);
    }

    public function test_an_esign_can_be_replaced(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/esign", [
            'esign' => 'data:image/png;base64,FIRST',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/esign", [
            'esign' => 'data:image/png;base64,SECOND',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'esign' => 'data:image/png;base64,SECOND']);
    }

    public function test_show_returns_the_stored_esign(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/esign", [
            'esign' => 'data:image/png;base64,STORED',
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/esign");

        $response->assertOk()->assertJsonPath('data.esign', 'data:image/png;base64,STORED');
    }

    public function test_show_returns_null_when_no_esign_is_registered_yet(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/esign");

        $response->assertOk()->assertJsonPath('data.esign', null);
    }

    public function test_show_for_a_non_teacher_user_is_rejected(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$plainUser->id}/esign");

        $response->assertStatus(404);
    }

    public function test_the_teachers_list_reports_has_esign_per_row(): void
    {
        $this->actingAdmin();
        $signed = TeacherDetail::factory()->create();
        $this->withApiKey()->postJson("/api/v1/teachers/{$signed->user_id}/esign", [
            'esign' => 'data:image/png;base64,AAAA',
        ]);
        $unsigned = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->getJson('/api/v1/teachers?per_page=100');

        $response->assertOk();
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->firstWhere('id', $signed->user_id)['has_esign']);
        $this->assertFalse($items->firstWhere('id', $unsigned->user_id)['has_esign']);
    }
}
