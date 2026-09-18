<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\Program;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\TeacherDetail;
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
            'exam_type_id' => ExamType::factory()->create()->id,
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
            'exam_type_id' => ExamType::factory()->create()->id,
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

    /**
     * The same packet combination (program/packet_code/question paper/
     * course/exam term/semester) can legitimately be uploaded as more than
     * one QuestionAnswerSheetMapping row (see store()'s own docblock) — a
     * QR code must still be unique across all of them together, not just
     * within whichever single mapping id a batch happens to target.
     */
    public function test_a_barcode_already_used_by_a_sibling_mapping_with_the_same_combination_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();
        $program = Program::factory()->create();
        $examTerm = ExamTerm::factory()->create();
        $examType = ExamType::factory()->create();

        $sameCombo = fn () => $this->withApiKey()->postJson('/api/v1/answer-sheet-mappings', [
            'question_paper_id' => $paper->id,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => $examTerm->id,
            'exam_type_id' => $examType->id,
            'program_name' => $program->name,
            'packet_code' => 'PKT-001',
        ]);

        $firstMapping = QuestionAnswerSheetMapping::findOrFail($sameCombo()->assertCreated()->json('data.id'));
        $secondMapping = QuestionAnswerSheetMapping::findOrFail($sameCombo()->assertCreated()->json('data.id'));
        $this->assertNotSame($firstMapping->id, $secondMapping->id);

        $this->postRows($firstMapping, [$this->row('BC-SHARED', 'R2001')], [
            'BC-SHARED' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->postRows($secondMapping, [$this->row('BC-SHARED', 'R2002')], [
            'BC-SHARED' => UploadedFile::fake()->create('sheet2.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(0, $secondMapping->answerSheets()->count());
    }

    /**
     * The same QR code reused under a genuinely *different* combination
     * (here, a different course) is fine — uniqueness is per combination,
     * never global.
     */
    public function test_a_barcode_can_be_reused_under_a_different_combination(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mappingA = $this->createMapping();
        $mappingB = $this->createMapping(); // different course/exam term (each freshly factoried) — a genuinely different combination

        $this->postRows($mappingA, [$this->row('BC-REUSED', 'R3001')], [
            'BC-REUSED' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->postRows($mappingB, [$this->row('BC-REUSED', 'R3002')], [
            'BC-REUSED' => UploadedFile::fake()->create('sheet2.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $this->assertSame(1, $mappingB->answerSheets()->count());
    }

    public function test_check_duplicate_barcodes_reports_only_the_ones_already_used_for_that_combination(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $mapping = $this->createMapping();

        $this->postRows($mapping, [$this->row('BC-USED', 'R4001')], [
            'BC-USED' => UploadedFile::fake()->create('sheet1.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response = $this->withApiKey()->postJson('/api/v1/answer-sheet-mappings/check-duplicate-barcodes', [
            'program_name' => $mapping->program_name,
            'packet_code' => $mapping->packet_code,
            'question_paper_id' => $mapping->question_paper_id,
            'course_id' => $mapping->course_id,
            'exam_term_id' => $mapping->exam_term_id,
            'exam_type_id' => $mapping->exam_type_id,
            'semester' => $mapping->semester,
            'barcodes' => ['BC-USED', 'BC-FRESH'],
        ]);

        $response->assertOk();
        $this->assertSame(['BC-USED'], array_values($response->json('data.duplicate_barcodes')));
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
            'exam_type_id' => ExamType::factory()->create()->id,
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

    public function test_index_search_matches_packet_code_program_name_course_or_exam_term(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create(['name' => 'Business Communication', 'code' => 'COM401']);
        $match = QuestionAnswerSheetMapping::factory()->create(['course_id' => $course->id, 'packet_code' => 'PKT-999']);
        $noMatch = QuestionAnswerSheetMapping::factory()->create(['packet_code' => 'PKT-000']);

        $response = $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings?search=PKT-999');

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($noMatch->id, $ids);
    }

    public function test_index_filters_by_course_exam_term_and_semester(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();
        $examTerm = ExamTerm::factory()->create();
        $match = QuestionAnswerSheetMapping::factory()->create(['course_id' => $course->id, 'exam_term_id' => $examTerm->id, 'semester' => 3]);
        QuestionAnswerSheetMapping::factory()->create(['semester' => 5]);

        $response = $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings?course_id={$course->id}&exam_term_id={$examTerm->id}&semester=3");

        $this->assertSame([$match->id], collect($response->json('data.items'))->pluck('id')->all());
    }

    public function test_index_filters_by_uploaded_or_empty_status(): void
    {
        $this->actingAdmin();
        $uploaded = QuestionAnswerSheetMapping::factory()->create();
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $uploaded->id]);
        $empty = QuestionAnswerSheetMapping::factory()->create();

        $uploadedResponse = $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings?status=uploaded');
        $this->assertSame([$uploaded->id], collect($uploadedResponse->json('data.items'))->pluck('id')->all());

        $emptyResponse = $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings?status=empty');
        $this->assertSame([$empty->id], collect($emptyResponse->json('data.items'))->pluck('id')->all());
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

    public function test_rows_reports_the_assigned_teachers_name_and_emp_code(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $teacherDetail = TeacherDetail::factory()->create(['emp_code' => 'EMP-4021']);
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'teacher_id' => $teacherDetail->user_id,
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings/{$mapping->id}/rows");

        $response->assertOk();
        $this->assertSame($teacherDetail->user->name, $response->json('data.items.0.teacher_name'));
        $this->assertSame('EMP-4021', $response->json('data.items.0.teacher_emp_code'));
    }

    public function test_rows_search_matches_roll_no_name_or_subject_barcode(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $match = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => 'ROLL-9001',
            'name' => 'Amartya Chatterjee',
            'subject_barcode' => 'BC-9001',
        ]);
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => 'ROLL-9002',
            'name' => 'Someone Else',
            'subject_barcode' => 'BC-9002',
        ]);

        $response = $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings/{$mapping->id}/rows?search=Amartya");

        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertSame([$match->id], $ids->toArray());
    }

    public function test_admin_can_delete_a_single_row_from_a_packet(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $sheet = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id]);
        $keep = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id]);

        $response = $this->withApiKey()->deleteJson("/api/v1/answer-sheet-mappings/{$mapping->id}/rows/{$sheet->id}");

        $response->assertOk();
        $this->assertSoftDeleted('answer_sheets', ['id' => $sheet->id]);
        $this->assertNull(AnswerSheet::find($sheet->id));
        $this->assertNotNull(AnswerSheet::find($keep->id));
    }

    public function test_delete_row_rejects_a_row_belonging_to_a_different_packet(): void
    {
        $this->actingAdmin();
        $mapping = QuestionAnswerSheetMapping::factory()->create();
        $otherMapping = QuestionAnswerSheetMapping::factory()->create();
        $sheet = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $otherMapping->id]);

        $response = $this->withApiKey()->deleteJson("/api/v1/answer-sheet-mappings/{$mapping->id}/rows/{$sheet->id}");

        $response->assertStatus(404);
        $this->assertNotNull(AnswerSheet::find($sheet->id));
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
