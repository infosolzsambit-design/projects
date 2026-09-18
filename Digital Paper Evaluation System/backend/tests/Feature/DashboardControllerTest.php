<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamType;
use App\Models\Program;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\Student;
use App\Models\TeacherDetail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // Lazily created once per test and reused by every mappingThisYear()
    // call in it — a non-super-admin's own default HasExamTypeScope always
    // resolves to whichever active exam type was created most recently, so
    // every mapping this helper builds within one test needs to share the
    // exact same one (unless a test is deliberately exercising exam-type
    // scoping itself, which passes its own 'exam_type_id' override).
    private ?ExamType $defaultExamType = null;

    private function defaultExamTypeId(): int
    {
        return ($this->defaultExamType ??= ExamType::factory()->create())->id;
    }

    /**
     * @return array{mapping: QuestionAnswerSheetMapping}
     */
    private function mappingThisYear(array $overrides = []): array
    {
        $paper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(array_merge(
            ['question_paper_id' => $paper->id, 'exam_type_id' => $this->defaultExamTypeId()],
            $overrides,
        ));

        return ['mapping' => $mapping];
    }

    public function test_totals_counts_master_data_regardless_of_exam_year(): void
    {
        $this->actingUser();
        Program::factory()->count(2)->create();
        Course::factory()->count(3)->create();
        Department::factory()->count(1)->create();
        TeacherDetail::factory()->count(4)->create();
        Student::factory()->count(5)->create();
        QuestionPaper::factory()->count(2)->create();
        AnswerSheet::factory()->count(6)->create();

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $totals = $response->json('data.totals');
        // Each of these factories cascade-creates its own related rows
        // (an AnswerSheet pulls in a fresh QuestionAnswerSheetMapping,
        // which pulls in its own Course/QuestionPaper, ...), so the real
        // totals after all of the above run higher than the bare counts
        // requested — assert against the database's own actual count
        // rather than a number that assumes nothing else got created
        // along the way.
        $this->assertSame(Program::count(), $totals['programs']);
        $this->assertSame(Course::count(), $totals['courses']);
        $this->assertSame(Department::count(), $totals['departments']);
        $this->assertSame(TeacherDetail::count(), $totals['teachers']);
        $this->assertSame(Student::count(), $totals['students']);
        $this->assertSame(QuestionPaper::count(), $totals['question_papers']);
        $this->assertSame(AnswerSheet::count(), $totals['answer_sheets']);
        // But the explicit counts requested are still a real lower bound.
        $this->assertGreaterThanOrEqual(2, $totals['programs']);
        $this->assertGreaterThanOrEqual(3, $totals['courses']);
        $this->assertGreaterThanOrEqual(1, $totals['departments']);
        $this->assertGreaterThanOrEqual(4, $totals['teachers']);
        $this->assertGreaterThanOrEqual(5, $totals['students']);
        $this->assertGreaterThanOrEqual(2, $totals['question_papers']);
        $this->assertGreaterThanOrEqual(6, $totals['answer_sheets']);
    }

    public function test_workflow_counts_assignment_and_evaluation_state(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];
        $teacher = TeacherDetail::factory()->create()->user_id;

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => 15]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'issue_master_id' => 1, 'issue_status' => 'open']);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'issue_master_id' => 1, 'issue_status' => 'resolved']);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $workflow = $response->json('data.workflow');
        $this->assertSame(4, $workflow['assigned']);
        $this->assertSame(1, $workflow['pending_assignment']);
        $this->assertSame(1, $workflow['evaluated']);
        $this->assertSame(3, $workflow['pending_evaluation']);
        $this->assertSame(2, $workflow['raised_issues']);
        $this->assertSame(1, $workflow['pending_issues']);
    }

    public function test_evaluation_status_pending_evaluation_and_evaluated_always_sum_to_total(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];
        $teacher = TeacherDetail::factory()->create()->user_id;

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => null, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => 12]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $status = $response->json('data.evaluation_status');
        $this->assertSame(3, $status['total']);
        $this->assertSame(1, $status['evaluated']);
        $this->assertSame(2, $status['pending_evaluation']);
        $this->assertSame(1, $status['not_assigned']);
        $this->assertSame($status['total'], $status['evaluated'] + $status['pending_evaluation']);
    }

    public function test_evaluation_progress_buckets_by_assigned_at_and_evaluated_at(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];
        $teacher = TeacherDetail::factory()->create()->user_id;

        // This week.
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => $teacher,
            'assigned_at' => now(),
            'marks' => 10,
            'evaluated_at' => now(),
        ]);
        // Outside the 8-week window entirely — must not appear anywhere.
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => $teacher,
            'assigned_at' => Carbon::now()->subWeeks(20),
        ]);
        // Assigned but never evaluated (no evaluated_at at all).
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => $teacher,
            'assigned_at' => now(),
        ]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $progress = $response->json('data.evaluation_progress');
        $this->assertCount(8, $progress['weeks']);
        $this->assertSame('Week 8', $progress['weeks'][7]);
        $this->assertSame(2, array_sum($progress['assigned']));
        $this->assertSame(1, array_sum($progress['evaluated']));
        // Both assigned-this-week sheets land in the last bucket.
        $this->assertSame(2, $progress['assigned'][7]);
        $this->assertSame(1, $progress['evaluated'][7]);
    }

    public function test_department_progress_groups_by_the_mapped_programs_department(): void
    {
        $this->actingUser();
        $csDept = Department::factory()->create(['name' => 'Computer Science']);
        $mathDept = Department::factory()->create(['name' => 'Mathematics']);
        Program::factory()->create(['name' => 'B.Tech CSE', 'department_id' => $csDept->id]);
        Program::factory()->create(['name' => 'B.Sc Maths', 'department_id' => $mathDept->id]);

        $csMapping = $this->mappingThisYear(['program_name' => 'B.Tech CSE'])['mapping'];
        $mathMapping = $this->mappingThisYear(['program_name' => 'B.Sc Maths'])['mapping'];
        $teacher = TeacherDetail::factory()->create()->user_id;

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $csMapping->id, 'teacher_id' => $teacher, 'marks' => 10]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $csMapping->id, 'teacher_id' => $teacher, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mathMapping->id, 'teacher_id' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $rows = collect($response->json('data.department_progress'))->keyBy('name');
        $this->assertSame(2, $rows['Computer Science']['total']);
        $this->assertSame(50, $rows['Computer Science']['evaluated_pct']);
        $this->assertSame(1, $rows['Mathematics']['total']);
        $this->assertSame(100, $rows['Mathematics']['not_assigned_pct']);
        // The three shares always add up to exactly 100.
        foreach ($rows as $row) {
            $this->assertSame(100, $row['evaluated_pct'] + $row['pending_pct'] + $row['not_assigned_pct']);
        }
    }

    public function test_issue_summary_counts_raised_pending_and_resolved(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'issue_master_id' => 1, 'issue_status' => 'open']);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'issue_master_id' => 1, 'issue_status' => 'open']);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'issue_master_id' => 1, 'issue_status' => 'resolved']);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id]); // no issue

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $response->assertJsonPath('data.issue_summary.total_raised', 3);
        $response->assertJsonPath('data.issue_summary.pending', 2);
        $response->assertJsonPath('data.issue_summary.resolved', 1);
    }

    public function test_teacher_workload_lists_the_busiest_teachers_first(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];
        $busy = TeacherDetail::factory()->create(['user_id' => User::factory()->create(['name' => 'Busy Teacher'])->id, 'emp_code' => 'EMP-0099'])->user_id;
        $quiet = TeacherDetail::factory()->create(['user_id' => User::factory()->create(['name' => 'Quiet Teacher'])->id])->user_id;

        AnswerSheet::factory()->count(3)->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $busy, 'marks' => 10]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $busy, 'marks' => null, 'issue_master_id' => 1]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $quiet, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $rows = $response->json('data.teacher_workload');
        $this->assertSame('Busy Teacher', $rows[0]['name']);
        $this->assertSame('EMP-0099', $rows[0]['emp_code']);
        $this->assertSame(4, $rows[0]['assigned']);
        $this->assertSame(3, $rows[0]['evaluated']);
        $this->assertSame(1, $rows[0]['pending']);
        $this->assertSame(1, $rows[0]['issues']);
        $this->assertSame('Quiet Teacher', $rows[1]['name']);
    }

    public function test_course_pending_excludes_courses_with_nothing_pending(): void
    {
        $this->actingUser();
        $pendingCourse = Course::factory()->create(['name' => 'Has Pending Work', 'code' => 'HPW-101']);
        $doneCourse = Course::factory()->create(['name' => 'Fully Evaluated']);
        $pendingMapping = $this->mappingThisYear(['course_id' => $pendingCourse->id])['mapping'];
        $doneMapping = $this->mappingThisYear(['course_id' => $doneCourse->id])['mapping'];

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $pendingMapping->id, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $pendingMapping->id, 'marks' => 10]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $doneMapping->id, 'marks' => 10]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        $rows = collect($response->json('data.course_pending'))->keyBy('name');
        $this->assertArrayHasKey('Has Pending Work', $rows);
        $this->assertArrayNotHasKey('Fully Evaluated', $rows);
        $this->assertSame('HPW-101', $rows['Has Pending Work']['code']);
    }

    public function test_course_pending_has_more_flag_and_the_see_all_endpoint_returns_every_row(): void
    {
        $this->actingUser();
        // COURSE_LIMIT is 5 — 6 pending courses means the summary caps at
        // 5 with has_more true, and the dedicated endpoint returns all 6.
        for ($i = 1; $i <= 6; $i++) {
            $course = Course::factory()->create(['name' => "Course {$i}"]);
            $mapping = $this->mappingThisYear(['course_id' => $course->id])['mapping'];
            AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);
        }

        $summary = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');
        $summary->assertOk();
        $this->assertCount(5, $summary->json('data.course_pending'));
        $this->assertTrue($summary->json('data.course_pending_has_more'));

        $full = $this->withApiKey()->getJson('/api/v1/dashboard/course-pending');
        $full->assertOk();
        $this->assertCount(6, $full->json('data'));
    }

    public function test_teacher_workload_has_more_flag_and_the_see_all_endpoint_returns_every_row(): void
    {
        $this->actingUser();
        $mapping = $this->mappingThisYear()['mapping'];
        // TEACHER_LIMIT is 5 — 6 teachers with at least one assigned sheet
        // means the summary caps at 5 with has_more true.
        for ($i = 1; $i <= 6; $i++) {
            $teacher = TeacherDetail::factory()->create()->user_id;
            AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher]);
        }

        $summary = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');
        $summary->assertOk();
        $this->assertCount(5, $summary->json('data.teacher_workload'));
        $this->assertTrue($summary->json('data.teacher_workload_has_more'));

        $full = $this->withApiKey()->getJson('/api/v1/dashboard/teacher-workload');
        $full->assertOk();
        $this->assertCount(6, $full->json('data'));
    }

    public function test_department_progress_has_more_flag_and_the_see_all_endpoint_returns_every_row(): void
    {
        $this->actingUser();
        // DEPARTMENT_LIMIT is 8 — 9 departments with activity means the
        // summary caps at 8 with has_more true.
        for ($i = 1; $i <= 9; $i++) {
            $department = Department::factory()->create(['name' => "Department {$i}"]);
            $program = Program::factory()->create(['name' => "Program {$i}", 'department_id' => $department->id]);
            $mapping = $this->mappingThisYear(['program_name' => $program->name])['mapping'];
            AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id]);
        }

        $summary = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');
        $summary->assertOk();
        $this->assertCount(8, $summary->json('data.department_progress'));
        $this->assertTrue($summary->json('data.department_progress_has_more'));

        $full = $this->withApiKey()->getJson('/api/v1/dashboard/department-progress');
        $full->assertOk();
        $this->assertCount(9, $full->json('data'));
    }

    public function test_answer_sheet_scoped_sections_are_scoped_to_the_current_exam_year_for_a_non_super_admin(): void
    {
        $this->seed(RoleSeeder::class);
        $plainUser = User::factory()->create();
        Sanctum::actingAs($plainUser);

        $thisYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year]);
        $lastYearPaper = QuestionPaper::factory()->create(['exam_year' => now()->year - 1]);
        // This test only exercises the exam-year scope, so both mappings
        // share the same exam type (see defaultExamTypeId()'s own
        // docblock) — otherwise the new exam-type scope would silently
        // filter one of them out for an unrelated reason.
        $thisYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $thisYearPaper->id, 'exam_type_id' => $this->defaultExamTypeId()]);
        $lastYearMapping = QuestionAnswerSheetMapping::factory()->create(['question_paper_id' => $lastYearPaper->id, 'exam_type_id' => $this->defaultExamTypeId()]);

        AnswerSheet::factory()->count(2)->create(['question_answer_sheet_mapping_id' => $thisYearMapping->id]);
        AnswerSheet::factory()->count(5)->create(['question_answer_sheet_mapping_id' => $lastYearMapping->id]);

        $response = $this->withApiKey()->getJson('/api/v1/dashboard/admin-summary');

        $response->assertOk();
        // Row 1 stays an all-time grand total (7 answer sheets total)...
        $response->assertJsonPath('data.totals.answer_sheets', 7);
        // ...but every answer-sheet-activity section is scoped to this year alone.
        $status = $response->json('data.evaluation_status');
        $this->assertSame(2, $status['total']);
    }
}
