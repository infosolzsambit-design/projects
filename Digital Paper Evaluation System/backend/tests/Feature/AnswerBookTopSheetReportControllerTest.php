<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class AnswerBookTopSheetReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{mapping: QuestionAnswerSheetMapping, paper: QuestionPaper, filters: array<string, mixed>}
     */
    private function searchableMapping(array $overrides = []): array
    {
        $course = Course::factory()->create(['name' => 'Data Structures', 'code' => 'CS-101']);
        $examTerm = ExamTerm::factory()->create(['name' => 'End Semester']);
        $examType = ExamType::factory()->create();
        $paper = QuestionPaper::factory()->create(['exam_year' => 2026, 'full_marks' => 20]);
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
            'paper' => $paper,
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

    /**
     * Group A > "1" (all, wraps two OR-graded sub-parts "i"/"ii") and
     * Group A > "2" (a lone leaf, no sub-parts) — matches the reference
     * top_sheet.jpeg's own "Q1.i"/"Q2" numbering shape.
     *
     * @return array{leaf1: QuestionPaperNode, leaf2: QuestionPaperNode, leaf3: QuestionPaperNode}
     */
    private function buildStructure(QuestionPaper $paper): array
    {
        $group = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => null,
            'label' => 'Group A', 'mode' => 'all', 'sort_order' => 1,
        ]);
        $part1 = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $group->id,
            'label' => '1', 'mode' => 'all', 'sort_order' => 1,
        ]);
        $leaf1 = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $part1->id,
            'label' => 'i', 'mode' => 'leaf', 'marks' => 5, 'sort_order' => 1,
        ]);
        $leaf2 = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $part1->id,
            'label' => 'ii', 'mode' => 'leaf', 'marks' => 5, 'sort_order' => 2,
        ]);
        $leaf3 = QuestionPaperNode::create([
            'question_paper_id' => $paper->id, 'parent_id' => $group->id,
            'label' => '2', 'mode' => 'leaf', 'marks' => 10, 'sort_order' => 2,
        ]);

        return ['leaf1' => $leaf1, 'leaf2' => $leaf2, 'leaf3' => $leaf3];
    }

    public function test_index_requires_all_required_filters(): void
    {
        $this->actingUser();

        $response = $this->withApiKey()->getJson('/api/v1/reports/answer-book-top-sheet');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year']);
    }

    public function test_index_lists_evaluated_and_pending_sheets_together(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R001', 'marks' => 18]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R002', 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/answer-book-top-sheet?'.http_build_query($filters));

        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertCount(2, $rows);
        $evaluated = collect($rows)->firstWhere('roll_no', 'R001');
        $pending = collect($rows)->firstWhere('roll_no', 'R002');
        $this->assertTrue($evaluated['evaluated']);
        // assertEquals, not assertSame — json_encode() drops the trailing
        // .0 for a whole-number float (18.0 comes back over JSON as the
        // int 18), which is a wire-format quirk, not a real type change.
        $this->assertEquals(18.0, $evaluated['marks']);
        $this->assertFalse($pending['evaluated']);
        $this->assertNull($pending['marks']);
    }

    public function test_index_excludes_sheets_outside_the_searched_exam_offering(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        $otherMapping = $this->searchableMapping(['program_name' => 'B.Sc Physics'])['mapping'];

        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $otherMapping->id]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/answer-book-top-sheet?'.http_build_query($filters));

        $response->assertOk();
        $this->assertCount(1, $response->json('data.rows'));
    }

    public function test_view_returns_a_pdf_for_an_evaluated_sheet(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'paper' => $paper] = $this->searchableMapping();
        ['leaf1' => $leaf1, 'leaf3' => $leaf3] = $this->buildStructure($paper);
        $sheet = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => 'R001',
            'marks' => 12,
            'draft_marks_breakdown' => [$leaf1->id => 4, $leaf3->id => 8],
        ]);

        $response = $this->withApiKey()->get("/api/v1/reports/answer-book-top-sheet/{$sheet->id}/view");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_view_rejects_a_sheet_that_has_not_been_evaluated_yet(): void
    {
        $this->actingUser();
        ['mapping' => $mapping] = $this->searchableMapping();
        $sheet = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->get("/api/v1/reports/answer-book-top-sheet/{$sheet->id}/view");

        $response->assertStatus(422);
    }

    public function test_export_pdf_combines_every_evaluated_sheet_and_skips_pending_ones(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'paper' => $paper, 'filters' => $filters] = $this->searchableMapping();
        $this->buildStructure($paper);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R001', 'marks' => 18]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R002', 'marks' => 15]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R003', 'marks' => null]);

        $response = $this->withApiKey()->get('/api/v1/reports/answer-book-top-sheet/export?'.http_build_query([...$filters, 'format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('answer_book_top_sheets.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_export_zip_contains_one_pdf_per_evaluated_sheet(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'paper' => $paper, 'filters' => $filters] = $this->searchableMapping();
        $this->buildStructure($paper);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R001', 'marks' => 18]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R002', 'marks' => 15]);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'roll_no' => 'R003', 'marks' => null]);

        $response = $this->withApiKey()->get('/api/v1/reports/answer-book-top-sheet/export?'.http_build_query([...$filters, 'format' => 'zip']));

        $response->assertOk();
        $this->assertStringContainsString('zip', $response->headers->get('Content-Type'));

        // A BinaryFileResponse streams straight from disk rather than
        // holding its body in memory, so getContent() (what a normal
        // response assertion would read) isn't usable here — the test
        // client never actually calls sendContent(), so the temp file
        // deleteFileAfterSend() would otherwise clean up is still on disk
        // to read directly.
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
    }

    public function test_export_rejects_a_search_with_no_evaluated_sheets(): void
    {
        $this->actingUser();
        ['mapping' => $mapping, 'filters' => $filters] = $this->searchableMapping();
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null]);

        $response = $this->withApiKey()->getJson('/api/v1/reports/answer-book-top-sheet/export?'.http_build_query($filters));

        $response->assertStatus(422);
    }
}
