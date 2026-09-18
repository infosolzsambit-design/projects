<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherWiseEvaluationReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{mapping: QuestionAnswerSheetMapping, filters: array<string, mixed>}
     */
    private function searchableMapping(array $overrides = []): array
    {
        $course = Course::factory()->create(['name' => 'Data Structures', 'code' => 'CS-101']);
        $examTerm = ExamTerm::factory()->create();
        $examType = ExamType::factory()->create(['name' => 'Regular']);
        $paper = QuestionPaper::factory()->create(['exam_year' => 2026]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(array_merge([
            'program_name' => 'B.Tech Computer Science',
            'course_id' => $course->id,
            'exam_term_id' => $examTerm->id,
            'exam_type_id' => $examType->id,
            'semester' => 6,
            'question_paper_id' => $paper->id,
        ], $overrides));

        return [
            'mapping' => $mapping,
            'filters' => [
                'program_name' => 'B.Tech Computer Science',
                'course_id' => $course->id,
                'exam_term_id' => $examTerm->id,
                'exam_type_id' => $examType->id,
                'semester' => 6,
                'exam_year' => 2026,
            ],
        ];
    }

    public function test_index_requires_all_required_filters(): void
    {
        $this->actingUser();

        $response = $this->withApiKey()->getJson('/api/v1/reports/teacher-wise-evaluation');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year']);
    }

    public function test_index_returns_one_row_per_teacher_and_packet_matching_the_search(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $teacher = TeacherDetail::factory()->create([
            'user_id' => User::factory()->create(['name' => 'Dr. Sarah Khan', 'phone_no' => '9876543210'])->id,
            'emp_code' => 'EMP-0042',
        ])->user_id;

        AnswerSheet::factory()->count(3)->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => 10]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => null, 'issue_master_id' => 1]);
        // Unassigned sheet in the same packet — must not appear as its own row.
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/teacher-wise-evaluation?'.http_build_query($filters));

        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('Dr. Sarah Khan', $rows[0]['teacher_name']);
        $this->assertSame('EMP-0042', $rows[0]['emp_code']);
        $this->assertSame('9876543210', $rows[0]['mobile_no']);
        $this->assertSame('Regular', $rows[0]['exam_type_name']);
        $this->assertSame('Data Structures', $rows[0]['subject_name']);
        $this->assertSame('CS-101', $rows[0]['subject_code']);
        $this->assertSame(6, $rows[0]['semester']);
        $this->assertSame(4, $rows[0]['allotted_script']);
        $this->assertSame(3, $rows[0]['total_evaluated']);
        $this->assertSame(1, $rows[0]['total_problem_script']);
        $this->assertSame(1, $rows[0]['total_pending']);
    }

    public function test_index_excludes_packets_outside_the_searched_exam_offering(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $otherMapping = $this->searchableMapping(['program_name' => 'B.Sc Physics'])['mapping'];
        $teacher = TeacherDetail::factory()->create()->user_id;

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $otherMapping->id, 'teacher_id' => $teacher]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/teacher-wise-evaluation?'.http_build_query($filters));

        $response->assertOk();
        $this->assertCount(1, $response->json('data.rows'));
    }

    public function test_index_filters_to_one_teacher_when_teacher_id_given(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $teacherA = TeacherDetail::factory()->create(['user_id' => User::factory()->create(['name' => 'Teacher A'])->id])->user_id;
        $teacherB = TeacherDetail::factory()->create(['user_id' => User::factory()->create(['name' => 'Teacher B'])->id])->user_id;

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacherA]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacherB]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/teacher-wise-evaluation?'.http_build_query([...$filters, 'teacher_id' => $teacherA]));

        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('Teacher A', $rows[0]['teacher_name']);
    }

    public function test_export_downloads_an_excel_file_by_default(): void
    {
        $user = $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $teacher = TeacherDetail::factory()->create()->user_id;
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher]);

        $response = $this->withApiKey()->get('/api/v1/reports/teacher-wise-evaluation/export?'.http_build_query($filters));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=utf-8');
        $this->assertStringContainsString('teacher_wise_evaluation_report.xls', $response->headers->get('Content-Disposition'));
        // The exported markup itself — who pulled it, and that Subject
        // Name now appears (ahead of Subject Code, per the report's own
        // column order).
        $this->assertStringContainsString('Printed By', $response->getContent());
        $this->assertStringContainsString($user->name, $response->getContent());
        $this->assertStringContainsString('Subject Name', $response->getContent());
        $this->assertStringContainsString('Data Structures', $response->getContent());
        // The searched exam type — called out on its own row near the top
        // (see the view's own docblock) as well as its own column, labeled
        // "Examination" per that column header.
        $this->assertStringContainsString('Examination: Regular', $response->getContent());
        $this->assertStringContainsString('Examination</th>', $response->getContent());
        // The bundled logo (falls back to it whenever no custom one has
        // been uploaded via General Settings) is embedded as a data: URI
        // — self-contained, no network fetch needed to actually see it.
        $this->assertStringContainsString('data:image/png;base64,', $response->getContent());
        // Every cell carries its own inline border — the whole reason the
        // template was rewritten as one bordered table instead of a
        // <style>-block-based one Excel's HTML import mostly ignores.
        $this->assertStringContainsString('border: 1px solid', $response->getContent());
    }

    public function test_export_downloads_a_pdf_when_format_is_pdf(): void
    {
        $this->actingUser();
        ['filters' => $filters] = $this->searchableMapping();

        $response = $this->withApiKey()->get('/api/v1/reports/teacher-wise-evaluation/export?'.http_build_query([...$filters, 'format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('teacher_wise_evaluation_report.pdf', $response->headers->get('Content-Disposition'));
    }
}
