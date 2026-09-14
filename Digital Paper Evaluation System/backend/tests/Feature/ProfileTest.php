<?php

namespace Tests\Feature;

use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPlainUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsTeacher(): User
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        return $detail->user;
    }

    public function test_show_returns_the_logged_in_users_own_profile(): void
    {
        $user = $this->actingAsPlainUser();

        $response = $this->withApiKey()->getJson('/api/v1/profile');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_show_for_a_plain_user_has_no_teacher_only_fields(): void
    {
        $this->actingAsPlainUser();

        $response = $this->withApiKey()->getJson('/api/v1/profile');

        $response->assertOk()
            ->assertJsonPath('data.gender', null)
            ->assertJsonPath('data.emp_code', null)
            ->assertJsonPath('data.designation', null)
            ->assertJsonPath('data.has_face_profile', false);
    }

    public function test_a_user_can_update_their_own_name(): void
    {
        $this->actingAsPlainUser();

        $response = $this->withApiKey()->putJson('/api/v1/profile', ['name' => 'Updated Name']);

        $response->assertOk()->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_a_teacher_can_update_gender_location_and_about(): void
    {
        $this->actingAsTeacher();

        $response = $this->withApiKey()->putJson('/api/v1/profile', [
            'gender' => 'female',
            'location' => 'Kolkata, India',
            'about' => 'Experienced evaluator.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.location', 'Kolkata, India')
            ->assertJsonPath('data.about', 'Experienced evaluator.');
    }

    public function test_gender_must_be_a_valid_enum_value(): void
    {
        $this->actingAsTeacher();

        $response = $this->withApiKey()->putJson('/api/v1/profile', ['gender' => 'not-a-real-value']);

        $response->assertStatus(422)->assertJsonValidationErrors('gender');
    }

    public function test_updating_gender_for_a_plain_user_with_no_teacher_detail_is_a_silent_no_op(): void
    {
        $this->actingAsPlainUser();

        $response = $this->withApiKey()->putJson('/api/v1/profile', ['gender' => 'male']);

        $response->assertOk()->assertJsonPath('data.gender', null);
    }

    public function test_a_teacher_can_save_a_face_scan(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $descriptor = array_fill(0, 128, 0.5);
        $response = $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => $descriptor,
        ]);

        $response->assertOk()->assertJsonPath('data.has_face_profile', true);
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'photo' => 'data:image/jpeg;base64,AAAA']);
    }

    public function test_saving_a_face_scan_requires_exactly_128_numbers(): void
    {
        $this->actingAsTeacher();

        $response = $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => [1, 2, 3],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('descriptor');
    }

    public function test_saving_a_face_scan_for_a_plain_user_is_rejected(): void
    {
        $this->actingAsPlainUser();

        $response = $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response->assertStatus(422);
    }

    public function test_verify_face_reports_a_match_for_an_identical_descriptor(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $descriptor = array_fill(0, 128, 0.5);
        $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => $descriptor,
        ]);

        $response = $this->withApiKey()->postJson('/api/v1/profile/face/verify', ['descriptor' => $descriptor]);

        $response->assertOk()->assertJsonPath('data.matched', true)->assertJsonPath('data.distance', 0);
    }

    public function test_verify_face_reports_no_match_for_a_very_different_descriptor(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.1),
        ]);

        $response = $this->withApiKey()->postJson('/api/v1/profile/face/verify', [
            'descriptor' => array_fill(0, 128, 5.0),
        ]);

        $response->assertOk()->assertJsonPath('data.matched', false);
    }

    public function test_verify_face_fails_when_no_face_profile_is_registered_yet(): void
    {
        $this->actingAsTeacher();

        $response = $this->withApiKey()->postJson('/api/v1/profile/face/verify', [
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response->assertStatus(422);
    }

    public function test_a_teacher_can_upload_an_esign(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $response = $this->withApiKey()->postJson('/api/v1/profile/esign', [
            'esign' => 'data:image/png;base64,AAAA',
        ]);

        $response->assertOk()->assertJsonPath('data.esign', 'data:image/png;base64,AAAA');
        $this->assertDatabaseHas('teacher_details', ['id' => $detail->id, 'esign' => 'data:image/png;base64,AAAA']);
    }

    public function test_uploading_an_esign_for_a_plain_user_is_rejected(): void
    {
        $this->actingAsPlainUser();

        $response = $this->withApiKey()->postJson('/api/v1/profile/esign', [
            'esign' => 'data:image/png;base64,AAAA',
        ]);

        $response->assertStatus(422);
    }

    public function test_esign_is_required(): void
    {
        $this->actingAsTeacher();

        $response = $this->withApiKey()->postJson('/api/v1/profile/esign', []);

        $response->assertStatus(422)->assertJsonValidationErrors('esign');
    }

    public function test_esign_is_included_in_show_once_uploaded(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $this->withApiKey()->postJson('/api/v1/profile/esign', ['esign' => 'data:image/png;base64,AAAA']);

        $response = $this->withApiKey()->getJson('/api/v1/profile');

        $response->assertOk()->assertJsonPath('data.esign', 'data:image/png;base64,AAAA');
    }

    public function test_the_stored_face_descriptor_is_never_exposed_via_show(): void
    {
        $detail = TeacherDetail::factory()->create();
        Sanctum::actingAs($detail->user);

        $this->withApiKey()->postJson('/api/v1/profile/face', [
            'photo' => 'data:image/jpeg;base64,AAAA',
            'descriptor' => array_fill(0, 128, 0.5),
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/profile');

        $response->assertOk();
        $this->assertArrayNotHasKey('face_descriptor', $response->json('data'));
    }
}
