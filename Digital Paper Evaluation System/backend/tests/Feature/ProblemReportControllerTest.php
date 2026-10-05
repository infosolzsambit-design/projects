<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\IssueMaster;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProblemReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ExamTerm $examTerm;

    private ExamType $examType;

    private QuestionPaper $paper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Super Admin']);
        Sanctum::actingAs($this->admin);
        $this->examTerm = ExamTerm::factory()->create(['name' => 'Even (2025-2026)']);
        $this->examType = ExamType::factory()->create(['name' => 'Mid Semester 2026']);
        $this->paper = QuestionPaper::factory()->create(['exam_year' => 2026]);
    }

    private function mapping(Course $course): QuestionAnswerSheetMapping
    {
        return QuestionAnswerSheetMapping::factory()->create([
            'program_name' => 'B.Sc',
            'course_id' => $course->id,
            'exam_term_id' => $this->examTerm->id,
            'exam_type_id' => $this->examType->id,
            'semester' => 1,
            'question_paper_id' => $this->paper->id,
        ]);
    }

    private function filters(array $overrides = []): string
    {
        return http_build_query(array_merge([
            'program_name' => 'B.Sc',
            'exam_term_id' => $this->examTerm->id,
            'exam_type_id' => $this->examType->id,
            'semester' => 1,
            'exam_year' => 2026,
        ], $overrides));
    }

    private function teacher(string $name, string $empCode): User
    {
        $user = User::factory()->create(['name' => $name]);
        TeacherDetail::factory()->create(['user_id' => $user->id, 'emp_code' => $empCode]);

        return $user;
    }

    public function test_index_requires_the_exam_filters_but_not_course(): void
    {
        $this->withApiKey()->getJson('/api/v1/reports/problem-report')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['program_name', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year'])
            ->assertJsonMissingValidationErrors(['course_id']);
    }

    public function test_index_lists_every_raised_issue_with_its_details(): void
    {
        $printing = IssueMaster::factory()->create(['name' => 'Printing Issue']);
        $course = Course::factory()->create(['name' => 'Environmental Science', 'code' => 'SCI501', 'type' => 'Theory']);
        $mapping = $this->mapping($course);
        $teacher = $this->teacher('Prof. Vikram Singh', 'EMP-0010');

        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => $teacher->id,
            'subject_barcode' => '1775390',
            'issue_master_id' => $printing->id,
            'issue_raised_by' => $teacher->id,
            'issue_raised_at' => '2026-09-28 11:35:00',
            'issue_status' => 'resolved',
            'issue_remarks' => 'Page 3 blank',
            'issue_fixed_by' => $this->admin->id,
            'issue_fixed_at' => '2026-09-28 12:00:00',
            'issue_admin_remarks' => 'Rescanned',
        ]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher->id]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters());

        $response->assertOk()->assertJsonCount(1, 'data.rows');
        $response->assertJsonPath('data.rows.0', [
            'teacher_name' => 'Prof. Vikram Singh',
            'emp_code' => 'EMP-0010',
            'program_name' => 'B.Sc',
            'course_name' => 'Environmental Science',
            'course_code' => 'SCI501',
            'course_type' => 'Theory',
            'semester' => 1,
            'exam_term_name' => 'Even (2025-2026)',
            'exam_type_name' => 'Mid Semester 2026',
            'exam_year' => 2026,
            'qr_code' => '1775390',
            'issue_type' => 'Printing Issue',
            'status' => 'Resolved',
            'teacher_remarks' => 'Page 3 blank',
            'issue_raised_at' => '28-09-2026 11:35 AM',
            'solved_by' => 'Super Admin',
            'solved_at' => '28-09-2026 12:00 PM',
            'admin_remarks' => 'Rescanned',
        ]);
    }

    public function test_course_and_teacher_filters_narrow_the_results(): void
    {
        $issue = IssueMaster::factory()->create();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        $teacherA = $this->teacher('Teacher A', 'A1');
        $teacherB = $this->teacher('Teacher B', 'B1');

        foreach ([[$courseA, $teacherA], [$courseB, $teacherA], [$courseB, $teacherB]] as [$course, $teacher]) {
            AnswerSheet::factory()->create([
                'question_answer_sheet_mapping_id' => $this->mapping($course)->id,
                'teacher_id' => $teacher->id,
                'issue_master_id' => $issue->id,
                'issue_raised_by' => $teacher->id,
                'issue_status' => 'open',
            ]);
        }

        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters())
            ->assertOk()->assertJsonCount(3, 'data.rows');
        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters(['course_id' => $courseB->id]))
            ->assertOk()->assertJsonCount(2, 'data.rows');
        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters(['course_id' => $courseB->id, 'teacher_id' => $teacherB->id]))
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.status', 'Open');
    }

    public function test_department_filter_narrows_the_results(): void
    {
        $issue = IssueMaster::factory()->create();
        $course = Course::factory()->create();
        $teacher = $this->teacher('Teacher A', 'A1');
        $physics = Department::factory()->create();
        $chemistry = Department::factory()->create();

        foreach ([$physics, $chemistry, $chemistry] as $department) {
            $mapping = $this->mapping($course);
            $mapping->update(['department_id' => $department->id, 'department_name' => $department->name]);
            AnswerSheet::factory()->create([
                'question_answer_sheet_mapping_id' => $mapping->id,
                'teacher_id' => $teacher->id,
                'issue_master_id' => $issue->id,
                'issue_raised_by' => $teacher->id,
                'issue_status' => 'open',
            ]);
        }

        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters())
            ->assertOk()->assertJsonCount(3, 'data.rows');
        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters(['department_id' => $chemistry->id]))
            ->assertOk()->assertJsonCount(2, 'data.rows');
        $this->withApiKey()->getJson('/api/v1/reports/problem-report?'.$this->filters(['department_id' => $physics->id]))
            ->assertOk()->assertJsonCount(1, 'data.rows');
    }

    public function test_export_downloads_an_excel_file(): void
    {
        $response = $this->withApiKey()->get('/api/v1/reports/problem-report/export?'.$this->filters());

        $response->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('problem_report.xls', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('PROBLEM REPORT', $response->getContent());
    }
}
