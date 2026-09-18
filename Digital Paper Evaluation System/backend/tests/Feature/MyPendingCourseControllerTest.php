<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\EmailLog;
use App\Models\ExamType;
use App\Models\GeneralSetting;
use App\Models\IssueMaster;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use App\Models\Role;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyPendingCourseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeacher(): User
    {
        $teacher = User::factory()->create();
        Sanctum::actingAs($teacher);

        return $teacher;
    }

    // Lazily created once per test and reused by every mapping fixture
    // built in it — a non-super-admin (every actingTeacher() here) is
    // always scoped by HasExamTypeScope to whichever active exam type was
    // created most recently, so mappings a test doesn't care about exam
    // type for need to all share this one, or that new scope would
    // silently filter some of them out for an unrelated reason.
    private ?ExamType $defaultExamType = null;

    private function defaultExamTypeId(): int
    {
        return ($this->defaultExamType ??= ExamType::factory()->create())->id;
    }

    /**
     * Mints a fresh evaluation_session_token for $sheet the same way a
     * real "Start Evaluate" click does (see startEvaluation()) — must be
     * called while acting as the sheet's own teacher.
     */
    private function evaluationToken(AnswerSheet $sheet): string
    {
        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        return $response->json('data.evaluation_token');
    }

    public function test_subjects_groups_by_the_packets_actual_course_not_the_csv_subject_fields(): void
    {
        $teacher = $this->actingTeacher();
        $otherTeacher = User::factory()->create();

        $english = Course::factory()->create(['name' => 'Business Communication', 'code' => 'COM401']);
        $chemistry = Course::factory()->create(['name' => 'Chemistry', 'code' => 'CHEM25']);

        // Pinned to the current year, not left to the factory's random
        // 2020-2026 spread — subjects() is exam-year-scoped (see
        // HasExamYearScope) and this teacher isn't a super admin, so a
        // mismatched year would just make these sheets invisible instead
        // of testing what this test is actually about.
        $englishPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $chemistryPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $englishMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $english->id, 'question_paper_id' => $englishPaper->id]);
        $chemistryMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $chemistry->id, 'question_paper_id' => $chemistryPaper->id]);

        // subject_code/subject_name deliberately mismatched from the real
        // course name — mirrors the real-world CSV-vs-course mismatch this
        // fix addresses; grouping must still follow the course, not these.
        AnswerSheet::factory()->count(2)->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $englishMapping->id,
            'subject_code' => 'TIU-UMG-MJ-T32301',
            'subject_name' => 'Management Accounting',
            'marks' => null,
        ]);
        AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $chemistryMapping->id,
            'marks' => null,
        ]);
        // Already evaluated — must not count as pending.
        AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $englishMapping->id,
            'marks' => 18,
        ]);
        // Someone else's pending sheet — must not leak into this teacher's list.
        AnswerSheet::factory()->create([
            'teacher_id' => $otherTeacher->id,
            'question_answer_sheet_mapping_id' => $englishMapping->id,
            'marks' => null,
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses');

        $response->assertOk();
        $courses = collect($response->json('data'))->keyBy('course_id');
        $this->assertSame(2, $courses[$english->id]['pending_count']);
        $this->assertSame('Business Communication', $courses[$english->id]['course_name']);
        $this->assertSame(1, $courses[$chemistry->id]['pending_count']);
    }

    public function test_papers_lists_only_this_teachers_pending_sheets_for_the_given_course(): void
    {
        $teacher = $this->actingTeacher();

        $english = Course::factory()->create();
        $chemistry = Course::factory()->create();
        // Pinned to the current year — see the same note in the test
        // above; papers() is exam-year-scoped too.
        $englishPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $chemistryPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $englishMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $english->id, 'question_paper_id' => $englishPaper->id]);
        $chemistryMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $chemistry->id, 'question_paper_id' => $chemistryPaper->id]);

        $pending = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $englishMapping->id,
            'marks' => null,
        ]);
        AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $englishMapping->id,
            'marks' => 15,
        ]);
        AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'question_answer_sheet_mapping_id' => $chemistryMapping->id,
            'marks' => null,
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$english->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$pending->id], $ids);
    }

    /**
     * MyPendingCoursesView.vue disables "Start Evaluate"/"Continue
     * Evaluate" purely off this one flag — an open Printing Issue blocks
     * it (the physical sheet needs fixing first), an open Timing Issue
     * doesn't (only the schedule is wrong, not the sheet itself), and
     * neither does an already-resolved issue of either kind.
     */
    public function test_papers_flags_blocks_evaluation_only_for_an_open_printing_issue(): void
    {
        $teacher = $this->actingTeacher();
        $course = Course::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $paper->id]);

        $openPrinting = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null,
            'issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'open',
        ]);
        $openTiming = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null,
            'issue_master_id' => (int) config('issues.timing_issue_id'), 'issue_status' => 'open',
        ]);
        $resolvedPrinting = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null,
            'issue_master_id' => (int) config('issues.printing_issue_id'), 'issue_status' => 'resolved',
        ]);
        $noIssue = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null,
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$course->id);

        $response->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$openPrinting->id]['blocks_evaluation']);
        $this->assertFalse($byId[$openTiming->id]['blocks_evaluation']);
        $this->assertFalse($byId[$resolvedPrinting->id]['blocks_evaluation']);
        $this->assertFalse($byId[$noIssue->id]['blocks_evaluation']);
    }

    private function faceScanSetting(string $value): void
    {
        GeneralSetting::factory()->create(['field_name' => 'face_scan_applicable', 'value' => $value]);
    }

    public function test_start_evaluation_skips_the_scan_when_the_setting_is_globally_off(): void
    {
        $teacher = $this->actingTeacher();
        $this->faceScanSetting('no');
        TeacherDetail::factory()->create(['user_id' => $teacher->id, 'face_scan_applicable' => true]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        $response->assertOk()->assertJsonPath('data.face_scan_required', false);
    }

    public function test_start_evaluation_skips_the_scan_when_this_teacher_is_individually_exempt(): void
    {
        $teacher = $this->actingTeacher();
        $this->faceScanSetting('yes');
        TeacherDetail::factory()->create(['user_id' => $teacher->id, 'face_scan_applicable' => false]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        $response->assertOk()->assertJsonPath('data.face_scan_required', false);
    }

    public function test_start_evaluation_requires_the_scan_when_globally_on_and_this_teacher_is_not_exempt(): void
    {
        $teacher = $this->actingTeacher();
        $this->faceScanSetting('yes');
        TeacherDetail::factory()->create(['user_id' => $teacher->id, 'face_scan_applicable' => true, 'face_descriptor' => array_fill(0, 128, 0.1)]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        $response->assertOk()
            ->assertJsonPath('data.face_scan_required', true)
            ->assertJsonPath('data.has_face_profile', true);
    }

    public function test_start_evaluation_rejects_a_sheet_that_does_not_belong_to_this_teacher(): void
    {
        $this->actingTeacher();
        $otherTeacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $otherTeacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        $response->assertStatus(404);
    }

    public function test_start_evaluation_rejects_a_sheet_already_marked(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => 20]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/start-evaluation");

        $response->assertStatus(404);
    }

    public function test_show_includes_the_real_question_paper_structure(): void
    {
        $teacher = $this->actingTeacher();
        $paper = QuestionPaper::factory()->create(['full_marks' => 20]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'question_paper_id' => $paper->id]);
        $group = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => null,
            'label' => 'Group A', 'mode' => 'all', 'sort_order' => 1,
        ]);
        QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $group->id,
            'label' => 'Q1', 'mode' => 'leaf', 'marks' => 20, 'sort_order' => 1,
        ]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);
        $token = $this->evaluationToken($sheet);

        $response = $this->withApiKey()->getJson("/api/v1/my-pending-courses/evaluate/{$token}");

        $response->assertOk();
        $response->assertJsonPath('data.question_paper.groups.0.label', 'Group A');
        $response->assertJsonPath('data.question_paper.groups.0.children.0.label', 'Q1');
        $response->assertJsonPath('data.question_paper.groups.0.children.0.marks', 20);
    }

    public function test_show_by_token_rejects_an_unknown_token(): void
    {
        $this->actingTeacher();

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/evaluate/not-a-real-token');

        $response->assertStatus(404);
    }

    public function test_show_by_token_rejects_an_expired_token(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'marks' => null,
            'evaluation_session_token' => 'expired-token',
            'evaluation_session_expires_at' => now()->subMinute(),
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/evaluate/expired-token');

        $response->assertStatus(404);
    }

    public function test_show_by_token_rejects_a_token_for_someone_elses_sheet(): void
    {
        $this->actingTeacher();
        $otherTeacher = User::factory()->create();
        AnswerSheet::factory()->create([
            'teacher_id' => $otherTeacher->id,
            'marks' => null,
            'evaluation_session_token' => 'someone-elses-token',
            'evaluation_session_expires_at' => now()->addHour(),
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/evaluate/someone-elses-token');

        $response->assertStatus(404);
    }

    /**
     * Regression guard for the whole point of this token: a copied/
     * bookmarked evaluation link must stop working once a later
     * legitimate "Start Evaluate" click mints a fresh one for the same
     * sheet, not stay valid forever.
     */
    public function test_start_evaluation_issues_a_fresh_token_that_supersedes_the_previous_one(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $firstToken = $this->evaluationToken($sheet);
        $secondToken = $this->evaluationToken($sheet);

        $this->assertNotSame($firstToken, $secondToken);
        $this->withApiKey()->getJson("/api/v1/my-pending-courses/evaluate/{$firstToken}")->assertStatus(404);
        $this->withApiKey()->getJson("/api/v1/my-pending-courses/evaluate/{$secondToken}")->assertOk();
    }

    public function test_submit_marks_sets_the_sheets_marks(): void
    {
        $teacher = $this->actingTeacher();
        $paper = QuestionPaper::factory()->create(['full_marks' => 20]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'question_paper_id' => $paper->id]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/submit-marks", ['marks' => 15]);

        $response->assertOk()->assertJsonPath('data.marks', '15.00');
        $this->assertSame('15.00', $sheet->fresh()->marks);
    }

    public function test_submit_marks_rejects_more_than_the_question_papers_full_marks(): void
    {
        $teacher = $this->actingTeacher();
        $paper = QuestionPaper::factory()->create(['full_marks' => 20]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'question_paper_id' => $paper->id]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/submit-marks", ['marks' => 25]);

        $response->assertStatus(422)->assertJsonValidationErrors(['marks']);
    }

    public function test_submit_marks_rejects_a_sheet_already_marked(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => 10]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/submit-marks", ['marks' => 5]);

        $response->assertStatus(404);
        $this->assertSame('10.00', $sheet->fresh()->marks);
    }

    public function test_submit_marks_persists_consumed_time(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/submit-marks", ['marks' => 5, 'consumed_time' => 42]);

        $response->assertOk();
        $this->assertSame(42, $sheet->fresh()->consumed_time);
    }

    public function test_save_draft_persists_the_breakdown_annotations_and_consumed_time_without_marking_the_sheet_evaluated(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/save-draft", [
            'marks_breakdown' => ['338' => 4, '340' => 3],
            'annotations' => ['1' => [['type' => 'correct', 'point' => [10, 20]]]],
            'consumed_time' => 7,
        ]);

        $response->assertOk();

        $fresh = $sheet->fresh();
        $this->assertNull($fresh->marks);
        $this->assertSame('7.00', $fresh->draft_marks);
        $this->assertSame(['338' => 4, '340' => 3], $fresh->draft_marks_breakdown);
        $this->assertSame(7, $fresh->consumed_time);
        $this->assertNotEmpty($fresh->draft_annotations);
    }

    public function test_save_draft_rejects_a_sheet_already_marked(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => 12]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/save-draft", ['marks_breakdown' => ['1' => 5]]);

        $response->assertStatus(404);
    }

    public function test_save_draft_rejects_a_sheet_that_does_not_belong_to_this_teacher(): void
    {
        $this->actingTeacher();
        $otherTeacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $otherTeacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/save-draft", ['marks_breakdown' => ['1' => 5]]);

        $response->assertStatus(404);
    }

    public function test_raise_issue_flags_the_sheet_and_resets_its_evaluation_progress(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'marks' => null,
            'draft_marks' => 7,
            'draft_marks_breakdown' => ['1' => 4, '2' => 3],
            'draft_annotations' => ['1' => [['type' => 'correct']]],
            'consumed_time' => 120,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'Pages 3-4 are blank/unprinted.',
        ]);

        $response->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame((int) config('issues.printing_issue_id'), $fresh->issue_master_id);
        $this->assertSame($teacher->id, $fresh->issue_raised_by);
        $this->assertSame('open', $fresh->issue_status);
        $this->assertSame('Pages 3-4 are blank/unprinted.', $fresh->issue_remarks);
        $this->assertNotNull($fresh->issue_raised_at);
        $this->assertNull($fresh->marks);
        $this->assertNull($fresh->draft_marks);
        $this->assertNull($fresh->draft_marks_breakdown);
        $this->assertNull($fresh->draft_annotations);
        $this->assertNull($fresh->consumed_time);
    }

    /**
     * The locked "Problem" flow on EvaluatePaperView.vue never sends
     * issue_master_id at all — this is what makes that flow always land
     * on Printing Issue regardless of anything a tampered request adds.
     */
    public function test_raise_issue_defaults_to_the_printing_issue_id_when_none_is_sent(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'Blank pages.',
        ]);

        $response->assertOk();
        $this->assertSame((int) config('issues.printing_issue_id'), $sheet->fresh()->issue_master_id);
    }

    /**
     * MyPendingCoursesView.vue's own "Raise Issue" row action lets the
     * teacher genuinely pick the issue type (e.g. Timing Issue instead of
     * the default Printing Issue) — a real, active issue_masters id must
     * be honored, not silently overridden. Also the whole reason a
     * non-printing issue exists as its own path: unlike Printing Issue,
     * it must NOT reset marks/annotations/consumed time — the evaluation
     * window being wrong doesn't invalidate work already done.
     */
    public function test_raise_issue_honors_an_explicitly_selected_issue_type(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'marks' => null,
            'draft_marks' => 7,
            'draft_marks_breakdown' => ['1' => 4, '2' => 3],
            'draft_annotations' => ['1' => [['type' => 'correct']]],
            'consumed_time' => 120,
        ]);
        // Forced to the real well-known timing-issue id — a factory-default
        // auto-increment id could otherwise coincidentally land on 1 (the
        // well-known Printing Issue id) in a fresh test database with no
        // other issue_masters rows yet, silently passing this test for the
        // wrong reason.
        $timingIssue = IssueMaster::factory()->create([
            'id' => (int) config('issues.timing_issue_id'),
            'name' => 'Timing Issue',
            'status' => true,
        ]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'Timing window was wrong.',
            'issue_master_id' => $timingIssue->id,
        ]);

        $response->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame($timingIssue->id, $fresh->issue_master_id);
        $this->assertSame('7.00', $fresh->draft_marks);
        $this->assertSame(['1' => 4, '2' => 3], $fresh->draft_marks_breakdown);
        $this->assertNotEmpty($fresh->draft_annotations);
        $this->assertSame(120, $fresh->consumed_time);
    }

    public function test_raise_issue_rejects_an_issue_master_id_that_is_not_a_real_active_issue_type(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);
        $inactiveIssue = IssueMaster::factory()->inactive()->create();

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'x',
            'issue_master_id' => $inactiveIssue->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('issue_master_id');
    }

    public function test_raise_issue_requires_remarks(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", []);

        $response->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    public function test_raise_issue_rejects_a_sheet_already_marked(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => 12]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", ['remarks' => 'x']);

        $response->assertStatus(404);
    }

    public function test_raise_issue_rejects_a_sheet_that_does_not_belong_to_this_teacher(): void
    {
        $this->actingTeacher();
        $otherTeacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $otherTeacher->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", ['remarks' => 'x']);

        $response->assertStatus(404);
    }

    /**
     * dispatch(...)->afterResponse() still runs within a test (the test
     * kernel's terminate() is called), and MAIL_MAILER=array/
     * QUEUE_CONNECTION=sync in phpunit.xml means this runs synchronously
     * with no real network call — so this can assert the real end-to-end
     * effect (an EmailLog row per user in every
     * config('roles.admin_recived_issue_mail') role) instead of faking it.
     */
    public function test_raise_issue_emails_every_user_in_an_admin_recived_issue_mail_role(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);
        IssueMaster::factory()->create([
            'id' => (int) config('issues.printing_issue_id'),
            'name' => 'Printing Issue',
            'status' => true,
        ]);

        $adminRoleIds = (array) config('roles.admin_recived_issue_mail');
        $admins = collect($adminRoleIds)->map(function (int $roleId) {
            Role::forceCreate(['id' => $roleId, 'name' => "Role {$roleId}", 'guard_name' => config('auth.defaults.guard')]);
            $admin = User::factory()->create();
            $admin->assignRole($roleId);

            return $admin;
        });
        // A plain, non-admin user should never get this mail.
        $notAnAdmin = User::factory()->create();

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'Pages 3-4 are blank/unprinted.',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('email_logs', $admins->count());
        foreach ($admins as $admin) {
            $this->assertDatabaseHas('email_logs', [
                'sender_id' => $teacher->id,
                'receiver_id' => $admin->id,
                'type' => 'issue_raised',
                'is_sent' => true,
            ]);
        }
        $this->assertDatabaseMissing('email_logs', ['receiver_id' => $notAnAdmin->id]);
    }

    public function test_raise_issue_email_includes_the_teachers_emp_code_when_they_have_one(): void
    {
        $teacher = $this->actingTeacher();
        TeacherDetail::factory()->create(['user_id' => $teacher->id, 'emp_code' => 'EMP-9042']);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'marks' => null]);

        Role::forceCreate(['id' => 1, 'name' => 'Role 1', 'guard_name' => config('auth.defaults.guard')]);
        $admin = User::factory()->create();
        $admin->assignRole(1);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/raise-issue", [
            'remarks' => 'Pages 3-4 are blank/unprinted.',
        ]);

        $response->assertOk();
        $log = EmailLog::where('type', 'issue_raised')->where('receiver_id', $admin->id)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('EMP-9042', $log->body);
    }

    public function test_show_includes_draft_fields_for_a_resumed_session(): void
    {
        $teacher = $this->actingTeacher();
        $sheet = AnswerSheet::factory()->create([
            'teacher_id' => $teacher->id,
            'marks' => null,
            'draft_marks' => 7,
            'draft_marks_breakdown' => ['338' => 4, '340' => 3],
            'consumed_time' => 7,
        ]);
        $token = $this->evaluationToken($sheet);

        $response = $this->withApiKey()->getJson("/api/v1/my-pending-courses/evaluate/{$token}");

        $response->assertOk()
            ->assertJsonPath('data.draft_marks', '7.00')
            ->assertJsonPath('data.draft_marks_breakdown.338', 4)
            ->assertJsonPath('data.consumed_time', 7);
    }

    public function test_subjects_only_shows_the_current_exam_year_for_a_non_super_admin_by_default(): void
    {
        $teacher = $this->actingTeacher();

        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'question_paper_id' => $thisYearPaper->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $thisYearMapping->course_id, 'question_paper_id' => $lastYearPaper->id]);

        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id, 'marks' => null]);
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses');

        $response->assertOk();
        $this->assertSame(1, collect($response->json('data'))->firstWhere('course_id', $thisYearMapping->course_id)['pending_count']);
    }

    public function test_subjects_shows_every_exam_year_to_a_super_admin(): void
    {
        Role::forceCreate(['id' => ((array) config('roles.super_admin_id'))[0], 'name' => 'Super Admin', 'guard_name' => config('auth.defaults.guard')]);
        $admin = $this->actingTeacher();
        $admin->assignRole((int) ((array) config('roles.super_admin_id'))[0]);

        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'question_paper_id' => $thisYearPaper->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $thisYearMapping->course_id, 'question_paper_id' => $lastYearPaper->id]);

        AnswerSheet::factory()->create(['teacher_id' => $admin->id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id, 'marks' => null]);
        AnswerSheet::factory()->create(['teacher_id' => $admin->id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses');

        $response->assertOk();
        $this->assertSame(2, collect($response->json('data'))->firstWhere('course_id', $thisYearMapping->course_id)['pending_count']);
    }

    public function test_papers_only_shows_the_requested_exam_year_for_a_non_super_admin(): void
    {
        $teacher = $this->actingTeacher();
        $course = Course::factory()->create();

        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $thisYearPaper->id]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['exam_type_id' => $this->defaultExamTypeId(), 'course_id' => $course->id, 'question_paper_id' => $lastYearPaper->id]);

        $thisYearSheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $thisYearMapping->id, 'marks' => null]);
        AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $lastYearMapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/my-pending-courses/papers?course_id='.$course->id);

        $response->assertOk();
        $this->assertSame([$thisYearSheet->id], collect($response->json('data'))->pluck('id')->all());
    }
}
