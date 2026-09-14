<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\Program;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionAnswerSheetMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createMapping(?QuestionPaper $paper = null, ?Course $course = null, ?Program $program = null): QuestionAnswerSheetMapping
    {
        $paper ??= QuestionPaper::factory()->create();
        $course ??= Course::factory()->create();
        $program ??= Program::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/answer-sheet-mappings', [
            'question_paper_id' => $paper->id,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'program_name' => $program->name,
            'packet_code' => 'PKT-001',
        ]);

        $response->assertCreated();

        return QuestionAnswerSheetMapping::findOrFail($response->json('data.id'));
    }

    private function row(string $barcode, string $rollNo): array
    {
        return [
            'branch_code' => 'BR1',
            'branch_name' => 'Main Branch',
            'subject_code' => 'FIN101',
            'subject_name' => 'Financial Accounting',
            'semester' => 5,
            'subject_barcode' => $barcode,
            'fi_code' => 'FI01',
            'roll_no' => $rollNo,
            'name' => 'Student '.$rollNo,
            'registration_no' => 'REG'.$rollNo,
            'absent' => false,
            'locked_time' => null,
            'packet_no' => 'PKT-001',
            'barcode' => null,
            'marks' => null,
            'top_sheet' => null,
        ];
    }

    private function postRows(QuestionAnswerSheetMapping $mapping, array $rows, array $pdfs)
    {
        return $this->withApiKey()->post("/api/v1/answer-sheet-mappings/{$mapping->id}/rows", [
            'rows' => json_encode($rows),
            'pdfs' => $pdfs,
        ]);
    }

    public function test_admin_can_create_an_empty_packet(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();
        $program = Program::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/answer-sheet-mappings', [
            'question_paper_id' => $paper->id,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'program_name' => $program->name,
            'packet_code' => 'PKT-001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.question_paper_id', $paper->id)
            ->assertJsonPath('data.packet_code', 'PKT-001');
        $this->assertSame(1, QuestionAnswerSheetMapping::count());
        $this->assertSame(0, AnswerSheet::count());
    }

    public function test_admin_can_upload_a_batch_of_rows_and_pdfs_to_an_existing_packet(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $rows = [$this->row('BC-1001', 'R1001'), $this->row('BC-1002', 'R1002')];
        $pdfs = [
            'BC-1001' => UploadedFile::fake()->create('sheet1.pdf', 200, 'application/pdf'),
            'BC-1002' => UploadedFile::fake()->create('sheet2.pdf', 200, 'application/pdf'),
        ];

        $response = $this->postRows($mapping, $rows, $pdfs);

        $response->assertCreated()->assertJsonPath('data.created_count', 2)->assertJsonPath('data.total_in_mapping', 2);
        $this->assertSame(2, $mapping->answerSheets()->count());

        $sheet = AnswerSheet::where('subject_barcode', 'BC-1001')->first();
        $other = AnswerSheet::where('subject_barcode', 'BC-1002')->first();
        $this->assertSame('R1001', $sheet->roll_no);
        // pdf_name is a freshly generated unique name, never the barcode or
        // the originally-uploaded filename ("sheet1.pdf") — see the
        // controller's own reasoning for why.
        $this->assertNotSame('sheet1.pdf', $sheet->pdf_name);
        $this->assertNotSame($sheet->pdf_name, $other->pdf_name);
        $this->assertSame($sheet->pdf_name, basename($sheet->pdf_path));
        $this->assertStringStartsWith("/storage/answer-sheets/{$mapping->id}/", $sheet->pdf_path);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $sheet->pdf_path));
    }

    public function test_multiple_batches_accumulate_on_the_same_packet(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $this->postRows($mapping, [$this->row('BC-1001', 'R1001')], [
            'BC-1001' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.total_in_mapping', 1);

        $this->postRows($mapping, [$this->row('BC-1002', 'R1002')], [
            'BC-1002' => UploadedFile::fake()->create('sheet2.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.total_in_mapping', 2);

        $this->assertSame(2, $mapping->answerSheets()->count());
    }

    public function test_a_barcode_reused_from_an_earlier_batch_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $this->postRows($mapping, [$this->row('BC-DUP', 'R1001')], [
            'BC-DUP' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->postRows($mapping, [$this->row('BC-DUP', 'R1002')], [
            'BC-DUP' => UploadedFile::fake()->create('sheet2.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(1, $mapping->answerSheets()->count());
    }

    public function test_a_roll_no_reused_from_an_earlier_batch_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $this->postRows($mapping, [$this->row('BC-1001', 'R-DUP')], [
            'BC-1001' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->postRows($mapping, [$this->row('BC-1002', 'R-DUP')], [
            'BC-1002' => UploadedFile::fake()->create('sheet2.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(1, $mapping->answerSheets()->count());
    }

    public function test_row_count_must_match_pdf_count_within_a_batch(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $rows = [$this->row('BC-1001', 'R1001'), $this->row('BC-1002', 'R1002')];
        $pdfs = ['BC-1001' => UploadedFile::fake()->create('sheet1.pdf', 200, 'application/pdf')];

        $response = $this->postRows($mapping, $rows, $pdfs);

        $response->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(0, $mapping->answerSheets()->count());
    }

    public function test_duplicate_subject_barcode_within_one_batch_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $rows = [$this->row('BC-DUP', 'R1001'), $this->row('BC-DUP', 'R1002')];
        $pdfs = ['BC-DUP' => UploadedFile::fake()->create('sheet1.pdf', 200, 'application/pdf')];

        $response = $this->postRows($mapping, $rows, $pdfs);

        $response->assertStatus(422)->assertJsonValidationErrors(['rows.0.subject_barcode', 'rows.1.subject_barcode']);
    }

    public function test_a_barcode_with_no_matching_pdf_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $rows = [$this->row('BC-1001', 'R1001'), $this->row('BC-9999-MISSING', 'R1002')];
        $pdfs = [
            'BC-1001' => UploadedFile::fake()->create('sheet1.pdf', 200, 'application/pdf'),
            'BC-2222-EXTRA' => UploadedFile::fake()->create('sheet2.pdf', 200, 'application/pdf'),
        ];

        $response = $this->postRows($mapping, $rows, $pdfs);

        $response->assertStatus(422)->assertJsonValidationErrors('pdfs');
        $this->assertSame(0, $mapping->answerSheets()->count());
    }

    public function test_requires_an_existing_question_paper_course_and_program(): void
    {
        $this->actingAdmin();

        $response = $this->withApiKey()->postJson('/api/v1/answer-sheet-mappings', [
            'question_paper_id' => 999999,
            'course_id' => 999999,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'program_name' => 'Nonexistent Program',
            'packet_code' => 'PKT-001',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['question_paper_id', 'course_id', 'program_name']);
    }

    public function test_index_lists_mappings(): void
    {
        $this->actingAdmin();
        QuestionAnswerSheetMapping::factory()->count(3)->create();

        $response = $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_show_returns_the_mapping_with_its_answer_sheets(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(2)->create(['question_answer_sheet_mapping_id' => $mapping->id]);

        $response = $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings/{$mapping->id}");

        $response->assertOk()->assertJsonPath('data.answer_sheet_count', 2);
    }

    public function test_rows_returns_a_paginated_page_of_the_packets_own_answer_sheets(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->count(5)->create(['question_answer_sheet_mapping_id' => $mapping->id]);
        // A different packet's rows must never leak into this one's page.
        AnswerSheet::factory()->count(2)->create();

        $response = $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings/{$mapping->id}/rows?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(5, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
    }

    public function test_admin_can_soft_delete_a_mapping(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/answer-sheet-mappings/{$mapping->id}");

        $response->assertOk();
        $this->assertSoftDeleted('question_answer_sheet_mappings', ['id' => $mapping->id]);
    }

    public function test_force_deleting_a_mapping_removes_its_answer_sheets_and_their_pdfs(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $sheet = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'pdf_path' => '/storage/answer-sheets/'.$mapping->id.'/bc-1001.pdf',
        ]);
        Storage::disk('public')->put('answer-sheets/'.$mapping->id.'/bc-1001.pdf', 'fake pdf content');

        $mapping->forceDelete();

        $this->assertDatabaseMissing('answer_sheets', ['id' => $sheet->id]);
        Storage::disk('public')->assertMissing('answer-sheets/'.$mapping->id.'/bc-1001.pdf');
    }
}
