<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Helpers\ProgramLabel;
use App\Services\AuditLogService;
use App\Services\EvaluationResetMailService;
use App\Services\UserNotificationService;
use App\Traits\ApiResponse;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Backs the sidebar's "Reset Evaluation" page: look an answer sheet up by
 * barcode, see its evaluation, and wipe that evaluation so the same
 * teacher can redo it. A reset is only allowed while the sheet's own
 * evaluation window (evaluation_start_date .. evaluation_end_date) is
 * still open — otherwise the teacher would have no time left to redo it.
 * No permission gate yet (to be added later).
 */
class ResetEvaluationController extends Controller
{
    use ApiResponse, HasExamTypeScope, HasExamYearScope;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly UserNotificationService $notifications,
    ) {}

    /**
     * GET /reset-evaluation/search?barcode=.. — matches either the sheet's
     * own barcode or its subject barcode, exactly.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
        ]);
        $barcode = trim($data['barcode']);
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);

        $sheets = AnswerSheet::query()
            ->with(['mapping.course', 'mapping.examType', 'mapping.questionPaper', 'teacher.teacherDetail'])
            ->where(fn ($q) => $q->where('barcode', $barcode)->orWhere('subject_barcode', $barcode))
            ->whereHas('mapping', function ($q) use ($examYear, $examType) {
                if ($examYear !== null) {
                    $q->whereHas('questionPaper', fn ($q2) => $q2->where('exam_year', $examYear));
                }
                if ($examType !== null) {
                    $q->where('exam_type_id', $examType);
                }
            })
            ->orderByDesc('id')
            ->get();

        return $this->success($sheets->map(fn (AnswerSheet $sheet) => $this->present($sheet))->values(), 'Answer sheets fetched successfully.');
    }

    /**
     * POST /reset-evaluation/{answer_sheet}/reset — clears the submitted
     * marks and every bit of in-progress work (draft marks, breakdown,
     * annotations, consumed time, any live marking-screen session). The
     * sheet stays assigned to the same teacher with the same window.
     */
    public function reset(AnswerSheet $answerSheet): JsonResponse
    {
        if (! $this->isWithinWindow($answerSheet)) {
            return $this->error('Evaluation can only be reset while the answer sheet is within its evaluation start and end time.', 422);
        }
        if (! $this->hasEvaluation($answerSheet)) {
            return $this->error('This answer sheet has no evaluation to reset.', 422);
        }

        $oldValues = $answerSheet->only(['marks', 'draft_marks', 'consumed_time', 'evaluated_at']);

        $answerSheet->update([
            'marks' => null,
            'evaluated_at' => null,
            'draft_marks' => null,
            'draft_marks_breakdown' => null,
            'draft_annotations' => null,
            'consumed_time' => null,
            'evaluation_session_token' => null,
            'evaluation_session_expires_at' => null,
        ]);

        $this->auditLog->log(
            event: 'answer-sheet-evaluation-reset',
            module: 'Evaluation',
            description: "Reset the evaluation of answer sheet #{$answerSheet->id} ({$answerSheet->barcode}).",
            auditable: $answerSheet,
            oldValues: $oldValues,
            tags: ['evaluation', 'answer-sheet', 'reset'],
        );

        $answerSheet->load(['mapping.course', 'mapping.examType', 'mapping.questionPaper', 'teacher.teacherDetail']);

        // Tell the teacher it's back in their pending list — bell
        // notification now, email after the response (same as Resolve).
        if ($answerSheet->teacher_id) {
            $teacherId = (int) $answerSheet->teacher_id;
            $courseName = UserNotificationService::courseLabel($answerSheet->mapping?->course);
            $barcode = $answerSheet->subject_barcode ?: $answerSheet->barcode;

            $this->notifications->evaluationReset($teacherId, $answerSheet->id, $courseName, $barcode);

            $adminId = (int) auth()->id();
            $adminName = auth()->user()?->name ?? 'Administrator';
            $programName = ProgramLabel::display($answerSheet->mapping?->program_name);
            $departmentName = $answerSheet->mapping?->department_name;
            $resetAt = now()->format('d-m-Y h:i A');
            $endDate = $answerSheet->evaluation_end_date?->format('d-m-Y h:i A');
            $timePerSheet = $answerSheet->evaluation_time_per_sheet;

            dispatch(function () use ($adminId, $teacherId, $courseName, $programName, $departmentName, $barcode, $adminName, $resetAt, $endDate, $timePerSheet) {
                App::make(EvaluationResetMailService::class)->sendEvaluationResetEmail(
                    adminId: $adminId,
                    teacherId: $teacherId,
                    courseName: $courseName,
                    programName: $programName,
                    departmentName: $departmentName,
                    barcode: $barcode,
                    resetByName: $adminName,
                    resetAt: $resetAt,
                    evaluationEndDate: $endDate,
                    evaluationTimePerSheet: $timePerSheet,
                );
            })->afterResponse();
        }

        return $this->success($this->present($answerSheet), 'Evaluation reset successfully.');
    }

    private function isWithinWindow(AnswerSheet $sheet): bool
    {
        return $sheet->evaluation_start_date !== null
            && $sheet->evaluation_end_date !== null
            && now()->between($sheet->evaluation_start_date, $sheet->evaluation_end_date);
    }

    private function hasEvaluation(AnswerSheet $sheet): bool
    {
        return $sheet->marks !== null
            || $sheet->draft_marks !== null
            || ! empty($sheet->draft_annotations)
            || $sheet->consumed_time !== null;
    }

    private function present(AnswerSheet $sheet): array
    {
        $mapping = $sheet->mapping;

        return [
            'id' => $sheet->id,
            'barcode' => $sheet->barcode,
            'subject_barcode' => $sheet->subject_barcode,
            'program_name' => $mapping?->program_name,
            // The packet's own department (chosen on Answer Sheet Upload).
            'department_name' => $mapping?->department_name,
            'course_name' => $mapping?->course?->name,
            'course_code' => $mapping?->course?->code,
            'course_type' => $mapping?->course?->type,
            'exam_type_name' => $mapping?->examType?->name,
            'semester' => $mapping?->semester ?? $sheet->semester,
            'exam_year' => $mapping?->questionPaper?->exam_year,
            'pdf_url' => $sheet->pdf_path,
            'pdf_name' => $sheet->pdf_name,
            'marks' => $sheet->marks,
            'draft_marks' => $sheet->draft_marks,
            'max_marks' => $mapping?->questionPaper?->full_marks,
            'draft_annotations' => $sheet->draft_annotations,
            'evaluated_by' => $sheet->teacher ? [
                'name' => $sheet->teacher->name,
                'emp_code' => $sheet->teacher->teacherDetail?->emp_code,
                'email' => $sheet->teacher->email,
                'phone_no' => $sheet->teacher->phone_no,
            ] : null,
            'evaluation_start_date' => $sheet->evaluation_start_date?->format('Y-m-d H:i'),
            'evaluation_end_date' => $sheet->evaluation_end_date?->format('Y-m-d H:i'),
            'consumed_time' => $sheet->consumed_time,
            'evaluated_at' => $sheet->evaluated_at?->format('Y-m-d H:i'),
            'has_evaluation' => $this->hasEvaluation($sheet),
            'can_reset' => $this->isWithinWindow($sheet),
        ];
    }
}
