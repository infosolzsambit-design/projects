<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerSheetResource;
use App\Models\AnswerSheet;
use App\Models\GeneralSetting;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the sidebar's "My Pending Course" menu (designed_files/
 * subject-list.html) — the logged-in teacher's own not-yet-evaluated
 * answer sheets, grouped by the *actual assigned course* (via each
 * sheet's packet — question_answer_sheet_mapping.course_id — same source
 * TeacherController::assignments() and AssignTeacherService already treat
 * as the real course). Deliberately not grouped by the sheet's own
 * subject_code/subject_name — those are free-text fields lifted straight
 * from the exam center's upload CSV and can name something other than
 * the course the packet was actually mapped to. Purely self-service:
 * every query below is scoped to $request->user()->id, never another
 * teacher's work, so — unlike almost every other controller in this app
 * — this one is deliberately left without a permission gate (any
 * authenticated user simply sees their own pending work, or an empty
 * list if they have none).
 *
 * "Pending" here means assigned to this teacher (teacher_id = them) but
 * not yet marked — marks IS NULL. Once evaluation is scored, marks gets
 * filled in and the sheet drops out of this list on its own.
 */
class MyPendingCourseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * GET /my-pending-courses — the subject chips across the top of
     * subject-list.html. One row per distinct course this teacher has
     * pending sheets for, with how many are pending.
     */
    public function subjects(Request $request): JsonResponse
    {
        $courses = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as qasm', 'qasm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses', 'courses.id', '=', 'qasm.course_id')
            ->where('answer_sheets.teacher_id', $request->user()->id)
            ->whereNull('answer_sheets.marks')
            ->whereNull('qasm.deleted_at')
            ->whereNull('courses.deleted_at')
            ->selectRaw('courses.id as course_id, courses.code as course_code, courses.name as course_name, COUNT(*) as pending_count')
            ->groupBy('courses.id', 'courses.code', 'courses.name')
            ->orderBy('courses.name')
            ->get()
            ->map(fn ($row) => [
                'course_id' => (int) $row->course_id,
                'course_code' => $row->course_code,
                'course_name' => $row->course_name,
                'pending_count' => (int) $row->pending_count,
            ]);

        return $this->success($courses, 'Pending courses fetched successfully.');
    }

    /**
     * GET /my-pending-courses/papers?course_id=.. — the paper-by-paper
     * list below the chips, for whichever course is currently selected on
     * the page.
     */
    public function papers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
        ]);

        $sheets = AnswerSheet::query()
            ->with('mapping.questionPaper')
            ->whereHas('mapping', fn ($q) => $q->where('course_id', $data['course_id']))
            ->where('teacher_id', $request->user()->id)
            ->whereNull('marks')
            ->orderBy('id')
            ->get();

        return $this->success(AnswerSheetResource::collection($sheets), 'Pending papers fetched successfully.');
    }

    /**
     * POST /my-pending-courses/papers/{answer_sheet}/start-evaluation —
     * the API-driven check MyPendingCoursesView.vue's "Start Evaluate"
     * button fires before doing anything else, so the face-scan gate can
     * never be skipped by a client that just decides not to ask:
     *
     *  1. general_settings.face_scan_applicable = 'no' → no scan needed
     *     for anyone, full stop.
     *  2. Otherwise, defer to this specific teacher's own
     *     teacher_details.face_scan_applicable (see TeacherDetail /
     *     FaceScanApplicableModal.vue) — 0 means this teacher is exempt
     *     even though the setting's on generally.
     *
     * Only ever answers for the calling teacher's own sheet — a 404 (not
     * a 403) for someone else's, or one no longer pending, so this leaks
     * no more than the sibling endpoints above already do.
     */
    public function startEvaluation(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->teacher_id !== $request->user()->id || $answerSheet->marks !== null) {
            return $this->notFound('No pending answer sheet found.');
        }

        $globallyApplicable = GeneralSetting::where('field_name', 'face_scan_applicable')->value('value') !== 'no';

        $teacherDetail = $request->user()->teacherDetail;
        $faceScanRequired = $globallyApplicable && (bool) ($teacherDetail?->face_scan_applicable ?? true);

        return $this->success([
            'face_scan_required' => $faceScanRequired,
            'has_face_profile' => ! empty($teacherDetail?->face_descriptor),
        ], 'Evaluation start check completed.');
    }

    /**
     * GET /my-pending-courses/papers/{answer_sheet} — everything
     * EvaluatePaperView.vue needs to open the real marking screen for one
     * sheet: the sheet itself, its PDF, and the *real* question-paper
     * structure it's being marked against (mapping.questionPaper.groups —
     * see AnswerSheetResource's own 'question_paper' field and
     * QuestionPaperResource::buildTree()), not a hardcoded scheme. Same
     * ownership/pending guard as startEvaluation() above.
     */
    public function show(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->teacher_id !== $request->user()->id || $answerSheet->marks !== null) {
            return $this->notFound('No pending answer sheet found.');
        }

        $answerSheet->load('mapping.questionPaper.groups');

        return $this->success(new AnswerSheetResource($answerSheet), 'Answer sheet fetched successfully.');
    }

    /**
     * POST /my-pending-courses/papers/{answer_sheet}/submit-marks — the
     * "Complete" action on EvaluatePaperView.vue (icon rail, not the old
     * header "Submit" — see that view's own docblock). Only ever accepts
     * the final total (the per-question sidebar is a UI aid to arrive at
     * a well-justified number against the real question structure — see
     * show() above; its own breakdown is what saveDraft() below persists
     * along the way, not this). Bounded by the question paper's own
     * full_marks when known. Once marks is set the sheet drops off this
     * teacher's pending list on its own (see subjects()/papers()'s own
     * whereNull('marks')) — no separate "status" flag needed.
     */
    public function submitMarks(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->teacher_id !== $request->user()->id || $answerSheet->marks !== null) {
            return $this->notFound('No pending answer sheet found.');
        }

        $answerSheet->load('mapping.questionPaper');
        $maxMarks = $answerSheet->mapping?->questionPaper?->full_marks;

        $rules = ['required', 'numeric', 'min:0'];
        if ($maxMarks !== null) {
            $rules[] = "max:{$maxMarks}";
        }
        $data = $request->validate([
            'marks' => $rules,
            // How long this teacher actually spent, in whole seconds —
            // optional (a client that somehow never got a tick in is
            // still allowed to complete), just persisted for the record.
            'consumed_time' => ['nullable', 'integer', 'min:0'],
        ]);

        $answerSheet->update([
            'marks' => $data['marks'],
            'consumed_time' => $data['consumed_time'] ?? $answerSheet->consumed_time,
        ]);

        $this->auditLog->log(
            event: 'answer-sheet-evaluated',
            module: 'Evaluation',
            description: "Submitted {$data['marks']} mark(s) for answer sheet #{$answerSheet->id} ({$answerSheet->roll_no}).",
            auditable: $answerSheet,
            newValues: ['marks' => $data['marks']],
            tags: ['evaluation', 'answer-sheet'],
        );

        return $this->success(new AnswerSheetResource($answerSheet), 'Marks submitted successfully.');
    }

    /**
     * POST /my-pending-courses/papers/{answer_sheet}/save-draft —
     * EvaluatePaperView.vue's autosave: fires on a debounce after any
     * marks/annotation change, plus a plain periodic timer regardless of
     * changes, so a browser crash or the machine simply turning off mid-
     * evaluation loses at most a few seconds of work, not the whole
     * session. Deliberately lightweight and silent — no audit log entry
     * (disableAuditing() below; see AnswerSheet::$auditExclude's own
     * docblock for why these fields are excluded even when auditing is
     * on) and no business-rule bounds-checking on the numbers beyond
     * "not negative", since none of this is final: only submitMarks()
     * above (via "Complete") actually counts as evaluating the sheet.
     */
    public function saveDraft(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->teacher_id !== $request->user()->id || $answerSheet->marks !== null) {
            return $this->notFound('No pending answer sheet found.');
        }

        $data = $request->validate([
            'marks_breakdown' => ['nullable', 'array'],
            'marks_breakdown.*' => ['numeric', 'min:0'],
            'annotations' => ['nullable', 'array'],
            'consumed_time' => ['nullable', 'integer', 'min:0'],
        ]);

        $draftMarks = collect($data['marks_breakdown'] ?? [])->sum(fn ($v) => (float) $v);

        AnswerSheet::disableAuditing();
        try {
            $answerSheet->update([
                'draft_marks' => $draftMarks,
                'draft_marks_breakdown' => $data['marks_breakdown'] ?? null,
                'draft_annotations' => $data['annotations'] ?? null,
                'consumed_time' => $data['consumed_time'] ?? $answerSheet->consumed_time,
            ]);
        } finally {
            AnswerSheet::enableAuditing();
        }

        return $this->success(['saved_at' => now()->toIso8601String()], 'Draft saved.');
    }
}
