<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\EmailLog;
use App\Models\IssueMaster;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_every_raised_issue_most_recent_first(): void
    {
        $this->actingUser();
        $teacher = User::factory()->create();
        $issue = IssueMaster::factory()->create(['name' => 'Printing Issue']);

        $older = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'issue_master_id' => $issue->id,
            'issue_raised_by' => $teacher->id,
            'issue_status' => 'open',
            'issue_remarks' => 'Blank pages.',
            'issue_raised_at' => now()->subHour(),
        ]);
        $newer = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'issue_master_id' => $issue->id,
            'issue_raised_by' => $teacher->id,
            'issue_status' => 'open',
            'issue_remarks' => 'Smudged ink.',
            'consumed_time' => 245,
            'issue_raised_at' => now(),
        ]);
        // No issue raised — must not appear.
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id]);

        $response = $this->withApiKey()->getJson('/api/v1/notifications');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('answer_sheet_id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertSame('Printing Issue', $response->json('data.0.issue_name'));
        $this->assertSame($teacher->name, $response->json('data.0.raised_by'));
        $this->assertSame(245, $response->json('data.0.consumed_time'));
    }

    /**
     * Regression guard for the actual ordering rule — status takes
     * priority over id, not the other way around: an older *open* issue
     * must still sort ahead of a *resolved* one raised more recently.
     */
    public function test_index_orders_open_issues_before_resolved_ones_regardless_of_id(): void
    {
        $this->actingUser();
        $issue = IssueMaster::factory()->create();

        $olderOpen = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'open']);
        $newerResolved = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'resolved']);
        $newestOpen = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'open']);

        $response = $this->withApiKey()->getJson('/api/v1/notifications');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('answer_sheet_id')->all();
        $this->assertSame([$newestOpen->id, $olderOpen->id, $newerResolved->id], $ids);
    }

    public function test_unresolved_count_returns_only_open_issues(): void
    {
        $this->actingUser();
        $issue = IssueMaster::factory()->create();
        AnswerSheet::factory()->count(2)->create(['issue_master_id' => $issue->id, 'issue_status' => 'open']);
        AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'resolved']);
        AnswerSheet::factory()->create(); // no issue at all

        $response = $this->withApiKey()->getJson('/api/v1/notifications/unresolved-count');

        $response->assertOk()->assertJsonPath('data.count', 2);
    }

    public function test_index_returns_an_empty_list_when_nothing_has_been_raised(): void
    {
        $this->actingUser();
        AnswerSheet::factory()->create();

        $response = $this->withApiKey()->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_search_matches_unique_number_course_remarks_or_raised_by(): void
    {
        $this->actingUser();
        $teacher = User::factory()->create(['name' => 'Priya Sharma']);
        $issue = IssueMaster::factory()->create();
        $course = Course::factory()->create(['name' => 'Business Communication', 'code' => 'COM401']);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $course->id]);

        $match = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $mapping->id,
            'issue_master_id' => $issue->id,
            'issue_raised_by' => $teacher->id,
            'issue_status' => 'open',
            'subject_barcode' => 'BC-7788',
        ]);
        $noMatch = AnswerSheet::factory()->create([
            'issue_master_id' => $issue->id,
            'issue_status' => 'open',
            'subject_barcode' => 'BC-0000',
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/notifications?search=7788');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('answer_sheet_id')->all();
        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($noMatch->id, $ids);
    }

    public function test_index_filters_by_issue_type_and_status(): void
    {
        $this->actingUser();
        $printingOpen = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'open',
        ]);
        $printingResolved = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'resolved',
        ]);
        $timingOpen = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'), 'issue_status' => 'open',
        ]);

        $printingResponse = $this->withApiKey()->getJson('/api/v1/notifications?issue_type=printing');
        $this->assertEqualsCanonicalizing(
            [$printingOpen->id, $printingResolved->id],
            collect($printingResponse->json('data'))->pluck('answer_sheet_id')->all(),
        );

        $openResponse = $this->withApiKey()->getJson('/api/v1/notifications?status=open');
        $this->assertEqualsCanonicalizing(
            [$printingOpen->id, $timingOpen->id],
            collect($openResponse->json('data'))->pluck('answer_sheet_id')->all(),
        );

        $bothResponse = $this->withApiKey()->getJson('/api/v1/notifications?issue_type=timing&status=open');
        $this->assertSame([$timingOpen->id], collect($bothResponse->json('data'))->pluck('answer_sheet_id')->all());
    }

    public function test_index_filters_by_raised_date_range(): void
    {
        $this->actingUser();
        $issue = IssueMaster::factory()->create();
        $tooEarly = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'open', 'issue_raised_at' => '2026-09-01 10:00:00']);
        $inRange = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'open', 'issue_raised_at' => '2026-09-10 10:00:00']);
        $tooLate = AnswerSheet::factory()->create(['issue_master_id' => $issue->id, 'issue_status' => 'open', 'issue_raised_at' => '2026-09-20 10:00:00']);

        $response = $this->withApiKey()->getJson('/api/v1/notifications?raised_from=2026-09-05&raised_to=2026-09-15');

        $ids = collect($response->json('data'))->pluck('answer_sheet_id')->all();
        $this->assertSame([$inRange->id], $ids);
        $this->assertNotContains($tooEarly->id, $ids);
        $this->assertNotContains($tooLate->id, $ids);
    }

    public function test_resolve_timing_issue_updates_the_schedule_and_marks_it_resolved(): void
    {
        $admin = $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'),
            'issue_status' => 'open',
            'evaluation_start_date' => '2026-09-10 09:00:00',
            'evaluation_end_date' => '2026-09-10 18:00:00',
            'evaluation_time_per_sheet' => 5,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-timing-issue", [
            'evaluation_start_date' => '2026-09-16 09:00:00',
            'evaluation_end_date' => '2026-09-17 18:00:00',
            'evaluation_time_per_sheet' => 10,
            'remarks' => 'Extended the window.',
        ]);

        $response->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame('2026-09-16 09:00:00', $fresh->evaluation_start_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-17 18:00:00', $fresh->evaluation_end_date->format('Y-m-d H:i:s'));
        $this->assertSame(10, $fresh->evaluation_time_per_sheet);
        $this->assertSame('resolved', $fresh->issue_status);
        $this->assertSame($admin->id, $fresh->issue_fixed_by);
        $this->assertNotNull($fresh->issue_fixed_at);
        $this->assertSame('Extended the window.', $fresh->issue_admin_remarks);
    }

    /**
     * The whole point of this rule: an admin can push a missed window
     * forward, never backdate one that's already passed.
     */
    public function test_resolve_timing_issue_rejects_a_start_date_earlier_than_the_sheets_current_start_date(): void
    {
        $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'),
            'issue_status' => 'open',
            'evaluation_start_date' => '2026-09-10 09:00:00',
            'evaluation_end_date' => '2026-09-10 18:00:00',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-timing-issue", [
            'evaluation_start_date' => '2026-09-05 09:00:00',
            'evaluation_end_date' => '2026-09-20 18:00:00',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('evaluation_start_date');
    }

    public function test_resolve_timing_issue_rejects_an_end_date_that_is_not_after_the_start_date(): void
    {
        $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'),
            'issue_status' => 'open',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-timing-issue", [
            'evaluation_start_date' => '2026-09-16 09:00:00',
            'evaluation_end_date' => '2026-09-16 09:00:00',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('evaluation_end_date');
    }

    public function test_resolve_timing_issue_rejects_a_sheet_without_an_open_timing_issue(): void
    {
        $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'),
            'issue_status' => 'open',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-timing-issue", [
            'evaluation_start_date' => '2026-09-16 09:00:00',
            'evaluation_end_date' => '2026-09-17 09:00:00',
        ]);

        $response->assertStatus(404);
    }

    public function test_resolve_printing_issue_replaces_the_pdf_and_marks_it_resolved(): void
    {
        Storage::fake('public');
        $admin = $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'),
            'issue_status' => 'open',
            'subject_barcode' => 'BC-9001',
            'pdf_name' => 'old.pdf',
            'pdf_path' => '/storage/answer-sheets/1/old.pdf',
        ]);
        Storage::disk('public')->put('answer-sheets/1/old.pdf', 'old contents');

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-printing-issue", [
            'pdf' => UploadedFile::fake()->create('rescanned.pdf', 100, 'application/pdf'),
            'qr_code' => 'BC-9001',
            'remarks' => 'Rescanned and re-uploaded.',
        ]);

        $response->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame('resolved', $fresh->issue_status);
        $this->assertSame($admin->id, $fresh->issue_fixed_by);
        $this->assertNotNull($fresh->issue_fixed_at);
        $this->assertSame('Rescanned and re-uploaded.', $fresh->issue_admin_remarks);
        $this->assertNotSame('/storage/answer-sheets/1/old.pdf', $fresh->pdf_path);
        Storage::disk('public')->assertMissing('answer-sheets/1/old.pdf');
    }

    public function test_resolve_printing_issue_rejects_a_mismatched_qr_code(): void
    {
        Storage::fake('public');
        $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'),
            'issue_status' => 'open',
            'subject_barcode' => 'BC-9001',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-printing-issue", [
            'pdf' => UploadedFile::fake()->create('rescanned.pdf', 100, 'application/pdf'),
            'qr_code' => 'SOME-OTHER-BARCODE',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('qr_code');
        $this->assertSame('open', $sheet->fresh()->issue_status);
    }

    public function test_resolve_printing_issue_rejects_a_sheet_without_an_open_printing_issue(): void
    {
        Storage::fake('public');
        $this->actingUser();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'),
            'issue_status' => 'open',
            'subject_barcode' => 'BC-9001',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-printing-issue", [
            'pdf' => UploadedFile::fake()->create('rescanned.pdf', 100, 'application/pdf'),
            'qr_code' => 'BC-9001',
        ]);

        $response->assertStatus(404);
    }

    /**
     * dispatch(...)->afterResponse() still runs within a test (the test
     * kernel's terminate() is called), and MAIL_MAILER=array/
     * QUEUE_CONNECTION=sync in phpunit.xml means this runs synchronously
     * with no real network call — so this can assert the real end-to-end
     * effect (an EmailLog row to the teacher who raised it) instead of
     * faking it, same as the raise-issue tests.
     */
    public function test_resolve_timing_issue_emails_the_teacher_who_raised_it(): void
    {
        $admin = $this->actingUser();
        $teacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.timing_issue_id'),
            'issue_status' => 'open',
            'issue_raised_by' => $teacher->id,
            'evaluation_start_date' => '2026-09-10 09:00:00',
            'evaluation_end_date' => '2026-09-10 18:00:00',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-timing-issue", [
            'evaluation_start_date' => '2026-09-16 09:00:00',
            'evaluation_end_date' => '2026-09-17 18:00:00',
            'remarks' => 'Extended the window.',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('email_logs', 1);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $teacher->id,
            'type' => 'issue_resolved',
            'is_sent' => true,
        ]);
        $log = EmailLog::where('type', 'issue_resolved')->first();
        $this->assertStringContainsString('Extended the window.', $log->body);
    }

    public function test_resolve_printing_issue_emails_the_teacher_who_raised_it(): void
    {
        Storage::fake('public');
        $admin = $this->actingUser();
        $teacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create([
            'issue_master_id' => (int) config('issues.printing_issue_id'),
            'issue_status' => 'open',
            'issue_raised_by' => $teacher->id,
            'subject_barcode' => 'BC-9001',
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/notifications/{$sheet->id}/resolve-printing-issue", [
            'pdf' => UploadedFile::fake()->create('rescanned.pdf', 100, 'application/pdf'),
            'qr_code' => 'BC-9001',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('email_logs', 1);
        $this->assertDatabaseHas('email_logs', [
            'sender_id' => $admin->id,
            'receiver_id' => $teacher->id,
            'type' => 'issue_resolved',
            'is_sent' => true,
        ]);
    }
}
