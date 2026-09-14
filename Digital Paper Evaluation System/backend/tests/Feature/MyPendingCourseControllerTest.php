<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\GeneralSetting;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
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

    public function test_subjects_groups_by_the_packets_actual_course_not_the_csv_subject_fields(): void
    {
        $teacher = $this->actingTeacher();
        $otherTeacher = User::factory()->create();

        $english = Course::factory()->create(['name' => 'Business Communication', 'code' => 'COM401']);
        $chemistry = Course::factory()->create(['name' => 'Chemistry', 'code' => 'CHEM25']);

        $englishMapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $english->id]);
        $chemistryMapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $chemistry->id]);

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
        $englishMapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $english->id]);
        $chemistryMapping = QuestionAnswerSheetMapping::factory()->create(['course_id' => $chemistry->id]);

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
        $mapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $paper->id]);
        $group = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => null,
            'label' => 'Group A', 'mode' => 'all', 'sort_order' => 1,
        ]);
        QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $group->id,
            'label' => 'Q1', 'mode' => 'leaf', 'marks' => 20, 'sort_order' => 1,
        ]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson("/api/v1/my-pending-courses/papers/{$sheet->id}");

        $response->assertOk();
        $response->assertJsonPath('data.question_paper.groups.0.label', 'Group A');
        $response->assertJsonPath('data.question_paper.groups.0.children.0.label', 'Q1');
        $response->assertJsonPath('data.question_paper.groups.0.children.0.marks', 20);
    }

    public function test_show_rejects_a_sheet_that_does_not_belong_to_this_teacher(): void
    {
        $this->actingTeacher();
        $otherTeacher = User::factory()->create();
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $otherTeacher->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson("/api/v1/my-pending-courses/papers/{$sheet->id}");

        $response->assertStatus(404);
    }

    public function test_submit_marks_sets_the_sheets_marks(): void
    {
        $teacher = $this->actingTeacher();
        $paper = QuestionPaper::factory()->create(['full_marks' => 20]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $paper->id]);
        $sheet = AnswerSheet::factory()->create(['teacher_id' => $teacher->id, 'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->postJson("/api/v1/my-pending-courses/papers/{$sheet->id}/submit-marks", ['marks' => 15]);

        $response->assertOk()->assertJsonPath('data.marks', '15.00');
        $this->assertSame('15.00', $sheet->fresh()->marks);
    }

    public function test_submit_marks_rejects_more_than_the_question_papers_full_marks(): void
    {
        $teacher = $this->actingTeacher();
        $paper = QuestionPaper::factory()->create(['full_marks' => 20]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $paper->id]);
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

        $response = $this->withApiKey()->getJson("/api/v1/my-pending-courses/papers/{$sheet->id}");

        $response->assertOk()
            ->assertJsonPath('data.draft_marks', '7.00')
            ->assertJsonPath('data.draft_marks_breakdown.338', 4)
            ->assertJsonPath('data.consumed_time', 7);
    }
}
