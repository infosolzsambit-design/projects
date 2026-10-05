<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\EmailLog;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResetEvaluationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole((int) ((array) config('roles.super_admin_id'))[0]);
        Sanctum::actingAs($admin);
    }

    private function evaluatedSheet(array $attributes = []): AnswerSheet
    {
        $mapping = QuestionAnswerSheetMapping::factory()->create(['department_name' => 'Physics']);

        return AnswerSheet::factory()->create($attributes + [
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => User::factory()->create(['email' => 'teacher.reset@example.com'])->id,
            'barcode' => 'BC-1001',
            'marks' => 42,
            'evaluated_at' => now()->subHour(),
            'draft_marks' => 42,
            'draft_marks_breakdown' => ['1' => 42],
            'draft_annotations' => ['1' => [['type' => 'correct', 'point' => [10, 10]]]],
            'consumed_time' => 600,
            'evaluation_start_date' => now()->subDay(),
            'evaluation_end_date' => now()->addDay(),
        ]);
    }

    public function test_search_finds_a_sheet_by_barcode_or_subject_barcode(): void
    {
        $byBarcode = $this->evaluatedSheet(['barcode' => 'BC-1001']);
        $bySubject = $this->evaluatedSheet(['barcode' => 'OTHER', 'subject_barcode' => 'SB-2002']);
        $this->evaluatedSheet(['barcode' => 'UNRELATED']);

        $this->withApiKey()->getJson('/api/v1/reset-evaluation/search?barcode=BC-1001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byBarcode->id)
            ->assertJsonPath('data.0.has_evaluation', true)
            ->assertJsonPath('data.0.can_reset', true)
            ->assertJsonPath('data.0.department_name', 'Physics')
            ->assertJsonPath('data.0.evaluated_by.email', 'teacher.reset@example.com');

        $this->withApiKey()->getJson('/api/v1/reset-evaluation/search?barcode=SB-2002')
            ->assertOk()
            ->assertJsonPath('data.0.id', $bySubject->id);
    }

    public function test_search_requires_a_barcode(): void
    {
        $this->withApiKey()->getJson('/api/v1/reset-evaluation/search')->assertStatus(422);
    }

    public function test_reset_clears_every_evaluation_field_but_keeps_the_assignment(): void
    {
        $sheet = $this->evaluatedSheet();
        $teacherId = $sheet->teacher_id;

        $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")
            ->assertOk()
            ->assertJsonPath('data.has_evaluation', false);

        $sheet->refresh();
        $this->assertNull($sheet->marks);
        $this->assertNull($sheet->evaluated_at);
        $this->assertNull($sheet->draft_marks);
        $this->assertNull($sheet->draft_marks_breakdown);
        $this->assertNull($sheet->draft_annotations);
        $this->assertNull($sheet->consumed_time);
        $this->assertSame($teacherId, $sheet->teacher_id);
        $this->assertNotNull($sheet->evaluation_start_date);
    }

    public function test_reset_notifies_the_teacher(): void
    {
        $sheet = $this->evaluatedSheet();

        $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $sheet->teacher_id,
            'type' => \App\Models\UserNotification::EVALUATION_RESET,
            'title' => 'Evaluation reset',
            'link' => '/my-pending-courses',
            'read_at' => null,
        ]);
        $message = \App\Models\UserNotification::where('user_id', $sheet->teacher_id)->value('message');
        $this->assertStringContainsString('has been reset by the admin', $message);
        $this->assertStringContainsString((string) ($sheet->subject_barcode ?: $sheet->barcode), $message);
    }

    public function test_reset_emails_the_teacher(): void
    {
        $sheet = $this->evaluatedSheet();

        $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")->assertOk();

        $this->assertDatabaseCount('email_logs', 1);
        $this->assertDatabaseHas('email_logs', [
            'receiver_id' => $sheet->teacher_id,
            'type' => 'evaluation_reset',
            'is_sent' => true,
        ]);
        $log = EmailLog::where('type', 'evaluation_reset')->first();
        $this->assertStringContainsString('has been <strong>reset</strong>', $log->body);
        $this->assertStringContainsString((string) ($sheet->subject_barcode ?: $sheet->barcode), $log->body);
        $this->assertStringContainsString('Physics', $log->body);
    }

    public function test_a_refused_reset_sends_no_notification(): void
    {
        $sheet = $this->evaluatedSheet(['evaluation_start_date' => now()->subDays(3), 'evaluation_end_date' => now()->subDay()]);

        $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")->assertStatus(422);

        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertDatabaseCount('email_logs', 0);
    }

    public function test_reset_is_refused_outside_the_evaluation_window(): void
    {
        $ended = $this->evaluatedSheet(['evaluation_start_date' => now()->subDays(3), 'evaluation_end_date' => now()->subDay()]);
        $notStarted = $this->evaluatedSheet(['evaluation_start_date' => now()->addDay(), 'evaluation_end_date' => now()->addDays(3)]);

        foreach ([$ended, $notStarted] as $sheet) {
            $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")->assertStatus(422);
            $this->assertEquals(42, $sheet->fresh()->marks);
        }

        $this->withApiKey()->getJson('/api/v1/reset-evaluation/search?barcode=BC-1001')
            ->assertOk()
            ->assertJsonPath('data.0.can_reset', false);
    }

    public function test_reset_is_refused_when_there_is_nothing_to_reset(): void
    {
        $sheet = $this->evaluatedSheet([
            'marks' => null, 'evaluated_at' => null, 'draft_marks' => null,
            'draft_marks_breakdown' => null, 'draft_annotations' => null, 'consumed_time' => null,
        ]);

        $this->withApiKey()->postJson("/api/v1/reset-evaluation/{$sheet->id}/reset")->assertStatus(422);
    }
}
