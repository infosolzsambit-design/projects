<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PendingReportControllerTest extends TestCase
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
            'program_name' => 'B.Tech',
            'course_id' => $course->id,
            'exam_term_id' => $examTerm->id,
            'exam_type_id' => $examType->id,
            'semester' => 6,
            'question_paper_id' => $paper->id,
        ], $overrides));

        return [
            'mapping' => $mapping,
            'filters' => [
                'program_name' => 'B.Tech',
                'course_id' => $course->id,
                'exam_term_id' => $examTerm->id,
                'exam_type_id' => $examType->id,
                'semester' => 6,
                'exam_year' => 2026,
            ],
        ];
    }

    private function teacher(string $name): int
    {
        return TeacherDetail::factory()->create(['user_id' => User::factory()->create(['name' => $name])->id])->user_id;
    }

    public function test_index_requires_the_exam_filters(): void
    {
        $this->actingUser();

        $this->withApiKey()->getJson('/api/v1/reports/pending-report')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year']);
    }

    public function test_index_lists_each_teacher_with_pending_sheets_most_pending_first(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping(['department_name' => 'Physics Dept']);
        $few = $this->teacher('Few Pending');
        $many = $this->teacher('Many Pending');
        $done = $this->teacher('All Done');
        $sheet = fn (int $teacher, array $attributes = []) => AnswerSheet::factory()->create($attributes + [
            'question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher, 'marks' => null,
        ]);

        $sheet($few);                                       // pending
        $sheet($few, ['marks' => 10]);                      // evaluated
        $sheet($few, ['issue_status' => 'open']);           // problem, not pending
        $sheet($many);
        $sheet($many);
        $sheet($many);
        $sheet($done, ['marks' => 12]);                     // nothing pending — not listed

        $rows = $this->withApiKey()->getJson('/api/v1/reports/pending-report?'.http_build_query($filters))
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(2, $rows);
        $this->assertSame('Many Pending', $rows[0]['teacher_name']);
        $this->assertSame(3, $rows[0]['pending']);
        $this->assertSame('Few Pending', $rows[1]['teacher_name']);
        $this->assertSame(3, $rows[1]['allotted']);
        $this->assertSame(1, $rows[1]['evaluated']);
        $this->assertSame(1, $rows[1]['pending']);
        $this->assertSame(1, $rows[1]['problem']);
        $this->assertSame(['Physics Dept'], $rows[1]['department_names']);
    }

    public function test_department_and_teacher_filters_narrow_the_results(): void
    {
        $this->actingUser();
        $physics = Department::factory()->create();
        $chemistry = Department::factory()->create();
        ['mapping' => $inPhysics, 'filters' => $filters] = $this->searchableMapping(['department_id' => $physics->id]);
        $inChemistry = QuestionAnswerSheetMapping::factory()->create(
            $inPhysics->only(['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'question_paper_id'])
            + ['department_id' => $chemistry->id],
        );
        $teacherA = $this->teacher('Teacher A');
        $teacherB = $this->teacher('Teacher B');
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $inPhysics->id, 'teacher_id' => $teacherA, 'marks' => null]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $inChemistry->id, 'teacher_id' => $teacherB, 'marks' => null]);

        $this->withApiKey()->getJson('/api/v1/reports/pending-report?'.http_build_query($filters))
            ->assertOk()->assertJsonCount(2, 'data.rows');
        $this->withApiKey()->getJson('/api/v1/reports/pending-report?'.http_build_query($filters + ['department_id' => $chemistry->id]))
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.teacher_name', 'Teacher B');
        $this->withApiKey()->getJson('/api/v1/reports/pending-report?'.http_build_query($filters + ['teacher_id' => $teacherA]))
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.teacher_name', 'Teacher A');
    }

    public function test_export_downloads_excel_and_pdf(): void
    {
        $user = $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        AnswerSheet::factory()->count(2)->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $this->teacher('Dr. Pending'), 'marks' => null]);
        $url = '/api/v1/reports/pending-report/export?'.http_build_query($filters);

        $excel = $this->withApiKey()->get($url);
        $excel->assertOk();
        $excel->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=utf-8');
        $this->assertStringContainsString('pending_report.xls', $excel->headers->get('Content-Disposition'));
        foreach (['PENDING REPORT', 'Examination: Regular', 'Data Structures', 'Dr. Pending', $user->name, 'Total'] as $expected) {
            $this->assertStringContainsString($expected, $excel->getContent());
        }

        $pdf = $this->withApiKey()->get($url.'&format=pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_export_header_shows_the_organization_logo_only_when_switched_on(): void
    {
        $this->actingUser();
        Storage::fake('public');
        Storage::disk('public')->put('general-settings/org.png', 'ORG-LOGO-BYTES');
        $setting = \App\Models\GeneralSetting::updateOrCreate(
            ['field_name' => 'organization_logo'],
            ['label' => 'Organization Logo', 'type' => 'file', 'value' => '/storage/general-settings/org.png', 'status' => true, 'sort_order' => 1],
        );
        ['filters' => $filters] = $this->searchableMapping();
        $url = '/api/v1/reports/pending-report/export?'.http_build_query($filters);
        $encoded = base64_encode('ORG-LOGO-BYTES');

        $this->assertStringContainsString($encoded, $this->withApiKey()->get($url)->getContent());

        $setting->update(['status' => false]);
        $this->assertStringNotContainsString($encoded, $this->withApiKey()->get($url)->getContent());
    }
}
