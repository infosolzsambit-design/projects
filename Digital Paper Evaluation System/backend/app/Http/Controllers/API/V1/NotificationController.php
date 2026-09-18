<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AnswerSheet;
use App\Services\AuditLogService;
use App\Services\IssueResolvedMailService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Backs the sidebar's "Notifications" page — every evaluation issue a
 * teacher has raised against an answer sheet (see
 * MyPendingCourseController::raiseIssue()), most recent first, open or
 * already resolved. A single shared, system-wide feed — deliberately no
 * permission gate or per-user scoping, same as My Pending/Completed
 * Course and the rest of this issue-tracking feature so far.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * GET /notifications — same params as NotificationsView.vue's own
     * header search box and "Filter" dropdown (see designed_files/
     * subject-list.html for that dropdown's shape):
     *  - ?search=          → matches unique number (barcode/subject_barcode/
     *    roll_no), course name/code, remarks, and who raised it
     *  - ?issue_type=printing|timing → resolved by id (config('issues.*')),
     *    never by matching the issue's name string
     *  - ?status=open|resolved
     *  - ?raised_from=, ?raised_to= → date range on issue_raised_at
     *
     * Always ordered open issues first, resolved ones after — the sidebar
     * badge is only about what still needs attention, so the list itself
     * surfaces that same set first — and within each of those two groups,
     * most recently raised (highest id) first.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AnswerSheet::query()
            ->whereNotNull('issue_master_id')
            ->with(['issueMaster', 'issueRaisedBy', 'issueFixedBy', 'mapping.course']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    ->orWhere('subject_barcode', 'like', "%{$search}%")
                    ->orWhere('roll_no', 'like', "%{$search}%")
                    ->orWhere('issue_remarks', 'like', "%{$search}%")
                    ->orWhereHas('mapping.course', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('issueRaisedBy', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('issue_type')) {
            $issueType = $request->string('issue_type')->toString();
            if ($issueType === 'printing') {
                $query->where('issue_master_id', (int) config('issues.printing_issue_id'));
            } elseif ($issueType === 'timing') {
                $query->where('issue_master_id', (int) config('issues.timing_issue_id'));
            }
        }

        if ($request->filled('status') && in_array($request->input('status'), ['open', 'resolved'], true)) {
            $query->where('issue_status', $request->input('status'));
        }

        if ($request->filled('raised_from')) {
            $query->whereDate('issue_raised_at', '>=', $request->input('raised_from'));
        }
        if ($request->filled('raised_to')) {
            $query->whereDate('issue_raised_at', '<=', $request->input('raised_to'));
        }

        $sheets = $query
            ->orderByRaw("CASE WHEN issue_status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return $this->success(NotificationResource::collection($sheets), 'Notifications fetched successfully.');
    }

    /**
     * GET /notifications/unresolved-count — just the number, for the
     * sidebar's own badge next to "Notifications" (see AppSidebar.vue) —
     * every page needs this, not just the Notifications page itself, so
     * it's its own lightweight endpoint rather than making every page load
     * the full list just to count it client-side.
     */
    public function unresolvedCount(): JsonResponse
    {
        $count = AnswerSheet::query()->where('issue_status', 'open')->count();

        return $this->success(['count' => $count], 'Unresolved notification count fetched successfully.');
    }

    /**
     * POST /notifications/{answer_sheet}/resolve-timing-issue — a Timing
     * Issue never touched marks/draft_marks/draft_marks_breakdown/
     * draft_annotations/consumed_time in the first place (see
     * raiseIssue()'s own docblock), so resolving one is purely a
     * reschedule: a brand new evaluation window (and, optionally, a new
     * per-sheet time budget) for the same in-progress evaluation to
     * continue against.
     *
     * evaluation_start_date can't be moved *earlier* than whatever this
     * sheet's start date already was — only later, or left where it is —
     * so an admin can push a missed window forward but never backdate one
     * that's already passed; evaluation_end_date only has to land after
     * whichever start date is actually being submitted.
     */
    public function resolveTimingIssue(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        $timingIssueId = (int) config('issues.timing_issue_id');
        if ((int) $answerSheet->issue_master_id !== $timingIssueId || $answerSheet->issue_status !== 'open') {
            return $this->notFound('No open timing issue found for this answer sheet.');
        }

        $data = $request->validate([
            'evaluation_start_date' => ['required', 'date'],
            'evaluation_end_date' => ['required', 'date', 'after:evaluation_start_date'],
            'evaluation_time_per_sheet' => ['nullable', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($answerSheet->evaluation_start_date && Carbon::parse($data['evaluation_start_date'])->lt($answerSheet->evaluation_start_date)) {
            throw ValidationException::withMessages([
                'evaluation_start_date' => ["The start date can't be earlier than this sheet's current start date ({$answerSheet->evaluation_start_date->format('Y-m-d H:i')})."],
            ]);
        }

        $answerSheet->update([
            'evaluation_start_date' => $data['evaluation_start_date'],
            'evaluation_end_date' => $data['evaluation_end_date'],
            'evaluation_time_per_sheet' => $data['evaluation_time_per_sheet'] ?? $answerSheet->evaluation_time_per_sheet,
            'issue_status' => 'resolved',
            'issue_fixed_by' => $request->user()->id,
            'issue_fixed_at' => now(),
            'issue_admin_remarks' => $data['remarks'] ?? null,
        ]);

        $this->auditLog->log(
            event: 'answer-sheet-timing-issue-resolved',
            module: 'Evaluation',
            description: "Resolved the timing issue on answer sheet #{$answerSheet->id} ({$answerSheet->roll_no}) with a new evaluation window.",
            auditable: $answerSheet,
            newValues: [
                'evaluation_start_date' => $data['evaluation_start_date'],
                'evaluation_end_date' => $data['evaluation_end_date'],
            ],
            tags: ['evaluation', 'answer-sheet', 'issue'],
        );

        $this->sendResolvedEmail($request, $answerSheet, $data['remarks'] ?? null, [
            'evaluation_start_date' => $data['evaluation_start_date'],
            'evaluation_end_date' => $data['evaluation_end_date'],
        ]);

        return $this->success(new NotificationResource($answerSheet->fresh(['issueMaster', 'issueRaisedBy', 'issueFixedBy', 'mapping.course'])), 'Timing issue resolved successfully.');
    }

    /**
     * POST /notifications/{answer_sheet}/resolve-printing-issue —
     * replaces this sheet's own scanned PDF (the physical thing a
     * Printing Issue is actually about) and reopens it for evaluation
     * (see AnswerSheetResource's own 'blocks_evaluation' field, which
     * this clears simply by flipping issue_status to 'resolved').
     *
     * The replacement PDF's own QR code (decoded client-side — see
     * utils/qr.js, the same mechanism the original bulk-upload flow
     * already trusts) must match this exact sheet's subject_barcode, not
     * merely be present — otherwise an admin could silently attach any
     * other student's scan to this row. That match is enforced here, not
     * just in the UI: `qr_code` must equal subject_barcode or the whole
     * request is rejected.
     */
    public function resolvePrintingIssue(Request $request, AnswerSheet $answerSheet): JsonResponse
    {
        $printingIssueId = (int) config('issues.printing_issue_id');
        if ((int) $answerSheet->issue_master_id !== $printingIssueId || $answerSheet->issue_status !== 'open') {
            return $this->notFound('No open printing issue found for this answer sheet.');
        }

        $data = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'qr_code' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['qr_code'] !== $answerSheet->subject_barcode) {
            throw ValidationException::withMessages([
                'qr_code' => ["The uploaded PDF's QR code does not match this answer sheet's barcode ({$answerSheet->subject_barcode})."],
            ]);
        }

        // Same convention as QuestionAnswerSheetMappingController::
        // storeRows() — Laravel generates the filename itself, and the old
        // file (if this sheet ever had one) is removed so a printing issue
        // doesn't just accumulate orphaned scans on disk.
        if ($answerSheet->pdf_path && str_starts_with($answerSheet->pdf_path, '/storage/')) {
            $oldPath = substr($answerSheet->pdf_path, strlen('/storage/'));
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $stored = $request->file('pdf')->store("answer-sheets/{$answerSheet->question_answer_sheet_mapping_id}", 'public');

        $answerSheet->update([
            'pdf_name' => basename($stored),
            'pdf_path' => '/storage/'.$stored,
            'issue_status' => 'resolved',
            'issue_fixed_by' => $request->user()->id,
            'issue_fixed_at' => now(),
            'issue_admin_remarks' => $data['remarks'] ?? null,
        ]);

        $this->auditLog->log(
            event: 'answer-sheet-printing-issue-resolved',
            module: 'Evaluation',
            description: "Resolved the printing issue on answer sheet #{$answerSheet->id} ({$answerSheet->roll_no}) by replacing its PDF.",
            auditable: $answerSheet,
            newValues: ['pdf_name' => basename($stored)],
            tags: ['evaluation', 'answer-sheet', 'issue'],
        );

        $this->sendResolvedEmail($request, $answerSheet, $data['remarks'] ?? null);

        return $this->success(new NotificationResource($answerSheet->fresh(['issueMaster', 'issueRaisedBy', 'issueFixedBy', 'mapping.course'])), 'Printing issue resolved successfully.');
    }

    /**
     * Shared by both resolve methods above — the teacher who originally
     * raised the issue (issue_raised_by, stamped by raiseIssue() and never
     * touched since) gets an email the moment it's resolved, no review
     * step (see IssueResolvedMailService). Dispatched ->afterResponse() so
     * the admin's own success response/page refresh isn't held up by
     * SendGrid — same pattern as IssueRaisedMailService's own dispatch in
     * MyPendingCourseController::raiseIssue().
     *
     * @param  array{evaluation_start_date?:string,evaluation_end_date?:string}  $newWindow  only ever passed by resolveTimingIssue()
     */
    private function sendResolvedEmail(Request $request, AnswerSheet $answerSheet, ?string $adminRemarks, array $newWindow = []): void
    {
        $answerSheet->loadMissing(['mapping.course', 'issueMaster', 'issueRaisedBy']);

        $teacherId = $answerSheet->issue_raised_by;
        if (! $teacherId) {
            return;
        }

        $adminId = $request->user()->id;
        $adminName = $request->user()->name;
        $isPrintingIssue = (int) $answerSheet->issue_master_id === (int) config('issues.printing_issue_id');
        $issueTypeName = $answerSheet->issueMaster?->name ?? 'Issue';
        $courseName = $answerSheet->mapping?->course
            ? "{$answerSheet->mapping->course->name} ({$answerSheet->mapping->course->code})"
            : 'the course';
        $barcode = $answerSheet->subject_barcode;
        $resolvedAt = $answerSheet->issue_fixed_at->format('d-m-Y h:i A');
        $newStart = isset($newWindow['evaluation_start_date']) ? Carbon::parse($newWindow['evaluation_start_date'])->format('d-m-Y h:i A') : null;
        $newEnd = isset($newWindow['evaluation_end_date']) ? Carbon::parse($newWindow['evaluation_end_date'])->format('d-m-Y h:i A') : null;
        $evaluationTimePerSheet = $answerSheet->evaluation_time_per_sheet;

        dispatch(function () use ($adminId, $teacherId, $isPrintingIssue, $issueTypeName, $courseName, $barcode, $adminRemarks, $adminName, $resolvedAt, $newStart, $newEnd, $evaluationTimePerSheet) {
            App::make(IssueResolvedMailService::class)->sendIssueResolvedEmail(
                adminId: $adminId,
                teacherId: $teacherId,
                isPrintingIssue: $isPrintingIssue,
                issueTypeName: $issueTypeName,
                courseName: $courseName,
                barcode: $barcode,
                adminRemarks: $adminRemarks,
                resolvedByName: $adminName,
                resolvedAt: $resolvedAt,
                newEvaluationStartDate: $newStart,
                newEvaluationEndDate: $newEnd,
                evaluationTimePerSheet: $evaluationTimePerSheet,
            );
        })->afterResponse();
    }
}
