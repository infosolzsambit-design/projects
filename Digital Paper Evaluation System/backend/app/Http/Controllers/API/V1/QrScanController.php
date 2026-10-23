<?php

namespace App\Http\Controllers\API\V1;

use App\Helpers\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Qr\ScanAnswerSheetRequest;
use App\Models\AnswerSheet;
use App\Models\QuestionAnswerSheetMapping;
use App\Services\AuditLogService;
use App\Services\StudentIdCheckService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scan Papers → Scan (ScanPacketView.vue): scanning a Generate QR packet's
 * answer sheets one by one. Each scan's PDF is stored against the sheet its
 * QR code belongs to (like Answer Sheet Upload), then the handwritten
 * STUDENT'S ID NO. is read (StudentIdCheckService) and compared with the
 * sheet's registration number; a mismatch is confirmed or rejected by hand.
 * "Complete" marks the unscanned sheets absent and the packet scanned.
 *
 * After Complete, the same page (Answer Sheets → "Scan Pending Copy") scans
 * absent students' copies in late: only an absent sheet — or a late copy
 * still awaiting its ID review — can be scanned then; it stops being absent
 * (and becomes assignable). Rejecting such a late copy makes it absent
 * again. Sheets scanned before Complete can't be rescanned or rejected.
 */
class QrScanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /** GET /qr/packets/{mapping}/sheets — every sheet with its scan / ID-check state. */
    public function sheets(QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $sheets = $questionAnswerSheetMapping->answerSheets()->orderBy('id')->get()
            ->map(fn (AnswerSheet $sheet) => $this->sheetPayload($sheet));

        return $this->success([
            'is_scan_complete' => (bool) $questionAnswerSheetMapping->is_scan_complete,
            'sheets' => $sheets,
        ], 'Packet sheets fetched successfully.');
    }

    /**
     * POST /qr/packets/{mapping}/scan — one scanned answer sheet.
     * Rejected (nothing stored) when the QR code isn't one of ours
     * (errors.reason = unknown) or belongs to another packet
     * (errors.reason = other_packet, with that packet and student).
     * Scanning a sheet again replaces its earlier scan. Once the packet is
     * complete, only absent students' copies (errors.reason = already_scanned
     * otherwise) — see the class docblock.
     */
    public function scan(ScanAnswerSheetRequest $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping, StudentIdCheckService $idCheck): JsonResponse
    {
        $mapping = $questionAnswerSheetMapping;

        $qr = trim($request->string('qr_code')->toString());
        $sheet = AnswerSheet::where('subject_barcode', $qr)->where('question_answer_sheet_mapping_id', $mapping->id)->first();

        if (! $sheet) {
            $elsewhere = AnswerSheet::with('mapping.course:id,name,code')->where('subject_barcode', $qr)->latest('id')->first();
            if (! $elsewhere || ! $elsewhere->mapping) {
                return $this->error("QR code {$qr} is not a valid answer sheet QR code.", 422, ['reason' => 'unknown', 'qr_code' => $qr]);
            }
            $other = $elsewhere->mapping;

            return $this->error("QR code {$qr} is not in this packet — it belongs to packet {$other->packet_code}.", 422, [
                'reason' => 'other_packet',
                'qr_code' => $qr,
                'student' => ['registration_no' => $elsewhere->registration_no, 'name' => $elsewhere->name],
                'other_packet' => [
                    'id' => $other->id,
                    'packet_code' => $other->packet_code,
                    'program_name' => $other->program_name,
                    'course_name' => $other->course?->name,
                    'course_code' => $other->course?->code,
                    'semester' => $other->semester,
                    'department_name' => $other->department_name,
                    'batch' => $other->batch,
                ],
            ]);
        }

        if ($mapping->is_scan_complete && ! $this->isPendingCopy($sheet)) {
            return $this->error("QR code {$qr} ({$sheet->name}) was already scanned — only absent students' copies can be scanned now.", 422, [
                'reason' => 'already_scanned',
                'qr_code' => $qr,
                'student' => ['registration_no' => $sheet->registration_no, 'name' => $sheet->name],
            ]);
        }

        $rescanned = (bool) $sheet->pdf_path;
        $this->deleteScanFiles($sheet);

        // Plain store() — Laravel picks a unique file name (same as
        // QuestionAnswerSheetMappingController::storeRows()).
        $stored = $request->file('pdf')->store("answer-sheets/{$mapping->id}", 'public');
        PublicStorage::openFolder(dirname($stored));

        $sheet->update([
            'pdf_name' => basename($stored),
            'pdf_path' => '/storage/'.$stored,
            'absent' => false,
            'student_id_read' => null,
            'roll_no_check_status' => null,
            'student_id_crop_path' => null,
            'roll_no_checked_at' => null,
            'student_id_verified_by' => null,
            'student_id_verified_at' => null,
        ]);

        $idCheck->check($sheet->fresh());

        return $this->success([
            'rescanned' => $rescanned,
            'sheet' => $this->sheetPayload($sheet->fresh()),
        ], $rescanned ? 'Answer sheet scanned again.' : 'Answer sheet scanned.', 201);
    }

    /**
     * POST /qr/packets/{mapping}/sheets/{sheet}/confirm — the reviewer
     * checked the scan by eye: the ID written on it IS this student's.
     * Same as Generate Marksheet's confirmMatch().
     */
    public function confirm(Request $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->question_answer_sheet_mapping_id !== $questionAnswerSheetMapping->id || ! $answerSheet->pdf_path) {
            return $this->error('This answer sheet has not been scanned in this packet.', 422);
        }
        $rollDigits = StudentIdCheckService::digitsOnly($answerSheet->roll_no);
        if ($rollDigits === '') {
            return $this->error('This answer sheet has no registration number to confirm against.', 422);
        }

        $before = $answerSheet->student_id_read;
        AnswerSheet::whereKey($answerSheet->id)->update([
            'student_id_read' => $rollDigits,
            'roll_no_check_status' => StudentIdCheckService::READ,
            'student_id_verified_by' => $request->user()->id,
            'student_id_verified_at' => now(),
        ]);

        $this->auditLog->log(
            event: 'student-id-confirmed',
            module: 'Scan Papers',
            description: "Confirmed the written student ID matches registration no {$answerSheet->registration_no} (QR {$answerSheet->subject_barcode}).",
            oldValues: ['student_id_read' => $before],
            newValues: ['answer_sheet_id' => $answerSheet->id],
            tags: ['scan-papers', 'roll-number'],
        );

        return $this->success(['sheet' => $this->sheetPayload($answerSheet->fresh())], 'Student ID confirmed.');
    }

    /**
     * DELETE /qr/packets/{mapping}/sheets/{sheet}/scan — reject a scan, so
     * the sheet can be scanned again. In a completed packet only a late copy
     * still awaiting its ID review (not assigned yet) — it becomes absent again.
     */
    public function removeScan(QuestionAnswerSheetMapping $questionAnswerSheetMapping, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->question_answer_sheet_mapping_id !== $questionAnswerSheetMapping->id) {
            return $this->notFound('This answer sheet is not in this packet.');
        }
        $complete = (bool) $questionAnswerSheetMapping->is_scan_complete;
        if ($complete && (! $answerSheet->pdf_path || ! $this->isPendingCopy($answerSheet))) {
            return $this->error('This answer sheet was scanned before the packet was completed — it can\'t be rejected now.', 422);
        }

        $this->deleteScanFiles($answerSheet);
        $answerSheet->update([
            // A late copy rejected → the student is absent again.
            'absent' => $complete,
            'pdf_name' => null,
            'pdf_path' => null,
            'student_id_read' => null,
            'roll_no_check_status' => null,
            'student_id_crop_path' => null,
            'roll_no_checked_at' => null,
            'student_id_verified_by' => null,
            'student_id_verified_at' => null,
        ]);

        return $this->success(['sheet' => $this->sheetPayload($answerSheet->fresh())], 'Scan removed — scan this sheet again.');
    }

    /**
     * POST /qr/packets/{mapping}/complete — finish the packet's scan.
     * Without mark_absent=1 it only reports what's left (unscanned sheets,
     * ID mismatches not yet confirmed). With it, every unscanned sheet is
     * marked absent and the packet becomes is_scan_complete = 1 (it then
     * shows on Answer Sheets and can be assigned). Not allowed while a
     * scan's ID still needs review.
     */
    public function complete(Request $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $mapping = $questionAnswerSheetMapping;
        if ($mapping->is_scan_complete) {
            return $this->error('This packet\'s scan is already complete.', 422);
        }

        $sheets = $mapping->answerSheets()->get();
        $unscanned = $sheets->whereNull('pdf_path');
        $needsReview = $sheets->filter(fn (AnswerSheet $s) => $s->pdf_path && ! in_array($this->idStatus($s), ['matched', 'confirmed'], true));
        $summary = [
            'total' => $sheets->count(),
            'scanned' => $sheets->count() - $unscanned->count(),
            'unscanned' => $unscanned->count(),
            'needs_review' => $needsReview->count(),
        ];

        if ($needsReview->isNotEmpty()) {
            return $this->error("{$needsReview->count()} scanned sheet(s) still need their student ID checked — confirm or reject them first.", 422, ['reason' => 'needs_review'] + $summary);
        }
        if (! $request->boolean('mark_absent')) {
            return $this->success($summary + ['completed' => false], $unscanned->isEmpty() ? 'Every sheet is scanned.' : "{$unscanned->count()} sheet(s) are not scanned.");
        }

        DB::transaction(function () use ($mapping, $unscanned) {
            AnswerSheet::whereIn('id', $unscanned->pluck('id'))->update(['absent' => true]);
            $mapping->update(['is_scan_complete' => true]);
        });

        $this->auditLog->log(
            event: 'answer-sheets-scan-completed',
            module: 'Scan Papers',
            description: "Completed the scan of packet {$mapping->packet_code}: {$summary['scanned']} scanned, {$summary['unscanned']} marked absent.",
            newValues: ['question_answer_sheet_mapping_id' => $mapping->id] + $summary,
            tags: ['scan-papers'],
        );

        return $this->success($summary + ['completed' => true], 'Scan complete.');
    }

    /**
     * In a completed packet, may this sheet be scanned (or its scan
     * rejected)? An absent student's, or a late copy still awaiting its ID
     * review that no teacher has yet — sheets scanned before Complete all had
     * their ID matched or confirmed (Complete requires it).
     */
    private function isPendingCopy(AnswerSheet $sheet): bool
    {
        if ($sheet->absent) {
            return true;
        }

        return $sheet->pdf_path
            && $sheet->teacher_id === null
            && $sheet->answer_sheet_pool_id === null
            && ! in_array($this->idStatus($sheet), ['matched', 'confirmed'], true);
    }

    /**
     * matched     — the ID read off the sheet is its registration number
     * confirmed   — checked by hand (Confirm in the review modal)
     * mismatch    — read, but a different number
     * unreadable  — nothing could be read
     * error       — the reader failed
     * not_checked — scanned, not read yet
     * not_scanned — no scan yet
     */
    private function idStatus(AnswerSheet $sheet): string
    {
        if (! $sheet->pdf_path) {
            return 'not_scanned';
        }
        if ($sheet->student_id_verified_by) {
            return 'confirmed';
        }

        return match ($sheet->roll_no_check_status) {
            StudentIdCheckService::READ => $sheet->student_id_read === StudentIdCheckService::digitsOnly($sheet->roll_no) ? 'matched' : 'mismatch',
            StudentIdCheckService::UNREADABLE => 'unreadable',
            StudentIdCheckService::ERROR => 'error',
            default => 'not_checked',
        };
    }

    private function sheetPayload(AnswerSheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'qr_code' => $sheet->subject_barcode,
            'registration_no' => $sheet->registration_no,
            'name' => $sheet->name,
            'scanned' => (bool) $sheet->pdf_path,
            'pdf_path' => $sheet->pdf_path,
            'absent' => (bool) $sheet->absent,
            'id_status' => $this->idStatus($sheet),
            'student_id_read' => $sheet->student_id_read,
            'student_id_crop_path' => $sheet->student_id_crop_path,
        ];
    }

    /** The sheet's scanned PDF and ID crop, if any (a rescan or a rejected scan). */
    private function deleteScanFiles(AnswerSheet $sheet): void
    {
        foreach ([$sheet->pdf_path, $sheet->student_id_crop_path] as $path) {
            if ($path && str_starts_with($path, '/storage/')) {
                Storage::disk('public')->delete(Str::after($path, '/storage/'));
            }
        }
    }
}
