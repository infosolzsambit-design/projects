<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_plain_authenticated_user_can_list_email_logs_with_no_permission_needed(): void
    {
        $this->actingUser();
        EmailLog::factory()->count(3)->create();

        $response = $this->withApiKey()->getJson('/api/v1/email-logs');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_index_includes_sender_and_receiver_names(): void
    {
        $this->actingUser();
        $sender = User::factory()->create(['name' => 'Admin One']);
        $receiver = User::factory()->create(['name' => 'Teacher One', 'email' => 'teacher-one@example.com']);
        EmailLog::factory()->create(['sender_id' => $sender->id, 'receiver_id' => $receiver->id]);

        $response = $this->withApiKey()->getJson('/api/v1/email-logs');

        $response->assertOk();
        $row = $response->json('data.items.0');
        $this->assertSame('Admin One', $row['sender_name']);
        $this->assertSame('Teacher One', $row['receiver_name']);
        $this->assertSame('teacher-one@example.com', $row['receiver_email']);
    }

    public function test_index_filters_by_search_against_subject_and_receiver(): void
    {
        $this->actingUser();
        $matching = EmailLog::factory()->create(['subject' => 'Answer Sheets Assigned — Data Structures']);
        EmailLog::factory()->create(['subject' => 'Something else entirely']);

        $response = $this->withApiKey()->getJson('/api/v1/email-logs?search=Data Structures');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$matching->id], $ids);
    }

    public function test_index_filters_by_type(): void
    {
        $this->actingUser();
        $assigned = EmailLog::factory()->create(['type' => 'answer_sheet_assigned']);
        EmailLog::factory()->create(['type' => 'issue_raised']);

        $response = $this->withApiKey()->getJson('/api/v1/email-logs?type=answer_sheet_assigned');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$assigned->id], $ids);
    }

    public function test_index_filters_by_is_sent(): void
    {
        $this->actingUser();
        $failed = EmailLog::factory()->unsent()->create();
        EmailLog::factory()->create(); // sent

        $response = $this->withApiKey()->getJson('/api/v1/email-logs?is_sent=no');

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertEquals([$failed->id], $ids);
    }

    public function test_types_returns_the_distinct_types_in_use(): void
    {
        $this->actingUser();
        EmailLog::factory()->create(['type' => 'answer_sheet_assigned']);
        EmailLog::factory()->create(['type' => 'answer_sheet_assigned']);
        EmailLog::factory()->create(['type' => 'issue_raised']);

        $response = $this->withApiKey()->getJson('/api/v1/email-logs/types');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['answer_sheet_assigned', 'issue_raised'], $response->json('data'));
    }

    public function test_resend_creates_a_new_log_row_and_leaves_the_original_untouched(): void
    {
        $this->actingUser();
        $original = EmailLog::factory()->unsent()->create(['subject' => 'Original Subject', 'body' => 'Original body']);

        $response = $this->withApiKey()->postJson("/api/v1/email-logs/{$original->id}/resend");

        $response->assertOk();
        $this->assertDatabaseHas('email_logs', ['id' => $original->id, 'is_sent' => false]);
        $newId = $response->json('data.id');
        $this->assertNotSame($original->id, $newId);
        $this->assertDatabaseHas('email_logs', [
            'id' => $newId,
            'receiver_id' => $original->receiver_id,
            'type' => $original->type,
            'subject' => 'Original Subject',
            'body' => 'Original body',
            'is_sent' => true,
        ]);
    }

    public function test_resend_uses_the_original_subject_and_body_when_none_given(): void
    {
        $this->actingUser();
        $original = EmailLog::factory()->unsent()->create(['subject' => 'Keep Me', 'body' => 'Keep this body too']);

        $response = $this->withApiKey()->postJson("/api/v1/email-logs/{$original->id}/resend", []);

        $response->assertOk();
        $response->assertJsonPath('data.subject', 'Keep Me');
        $response->assertJsonPath('data.body', 'Keep this body too');
    }

    public function test_resend_uses_an_edited_subject_and_body_when_given(): void
    {
        $this->actingUser();
        $original = EmailLog::factory()->unsent()->create(['subject' => 'Old Subject', 'body' => 'Old body']);

        $response = $this->withApiKey()->postJson("/api/v1/email-logs/{$original->id}/resend", [
            'subject' => 'Corrected Subject',
            'body' => 'Corrected body text',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.subject', 'Corrected Subject');
        $response->assertJsonPath('data.body', 'Corrected body text');
    }

    public function test_resend_rejects_an_already_sent_email(): void
    {
        $this->actingUser();
        $original = EmailLog::factory()->create(); // is_sent: true by default

        $response = $this->withApiKey()->postJson("/api/v1/email-logs/{$original->id}/resend");

        $response->assertStatus(422);
        $this->assertDatabaseCount('email_logs', 1); // no new attempt row created
    }

    public function test_resend_rejects_when_the_receiver_has_no_email(): void
    {
        $this->actingUser();
        $receiver = User::factory()->create(['email' => '']);
        $original = EmailLog::factory()->unsent()->create(['receiver_id' => $receiver->id]);

        $response = $this->withApiKey()->postJson("/api/v1/email-logs/{$original->id}/resend");

        $response->assertStatus(422);
    }
}
