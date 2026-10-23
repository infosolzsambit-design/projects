<?php

namespace Tests\Feature;

use App\Models\AnswerSheet;
use App\Models\QuestionAnswerSheetMapping;
use App\Models\User;
use App\Services\StudentIdCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrScanTest extends TestCase
{
    use RefreshDatabase;

    private QuestionAnswerSheetMapping $packet;

    /** What the fake ID reader "reads" off the next scan. */
    private ?string $nextRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());

        // The real reader runs Python — stand in for it.
        $this->mock(StudentIdCheckService::class, function ($mock) {
            $mock->shouldReceive('check')->andReturnUsing(function (AnswerSheet $sheet) {
                $status = $this->nextRead === null ? StudentIdCheckService::UNREADABLE : StudentIdCheckService::READ;
                AnswerSheet::whereKey($sheet->id)->update(['student_id_read' => $this->nextRead, 'roll_no_check_status' => $status, 'student_id_crop_path' => "/storage/student-id-crops/{$sheet->id}.png"]);

                return $status;
            });
        });

        $this->packet = QuestionAnswerSheetMapping::factory()->scanPending()->create(['packet_code' => 'PKT-A']);
        foreach (['10000000' => '2410001', '10000001' => '2410002', '10000002' => '2410003'] as $qr => $registration) {
            AnswerSheet::factory()->create([
                'question_answer_sheet_mapping_id' => $this->packet->id, 'subject_barcode' => $qr,
                'roll_no' => $registration, 'registration_no' => $registration, 'name' => "Student {$registration}", 'pdf_path' => null, 'pdf_name' => null,
            ]);
        }
    }

    private function scan(string $qr)
    {
        return $this->withApiKey()->post("/api/v1/qr/packets/{$this->packet->id}/scan", [
            'qr_code' => $qr,
            'pdf' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_scan_with_a_matching_student_id_is_stored_and_matched(): void
    {
        $this->nextRead = '2410001';
        $response = $this->scan('10000000')->assertStatus(201)
            ->assertJsonPath('data.sheet.id_status', 'matched')
            ->assertJsonPath('data.sheet.registration_no', '2410001')
            ->assertJsonPath('data.rescanned', false);

        $path = $response->json('data.sheet.pdf_path');
        $this->assertStringStartsWith("/storage/answer-sheets/{$this->packet->id}/", $path);
        Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
    }

    public function test_an_unknown_qr_code_is_rejected_and_nothing_is_stored(): void
    {
        $this->scan('99999999')->assertStatus(422)->assertJsonPath('errors.reason', 'unknown');
        $this->assertSame(0, AnswerSheet::whereNotNull('pdf_path')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_qr_code_from_another_packet_says_which_packet_it_belongs_to(): void
    {
        $other = QuestionAnswerSheetMapping::factory()->scanPending()->create(['packet_code' => 'PKT-B', 'batch' => '2025']);
        AnswerSheet::factory()->create(['question_answer_sheet_mapping_id' => $other->id, 'subject_barcode' => '20000000', 'registration_no' => '5550001', 'name' => 'Other Student', 'pdf_path' => null]);

        $this->scan('20000000')->assertStatus(422)
            ->assertJsonPath('errors.reason', 'other_packet')
            ->assertJsonPath('errors.other_packet.packet_code', 'PKT-B')
            ->assertJsonPath('errors.other_packet.batch', '2025')
            ->assertJsonPath('errors.student.registration_no', '5550001');
        $this->assertNull(AnswerSheet::where('subject_barcode', '20000000')->value('pdf_path'));
    }

    public function test_a_mismatch_can_be_confirmed_by_hand_or_rejected(): void
    {
        $this->nextRead = '2410999';
        $first = $this->scan('10000000')->assertStatus(201)->assertJsonPath('data.sheet.id_status', 'mismatch');
        $sheetId = $first->json('data.sheet.id');

        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/sheets/{$sheetId}/confirm")
            ->assertOk()->assertJsonPath('data.sheet.id_status', 'confirmed');
        $this->assertNotNull(AnswerSheet::find($sheetId)->student_id_verified_by);

        // Another one, unreadable → rejected: scan removed, file deleted.
        $this->nextRead = null;
        $second = $this->scan('10000001')->assertStatus(201)->assertJsonPath('data.sheet.id_status', 'unreadable');
        $path = substr($second->json('data.sheet.pdf_path'), strlen('/storage/'));
        $this->withApiKey()->deleteJson("/api/v1/qr/packets/{$this->packet->id}/sheets/{$second->json('data.sheet.id')}/scan")
            ->assertOk()->assertJsonPath('data.sheet.id_status', 'not_scanned');
        Storage::disk('public')->assertMissing($path);
    }

    public function test_scanning_a_sheet_again_replaces_the_earlier_scan(): void
    {
        $this->nextRead = '2410001';
        $old = substr($this->scan('10000000')->json('data.sheet.pdf_path'), strlen('/storage/'));
        $this->scan('10000000')->assertStatus(201)->assertJsonPath('data.rescanned', true);

        Storage::disk('public')->assertMissing($old);
        $this->assertCount(1, Storage::disk('public')->files("answer-sheets/{$this->packet->id}"));
    }

    public function test_complete_marks_the_unscanned_sheets_absent(): void
    {
        $this->nextRead = '2410999';
        $mismatch = $this->scan('10000000')->json('data.sheet.id');

        // An unchecked mismatch blocks completing.
        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/complete", ['mark_absent' => true])
            ->assertStatus(422)->assertJsonPath('errors.reason', 'needs_review');

        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/sheets/{$mismatch}/confirm")->assertOk();

        // Without mark_absent: just the summary.
        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/complete")
            ->assertOk()->assertJsonPath('data.unscanned', 2)->assertJsonPath('data.completed', false);
        $this->assertFalse($this->packet->fresh()->is_scan_complete);

        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/complete", ['mark_absent' => true])
            ->assertOk()->assertJsonPath('data.completed', true)->assertJsonPath('data.scanned', 1);

        $this->assertTrue($this->packet->fresh()->is_scan_complete);
        $this->assertSame(2, AnswerSheet::where('question_answer_sheet_mapping_id', $this->packet->id)->where('absent', true)->count());
        $this->assertFalse((bool) AnswerSheet::find($mismatch)->absent);

        // Now on Answer Sheets — absent sheets not in Total, not to assign, not listed — and no more scanning.
        $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings')->assertOk()
            ->assertJsonPath('data.items.0.id', $this->packet->id)
            ->assertJsonPath('data.items.0.answer_sheet_count', 1)
            ->assertJsonPath('data.items.0.absent_answer_sheet_count', 2)
            ->assertJsonPath('data.items.0.pending_answer_sheet_count', 1);
        $this->withApiKey()->getJson("/api/v1/answer-sheet-mappings/{$this->packet->id}/rows")->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.subject_barcode', '10000000');
    }

    public function test_after_complete_only_absent_students_copies_can_be_scanned(): void
    {
        $this->nextRead = '2410001';
        $this->scan('10000000')->assertStatus(201);
        $this->withApiKey()->postJson("/api/v1/qr/packets/{$this->packet->id}/complete", ['mark_absent' => true])->assertOk();

        // Scanned before Complete → can't be scanned again or rejected.
        $this->scan('10000000')->assertStatus(422)->assertJsonPath('errors.reason', 'already_scanned');
        $early = AnswerSheet::where('subject_barcode', '10000000')->first();
        $this->withApiKey()->deleteJson("/api/v1/qr/packets/{$this->packet->id}/sheets/{$early->id}/scan")->assertStatus(422);

        // An absent student's copy, matched → no longer absent, ready to assign.
        $this->nextRead = '2410002';
        $this->scan('10000001')->assertStatus(201)->assertJsonPath('data.sheet.id_status', 'matched')->assertJsonPath('data.sheet.absent', false);
        $this->withApiKey()->getJson('/api/v1/answer-sheet-mappings')->assertOk()
            ->assertJsonPath('data.items.0.answer_sheet_count', 2)
            ->assertJsonPath('data.items.0.absent_answer_sheet_count', 1)
            ->assertJsonPath('data.items.0.pending_answer_sheet_count', 2);

        // Another late copy whose ID doesn't match → rejected → absent again.
        $this->nextRead = '9999999';
        $late = $this->scan('10000002')->assertStatus(201)->assertJsonPath('data.sheet.id_status', 'mismatch')->json('data.sheet.id');
        $this->withApiKey()->deleteJson("/api/v1/qr/packets/{$this->packet->id}/sheets/{$late}/scan")
            ->assertOk()->assertJsonPath('data.sheet.absent', true)->assertJsonPath('data.sheet.id_status', 'not_scanned');
        $this->assertTrue((bool) AnswerSheet::find($late)->absent);
    }
}
