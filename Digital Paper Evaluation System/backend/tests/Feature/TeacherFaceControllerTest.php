<?php

namespace Tests\Feature;

use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherFaceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_save_a_face_scan_for_a_teacher(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response->assertOk()->assertJsonPath('data.has_face_profile', true);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'photo' => 'data:image/jpeg;base64,AAAA']);
    }

    public function test_saving_a_face_scan_requires_exactly_128_numbers(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => [1, 2, 3],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('descriptor');
    }

    public function test_saving_a_face_scan_for_a_non_teacher_user_is_rejected(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$plainUser->id}/face", [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response->assertStatus(404);
    }

    public function test_a_face_scan_can_be_retaken(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,FIRST',
            'descriptor' => array_fill(0, 128, 0.1),
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,SECOND',
            'descriptor' => array_fill(0, 128, 0.9),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'photo' => 'data:image/jpeg;base64,SECOND']);
    }

    public function test_show_returns_the_stored_photo(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();
        $this->withApiKey()->postJson("/api/v1/teachers/{$detail->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,STORED',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/face");

        $response->assertOk()
            ->assertJsonPath('data.photo', 'data:image/jpeg;base64,STORED')
            ->assertJsonPath('data.has_face_profile', true);
    }

    public function test_show_fails_when_no_face_scan_is_registered_yet(): void
    {
        $this->actingAdmin();
        $detail = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$detail->user_id}/face");

        $response->assertStatus(422);
    }

    public function test_show_for_a_non_teacher_user_is_rejected(): void
    {
        $this->actingAdmin();
        $plainUser = User::factory()->create();

        $response = $this->withApiKey()->getJson("/api/v1/teachers/{$plainUser->id}/face");

        $response->assertStatus(404);
    }

    public function test_the_teachers_list_reports_has_face_profile_per_row(): void
    {
        $this->actingAdmin();
        $scanned = TeacherDetail::factory()->create();
        $this->withApiKey()->postJson("/api/v1/teachers/{$scanned->user_id}/face", [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);
        $unscanned = TeacherDetail::factory()->create();

        $response = $this->withApiKey()->getJson('/api/v1/teachers?per_page=100');

        $response->assertOk();
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->firstWhere('id', $scanned->user_id)['has_face_profile']);
        $this->assertFalse($items->firstWhere('id', $unscanned->user_id)['has_face_profile']);
    }
}
