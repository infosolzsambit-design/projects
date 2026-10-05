<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\QuestionPaper;
use App\Models\User;
use App\Services\StudentIdCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateMarksheetControllerTest extends TestCase
{
    use RefreshDatabase;

    private ExamTerm $examTerm;

    private ExamType $examType;

    private QuestionPaper $paper;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->examTerm = ExamTerm::factory()->create();
        $this->examType = ExamType::factory()->create();
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

    private function query(array $overrides = []): string
    {
        return http_build_query(array_merge([
            'program_name' => 'B.Sc',
            'exam_term_id' => $this->examTerm->id,
            'exam_type_id' => $this->examType->id,
            'semester' => 1,
            'exam_year' => 2026,
        ], $overrides));
    }

    public function test_summary_counts_evaluation_and_roll_number_check_per_course(): void
    {
        $course = Course::factory()->create(['name' => 'Physics II', 'code' => 'PH-102']);
        $mapping = $this->mapping($course);
        $base = ['question_answer_sheet_mapping_id' => $mapping->id];

        $read = ['roll_no_check_status' => 'read', 'student_id_read' => '231001101005'];
        AnswerSheet::factory()->create($base + $read + ['marks' => 40, 'roll_no' => '231001101005']);
        AnswerSheet::factory()->create($base + $read + ['marks' => 35, 'roll_no' => '2310-0110-1005']); // separators ignored
        AnswerSheet::factory()->create($base + $read + ['marks' => null, 'roll_no' => '999999999999']);
        AnswerSheet::factory()->create($base + ['marks' => null, 'roll_no_check_status' => 'unreadable', 'issue_status' => 'open']);
        AnswerSheet::factory()->create($base + ['marks' => null, 'roll_no_check_status' => null]);

        $this->withApiKey()->getJson('/api/v1/generate-marksheet?'.$this->query())
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.course_name', 'Physics II')
            ->assertJsonPath('data.rows.0.course_code', 'PH-102')
            ->assertJsonPath('data.rows.0.total_count', 5)
            ->assertJsonPath('data.rows.0.evaluated_count', 2)
            ->assertJsonPath('data.rows.0.pending_count', 2) // the open-issue sheet is a problem, not pending
            ->assertJsonPath('data.rows.0.problem_count', 1)
            ->assertJsonPath('data.rows.0.matched_count', 2)
            ->assertJsonPath('data.rows.0.mismatched_count', 2)
            ->assertJsonPath('data.rows.0.not_checked_count', 1);
    }

    public function test_summary_filters_by_the_packet_department_and_lists_it(): void
    {
        $physics = Department::factory()->create(['name' => 'Physics Dept']);
        $chemistry = Department::factory()->create(['name' => 'Chemistry Dept']);
        $course = Course::factory()->create();
        $inPhysics = $this->mapping($course);
        $inPhysics->update(['department_id' => $physics->id, 'department_name' => $physics->name]);
        $inChemistry = $this->mapping($course);
        $inChemistry->update(['department_id' => $chemistry->id, 'department_name' => $chemistry->name]);
        AnswerSheet::factory()->count(2)->create(['question_answer_sheet_mapping_id' => $inPhysics->id]);
        AnswerSheet::factory()->count(3)->create(['question_answer_sheet_mapping_id' => $inChemistry->id]);

        // No department — both packets, both departments listed.
        $this->withApiKey()->getJson('/api/v1/generate-marksheet?'.$this->query())
            ->assertOk()
            ->assertJsonPath('data.rows.0.total_count', 5)
            ->assertJsonPath('data.rows.0.department_names', ['Chemistry Dept', 'Physics Dept']);

        // One department — only its packet.
        $this->withApiKey()->getJson('/api/v1/generate-marksheet?'.$this->query(['department_id' => $physics->id]))
            ->assertOk()
            ->assertJsonPath('data.rows.0.total_count', 2)
            ->assertJsonPath('data.rows.0.department_names', ['Physics Dept']);
    }

    public function test_summary_requires_the_exam_filters_but_not_course(): void
    {
        $this->withApiKey()->getJson('/api/v1/generate-marksheet')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['program_name', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year'])
            ->assertJsonMissingValidationErrors(['course_id']);
    }

    public function test_mismatches_lists_only_sheets_needing_review(): void
    {
        $course = Course::factory()->create();
        $mapping = $this->mapping($course);
        $base = ['question_answer_sheet_mapping_id' => $mapping->id];

        $mismatch = AnswerSheet::factory()->create($base + [
            'subject_barcode' => '1775390', 'roll_no' => '231001101005',
            'student_id_read' => '241002203005', 'roll_no_check_status' => 'read',
            'student_id_crop_path' => '/storage/student-id-crops/1/1.png',
        ]);
        AnswerSheet::factory()->create($base + ['roll_no' => '241002203005', 'student_id_read' => '241002203005', 'roll_no_check_status' => 'read']);

        $this->withApiKey()->getJson('/api/v1/generate-marksheet/mismatches?'.$this->query(['course_id' => $course->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mismatch->id)
            ->assertJsonPath('data.items.0.qr_code', '1775390')
            ->assertJsonPath('data.items.0.roll_no', '231001101005')
            ->assertJsonPath('data.items.0.student_id_read', '241002203005')
            ->assertJsonPath('data.items.0.crop_url', '/storage/student-id-crops/1/1.png')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_a_roll_number_corrected_after_the_check_counts_as_matched(): void
    {
        $course = Course::factory()->create();
        $sheet = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $this->mapping($course)->id,
            'roll_no' => '231001101005',
            'student_id_read' => '241002203005',
            'roll_no_check_status' => 'read',
        ]);
        $url = '/api/v1/generate-marksheet?'.$this->query();

        $this->withApiKey()->getJson($url)
            ->assertJsonPath('data.rows.0.matched_count', 0)
            ->assertJsonPath('data.rows.0.mismatched_count', 1);

        $sheet->update(['roll_no' => '241002203005']);

        $this->withApiKey()->getJson($url)
            ->assertJsonPath('data.rows.0.matched_count', 1)
            ->assertJsonPath('data.rows.0.mismatched_count', 0);
        $this->withApiKey()->getJson('/api/v1/generate-marksheet/mismatches?'.$this->query(['course_id' => $course->id]))
            ->assertJsonCount(0, 'data.items');
    }

    public function test_confirm_match_marks_selected_sheets_as_matched(): void
    {
        $course = Course::factory()->create();
        $mapping = $this->mapping($course);
        $misread = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => '231001101005', 'student_id_read' => '231001101006', 'roll_no_check_status' => 'read',
        ]);
        $unreadable = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => '231001101007', 'student_id_read' => null, 'roll_no_check_status' => 'unreadable',
        ]);
        $untouched = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'roll_no' => '231001101008', 'student_id_read' => '111111111111', 'roll_no_check_status' => 'read',
        ]);

        $this->withApiKey()->postJson('/api/v1/generate-marksheet/confirm-match', ['answer_sheet_ids' => [$misread->id, $unreadable->id]])
            ->assertOk()
            ->assertJsonPath('data.confirmed', 2);

        $this->withApiKey()->getJson('/api/v1/generate-marksheet?'.$this->query())
            ->assertJsonPath('data.rows.0.matched_count', 2)
            ->assertJsonPath('data.rows.0.mismatched_count', 1);
        $this->assertSame('231001101007', $unreadable->fresh()->student_id_read);
        $this->assertNotNull($misread->fresh()->student_id_verified_by);
        $this->assertNotNull($misread->fresh()->student_id_verified_at);
        $this->assertNull($untouched->fresh()->student_id_verified_by);

        // A roll number changed after confirmation is a mismatch again.
        $misread->update(['roll_no' => '999999999999']);
        $this->withApiKey()->getJson('/api/v1/generate-marksheet?'.$this->query())
            ->assertJsonPath('data.rows.0.matched_count', 1);
    }

    public function test_confirm_match_requires_answer_sheet_ids(): void
    {
        $this->withApiKey()->postJson('/api/v1/generate-marksheet/confirm-match', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answer_sheet_ids']);
    }

    public function test_export_is_refused_until_every_sheet_is_evaluated_and_matched(): void
    {
        $course = Course::factory()->create(['code' => 'SCI501']);
        $mapping = $this->mapping($course);
        $matched = ['roll_no_check_status' => 'read', 'roll_no' => '231001101005', 'student_id_read' => '231001101005'];
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => 40] + $matched);
        $pending = AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $mapping->id, 'marks' => null] + $matched);
        $url = '/api/v1/generate-marksheet/export?'.$this->query(['course_id' => $course->id]);

        $this->withApiKey()->getJson($url)->assertStatus(422); // one not evaluated

        $pending->update(['marks' => 35, 'student_id_read' => '999999999999']);
        $this->withApiKey()->getJson($url)->assertStatus(422); // one roll number mismatched

        $pending->update(['student_id_read' => '231001101005']);
        $this->withApiKey()->get($url)->assertOk();
    }

    public function test_export_downloads_the_marksheet_as_excel_or_pdf(): void
    {
        $course = Course::factory()->create(['name' => 'Physics II', 'code' => 'PH-102', 'type' => 'Practical']);
        $mapping = $this->mapping($course);
        $mapping->update(['department_name' => 'Physics Dept']);
        $this->paper->update(['full_marks' => 80]);
        AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $mapping->id,
            'subject_barcode' => '1775390', 'name' => 'Prathama Bose', 'roll_no' => '231001101005',
            'registration_no' => 'REG-1', 'marks' => 42.5, 'evaluated_at' => '2026-09-28 14:30:00',
            'roll_no_check_status' => 'read', 'student_id_read' => '231001101005',
        ]);
        \App\Models\GeneralSetting::updateOrCreate(
            ['field_name' => 'university_code'],
            ['label' => 'University Code', 'type' => 'text', 'value' => 'TIU', 'status' => true, 'sort_order' => 1],
        );
        $url = '/api/v1/generate-marksheet/export?'.$this->query(['course_id' => $course->id]);

        $excel = $this->withApiKey()->get($url.'&format=excel');
        $excel->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', $excel->headers->get('Content-Type'));
        $this->assertStringContainsString('marksheet_ph_102.xls', $excel->headers->get('Content-Disposition'));
        $html = $excel->getContent();
        foreach (['MARKSHEET', 'B.Sc', 'Physics II', 'PH-102', '1st', '1775390', 'Prathama Bose', '231001101005', 'REG-1', '80', '42.5', '28-09-2026 02:30 PM'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // Department column right after Program Name, with the packet's department.
        $this->assertMatchesRegularExpression('/>Program Name<\/th>\s*<th[^>]*>Department<\/th>/', $html);
        $this->assertStringContainsString('Physics Dept', $html);
        // University Code (General Settings) sits right after Barcode, on every row.
        $this->assertMatchesRegularExpression('/>Barcode<\/th>\s*<th[^>]*>University Code<\/th>/', $html);
        $this->assertMatchesRegularExpression('/1775390<\/td>\s*<td[^>]*>TIU<\/td>/', $html);
        // "Type" is the last column, holding the course's type.
        $this->assertMatchesRegularExpression('/>Lock-in Time<\/th>\s*<th[^>]*>Type<\/th>\s*<\/tr>/', $html);
        $this->assertMatchesRegularExpression('/02:30 PM<\/td>\s*(\{\{--.*?--\}\}\s*)?<td[^>]*>Practical<\/td>\s*<\/tr>/s', $html);

        $pdf = $this->withApiKey()->get($url.'&format=pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_ordinal_semesters(): void
    {
        $this->assertSame(['1st', '2nd', '3rd', '4th', '11th', '12th'], array_map(
            [\App\Http\Controllers\API\V1\GenerateMarksheetController::class, 'ordinal'], [1, 2, 3, 4, 11, 12],
        ));
    }

    public function test_check_reads_the_next_batch_right_away_and_reports_progress(): void
    {
        Storage::fake('public');
        $mapping = $this->mapping(Course::factory()->create());
        $base = ['question_answer_sheet_mapping_id' => $mapping->id];
        foreach (['a', 'b', 'c'] as $name) {
            Storage::disk('public')->put("answer-sheets/{$mapping->id}/{$name}.pdf", '%PDF fake');
            AnswerSheet::factory()->create($base + ['pdf_path' => "/storage/answer-sheets/{$mapping->id}/{$name}.pdf", 'roll_no_check_status' => null]);
        }
        AnswerSheet::factory()->create($base + ['pdf_path' => null, 'roll_no_check_status' => null]); // no PDF: never counted
        Process::fake(['*' => Process::result('[{"status": "ok", "digits": "111"}, {"status": "ok", "digits": "222"}]')]);

        $this->withApiKey()->postJson('/api/v1/generate-marksheet/check?'.$this->query(), ['limit' => 2])
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.checked', 2)
            ->assertJsonPath('data.remaining', 1);

        Process::fake(['*' => Process::result('[{"status": "no_strip"}]')]);
        $this->withApiKey()->postJson('/api/v1/generate-marksheet/check?'.$this->query(), ['limit' => 2])
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.remaining', 0);

        $this->withApiKey()->postJson('/api/v1/generate-marksheet/check?'.$this->query())
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.remaining', 0);
    }

    public function test_recheck_clears_readings_but_never_a_manual_confirmation(): void
    {
        $course = Course::factory()->create();
        $mapping = $this->mapping($course);
        $base = ['question_answer_sheet_mapping_id' => $mapping->id, 'pdf_path' => '/storage/x.pdf'];
        $read = AnswerSheet::factory()->create($base + ['roll_no_check_status' => 'read', 'student_id_read' => '123']);
        $confirmed = AnswerSheet::factory()->create($base + [
            'roll_no_check_status' => 'read', 'student_id_read' => '999', 'student_id_verified_by' => 1, 'student_id_verified_at' => now(),
        ]);

        $this->withApiKey()->postJson('/api/v1/generate-marksheet/recheck?'.$this->query(['course_id' => $course->id]))
            ->assertOk()
            ->assertJsonPath('data.reset', 1)
            ->assertJsonPath('data.remaining', 1);

        $this->assertNull($read->fresh()->roll_no_check_status);
        $this->assertNull($read->fresh()->student_id_read);
        $this->assertSame('999', $confirmed->fresh()->student_id_read);
    }

    /**
     * @return array{0: AnswerSheet, 1: StudentIdCheckService}
     */
    private function sheetWithPdf(string $rollNo): array
    {
        Storage::fake('public');
        Storage::disk('public')->put('answer-sheets/test/sheet.pdf', '%PDF-1.4 fake');
        $sheet = AnswerSheet::factory()->create([
            'question_answer_sheet_mapping_id' => $this->mapping(Course::factory()->create())->id,
            'roll_no' => $rollNo,
            'pdf_path' => '/storage/answer-sheets/test/sheet.pdf',
        ]);

        return [$sheet, app(StudentIdCheckService::class)];
    }

    public function test_service_stores_the_digits_it_read(): void
    {
        [$sheet, $service] = $this->sheetWithPdf('2310-0110-1005');
        Process::fake(['*' => Process::result('[{"status": "ok", "digits": "231001101005", "confidences": [], "min_confidence": 1, "cells": 12}]')]);

        $this->assertSame('read', $service->check($sheet));
        $sheet->refresh();
        $this->assertSame('read', $sheet->roll_no_check_status);
        $this->assertSame('231001101005', $sheet->student_id_read);
        $this->assertNotNull($sheet->roll_no_checked_at);
    }

    public function test_service_records_unreadable_and_error(): void
    {
        [$sheet, $service] = $this->sheetWithPdf('231001101005');

        Process::fake(['*' => Process::result('[{"status": "ok", "digits": "241002203005"}]')]);
        $this->assertSame('read', $service->check($sheet));
        $this->assertSame('241002203005', $sheet->fresh()->student_id_read);

        Process::fake(['*' => Process::result('[{"status": "no_strip", "message": "not found"}]')]);
        $this->assertSame('unreadable', $service->check($sheet));
        $this->assertNull($sheet->fresh()->student_id_read);

        Process::fake(['*' => Process::result('garbage', 'boom', 1)]);
        $this->assertSame('error', $service->check($sheet));
    }
}
