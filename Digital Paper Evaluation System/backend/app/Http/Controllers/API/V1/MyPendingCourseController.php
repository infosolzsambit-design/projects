<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerSheetResource;
use App\Models\AnswerSheet;
use App\Models\GeneralSetting;
use App\Services\AuditLogService;
use App\Services\IssueRaisedMailService;
use App\Traits\ApiResponse;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
 *
 * Also exam-year-scoped (see HasExamYearScope) via the sheet's packet's
 * own question paper — a non-super-admin only ever sees one exam year's
 * worth of pending work here, matching AppHeader.vue's Exam Year picker.
 */
class MyPendingCourseController extends Controller
{
    use ApiResponse, HasExamTypeScope, HasExamYearScope;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly IssueRaisedMailService $issueRaisedMail,
    ) {}

    /**
     * GET /my-pending-courses — the subject chips across the top of
     * subject-list.html. One row per distinct course this teacher has
     * pending sheets for, with how many are pending.
     */
    public function subjects(Request $request): JsonResponse
    {
        // Only ever adds a second join (on an indexed column,
        // question_papers.id — the FK side of qasm.question_paper_id,
        // itself indexed too) when the scope is actually non-null, so a
        // super admin's request pays nothing extra for it.
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);

        $courses = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as qasm', 'qasm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses', 'courses.id', '=', 'qasm.course_id')
            ->when($examYear !== null, fn ($q) => $q
                ->join('question_papers as qp', 'qp.id', '=', 'qasm.question_paper_id')
                ->where('qp.exam_year', $examYear))
            ->when($examType !== null, fn ($q) => $q->where('qasm.exam_type_id', $examType))
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
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);

        $sheets = AnswerSheet::query()
            ->with(['mapping.questionPaper', 'issueMaster'])
            ->whereHas('mapping', function ($q) use ($data, $examYear, $examType) {
                $q->where('course_id', $data['course_id']);
                if ($examYear !== null) {
                    $q->whereHas('questionPaper', fn ($q2) => $q2->where('exam_year', $examYear));
                }
                if ($examType !== null) {
                    $q->where('exam_type_id', $examType);
                }
            })
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
     * Also mints a brand-new, random evaluation_session_token here (every
     * click rotates it, superseding whatever token an earlier click may
     * have issued) — this is what the marking screen's own URL
     * (/my-pending-courses/:token/evaluate) is actually built from, not
     * this sheet's own id (see showByToken() below). A copied/bookmarked
     * link stops working once it expires (session_token_ttl_minutes) or
     * once a later legitimate click supersedes it — it's never meant to
     * be reusable on its own.
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

        $token = Str::random(48);
        $answerSheet->update([
            'evaluation_session_token' => $token,
            'evaluation_session_expires_at' => now()->addMinutes((int) config('evaluation.session_token_ttl_minutes')),
        ]);

        return $this->success([
            'face_scan_required' => $faceScanRequired,
            'has_face_profile' => ! empty($teacherDetail?->face_descriptor),
            'evaluation_token' => $token,
        ], 'Evaluation start check completed.');
    }

    /**
     * GET /my-pending-courses/evaluate/{token} — everything
     * EvaluatePaperView.vue needs to open the real marking screen for one
     * sheet: the sheet itself, its PDF, and the *real* question-paper
     * structure it's being marked against (mapping.questionPaper.groups —
     * see AnswerSheetResource's own 'question_paper' field and
     * QuestionPaperResource::buildTree()), not a hardcoded scheme.
     *
     * Looked up by the one-time token startEvaluation() above just
     * minted, not this sheet's own id — a 404 (not a 403) for a token
     * that's unknown, expired, belongs to someone else's sheet, or whose
     * sheet has since been completed, same "leaks nothing extra" shape as
     * every other guard in this controller.
     */
    public function showByToken(Request $request, string $token): JsonResponse
    {
        $answerSheet = AnswerSheet::where('evaluation_session_token', $token)->first();

        if (
            ! $answerSheet
            || $answerSheet->teacher_id !== $request->user()->id
            || $answerSheet->marks !== null
            || $answerSheet->evaluation_session_expires_at === null
            || $answerSheet->evaluation_session_expires_at->isPast()
        ) {
            return $this->notFound('This evaluation link is no longer valid. Please start evaluation again from your pending list.');
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
            'evaluated_at' => now(),
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

    /**
     * POST /my-pending-courses/papers/{answer_sheet}/raise-issue — backs
     * two entry points that both land here: EvaluatePaperView.vue's
     * "Problem" action (its dropdown locked to Printing Issue — see
     * RaiseIssueModal.vue's `locked` prop) and MyPendingCoursesView.vue's
     * own "Raise Issue" row action, which lets the teacher genuinely pick
     * the issue type (Printing Issue or Timing Issue) themselves. Either
     * way, issue_master_id is only ever trusted when it's a real, active
     * issue_masters id (Rule::exists below) — resolved by id, never by
     * matching a name string — and defaults to
     * config('issues.printing_issue_id') when the caller omits it
     * entirely, which is exactly what the locked "Problem" flow does.
     *
     * Raising a *Printing* issue also wipes this sheet's evaluation
     * progress back to a clean slate (marks/draft_marks/
     * draft_marks_breakdown/draft_annotations/consumed_time all null —
     * same reasoning as AssignTeacherService::reassign()'s own reset) so
     * it's ready to be re-attempted from scratch once the physical sheet
     * itself gets fixed (see NotificationController::
     * resolvePrintingIssue()) — the sheet also can't be evaluated at all
     * while that's still open (see AnswerSheetResource's own
     * 'blocks_evaluation' field). A *Timing* issue is only about the
     * evaluation window being wrong, not the sheet itself, so none of that
     * progress is touched — the teacher can keep working right through it
     * if they want, and just gets a new window once an admin resolves it
     * (see NotificationController::resolveTimingIssue()).
     */
    public function raiseIssue(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->teacher_id !== $request->user()->id || $answerSheet->marks !== null) {
            return $this->notFound('No pending answer sheet found.');
        }

        $data = $request->validate([
            'issue_master_id' => [
                'nullable', 'integer',
                Rule::exists('issue_masters', 'id')->where('status', true)->whereNull('deleted_at'),
            ],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $issueMasterId = $data['issue_master_id'] ?? (int) config('issues.printing_issue_id');
        $isPrintingIssue = $issueMasterId === (int) config('issues.printing_issue_id');

        $answerSheet->update(array_merge([
            'issue_master_id' => $issueMasterId,
            'issue_raised_by' => $request->user()->id,
            'issue_raised_at' => now(),
            'issue_status' => 'open',
            'issue_remarks' => $data['remarks'],
        ], $isPrintingIssue ? [
            'marks' => null,
            'draft_marks' => null,
            'draft_marks_breakdown' => null,
            'draft_annotations' => null,
            'consumed_time' => null,
        ] : []));

        $this->auditLog->log(
            event: 'answer-sheet-issue-raised',
            module: 'Evaluation',
            description: "Raised an issue on answer sheet #{$answerSheet->id} ({$answerSheet->roll_no}): {$data['remarks']}",
            auditable: $answerSheet,
            newValues: ['issue_master_id' => $issueMasterId, 'issue_remarks' => $data['remarks']],
            tags: ['evaluation', 'answer-sheet', 'issue'],
        );

        // Every admin (config('roles.admin_recived_issue_mail')) gets an
        // email straight away, no review step — unlike the assignment
        // flow, there's nothing here for anyone to edit first. Dispatched
        // ->afterResponse() so the teacher's own success response isn't
        // held up by SendGrid (see IssueRaisedMailService, AssignTeacher
        // Controller for the same pattern).
        $answerSheet->loadMissing(['mapping.course', 'issueMaster']);
        $teacherId = $request->user()->id;
        $teacherName = $request->user()->name;
        $teacherEmpCode = $request->user()->loadMissing('teacherDetail')->teacherDetail?->emp_code;
        $issueTypeName = $answerSheet->issueMaster?->name ?? 'Issue';
        $courseName = $answerSheet->mapping?->course
            ? "{$answerSheet->mapping->course->name} ({$answerSheet->mapping->course->code})"
            : 'the course';
        $rollNo = (string) $answerSheet->roll_no;
        $barcode = $answerSheet->subject_barcode;
        $raisedAt = $answerSheet->issue_raised_at->format('d-m-Y h:i A');
        $remarks = $data['remarks'];
        $evaluationTimePerSheet = $answerSheet->evaluation_time_per_sheet;

        dispatch(function () use ($teacherId, $teacherName, $teacherEmpCode, $isPrintingIssue, $issueTypeName, $remarks, $courseName, $rollNo, $barcode, $raisedAt, $evaluationTimePerSheet) {
            App::make(IssueRaisedMailService::class)->sendIssueRaisedEmails(
                teacherId: $teacherId,
                teacherName: $teacherName,
                teacherEmpCode: $teacherEmpCode,
                isPrintingIssue: $isPrintingIssue,
                issueTypeName: $issueTypeName,
                remarks: $remarks,
                courseName: $courseName,
                rollNo: $rollNo,
                barcode: $barcode,
                raisedAt: $raisedAt,
                evaluationTimePerSheet: $evaluationTimePerSheet,
            );
        })->afterResponse();

        return $this->success(new AnswerSheetResource($answerSheet), 'Issue raised successfully.');
    }
}
