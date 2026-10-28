<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamSubType;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluatedCopyDownloadControllerTest extends TestCase
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
        $course = Course::factory()->create();
        $examTerm = ExamTerm::factory()->create();
        $examType = ExamType::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => 2026, 'full_marks' => 50]);
        $mapping = QuestionAnswerSheetMapping::factory()->create(array_merge([
            'program_name' => 'BCA',
            'course_id' => $course->id,
            'exam_term_id' => $examTerm->id,
            'exam_type_id' => $examType->id,
            'semester' => 4,
            'question_paper_id' => $paper->id,
        ], $overrides));

        return [
            'mapping' => $mapping,
            'filters' => [
                'program_name' => 'BCA',
                'course_id' => $course->id,
                'exam_term_id' => $examTerm->id,
                'exam_type_id' => $examType->id,
                'semester' => 4,
                'exam_year' => 2026,
            ],
        ];
    }

    public function test_index_requires_the_same_filters_as_the_other_reports(): void
    {
        $this->actingUser();

        $this->withApiKey()->getJson('/api/v1/reports/evaluated-copies')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year'])
            ->assertJsonMissingValidationErrors(['department_id', 'exam_sub_type_id', 'teacher_id']);
    }

    public function test_index_lists_only_evaluated_copies_with_a_summary_of_the_rest(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $teacher = TeacherDetail::factory()->create(['emp_code' => 'EMP-7'])->user;
        $base = ['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacher->id, 'absent' => false, 'pdf_path' => '/storage/a.pdf'];

        AnswerSheet::factory()->create($base + ['roll_no' => 'R2', 'subject_barcode' => '10000002', 'marks' => 41.5, 'evaluated_at' => '2026-10-20 10:00:00']);
        AnswerSheet::factory()->create($base + ['roll_no' => 'R1', 'subject_barcode' => '10000001', 'marks' => 30]);
        AnswerSheet::factory()->create(array_merge($base, ['roll_no' => 'R3', 'marks' => 25, 'pdf_path' => null]));
        AnswerSheet::factory()->create($base + ['roll_no' => 'R4', 'marks' => null]);
        AnswerSheet::factory()->create(array_merge($base, ['roll_no' => 'R5', 'marks' => null, 'absent' => true, 'pdf_path' => null]));

        $data = $this->withApiKey()->getJson('/api/v1/reports/evaluated-copies?'.http_build_query($filters))->assertOk()->json('data');

        $this->assertSame(['evaluated' => 3, 'not_evaluated' => 1, 'absent' => 1, 'no_file' => 1], $data['summary']);
        $this->assertSame(['R1', 'R2', 'R3'], array_column($data['rows'], 'roll_no'));
        $this->assertSame([true, true, false], array_column($data['rows'], 'has_file'));
        $this->assertSame('10000002', $data['rows'][1]['script_code']);
        $this->assertEquals(41.5, $data['rows'][1]['marks']);
        $this->assertSame(50, $data['rows'][1]['full_marks']);
        $this->assertSame('EMP-7', $data['rows'][1]['teacher_emp_code']);
        $this->assertArrayNotHasKey('draft_annotations', $data['rows'][0]);
    }

    public function test_index_honours_the_optional_filters(): void
    {
        $this->actingUser();
        $subType = ExamSubType::factory()->create();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping(['exam_sub_type_id' => $subType->id]);
        $teacherA = TeacherDetail::factory()->create()->user_id;
        $teacherB = TeacherDetail::factory()->create()->user_id;
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacherA, 'marks' => 10, 'absent' => false]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'teacher_id' => $teacherB, 'marks' => 20, 'absent' => false]);

        $get = fn (array $extra) => $this->withApiKey()->getJson('/api/v1/reports/evaluated-copies?'.http_build_query($filters + $extra))->assertOk()->json('data.rows');

        $this->assertCount(2, $get([]));
        $this->assertCount(1, $get(['teacher_id' => $teacherB]));
        $this->assertCount(2, $get(['exam_sub_type_id' => $subType->id]));
        $this->assertCount(0, $get(['exam_sub_type_id' => ExamSubType::factory()->create()->id]));
    }

    public function test_sheet_returns_what_the_copy_is_built_from(): void
    {
        $this->actingUser();
        ['mapping' => $mapping] = $this->searchableMapping();
        $annotations = ['1' => [['type' => 'correct', 'point' => [100, 200]]]];
        $sheet = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id, 'marks' => 35, 'absent' => false,
            'pdf_path' => '/storage/answer-sheets/1/a.pdf', 'draft_annotations' => $annotations,
        ]);

        $this->withApiKey()->getJson("/api/v1/reports/evaluated-copies/{$sheet->id}")
            ->assertOk()
            ->assertJsonPath('data.pdf_url', '/storage/answer-sheets/1/a.pdf')
            ->assertJsonPath('data.draft_annotations', $annotations)
            ->assertJsonPath('data.max_marks', 50);
    }

    public function test_sheet_refuses_a_copy_that_is_not_evaluated_or_has_no_file(): void
    {
        $this->actingUser();
        ['mapping' => $mapping] = $this->searchableMapping();
        $pending = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null, 'absent' => false, 'pdf_path' => '/storage/a.pdf']);
        $noFile = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => 10, 'absent' => false, 'pdf_path' => null]);

        $this->withApiKey()->getJson("/api/v1/reports/evaluated-copies/{$pending->id}")->assertStatus(422);
        $this->withApiKey()->getJson("/api/v1/reports/evaluated-copies/{$noFile->id}")->assertStatus(422);
    }
}
