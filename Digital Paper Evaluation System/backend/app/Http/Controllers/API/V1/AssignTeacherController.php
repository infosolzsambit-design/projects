<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\AssignTeacherService;
use App\Services\AuditLogService;
use App\Services\TeacherAssignmentMailService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Backs AssignTeacherView.vue's "Assign" button — everything up to that
// click (the search, Distribute Equally's equal split, any hand-edited
// quantity) is plain client-side math against data already on the page;
// this is the one call that actually persists it. See
// AssignTeacherService's own docblock for how "assigning" is stored.
class AssignTeacherController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AssignTeacherService $assignTeacherService,
        private readonly AuditLogService $auditLog,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_name' => ['required', 'string'],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_year' => ['required', 'integer'],
            'assignments' => ['required', 'array', 'min:1'],
            // Only a real teacher — a user with a (non-deleted)
            // teacher_details row — same check TeacherController::show()
            // makes, just expressed as a validation rule instead.
            'assignments.*.teacher_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at'),
            ],
            'assignments.*.quantity' => ['required', 'integer', 'min:1'],
            // The evaluation window every sheet this call touches gets
            // stamped with — see AssignTeacherService::assign()'s own
            // docblock. Required, not optional: nothing here should be
            // assigned without a window an evaluator is expected to work
            // inside of.
            'evaluation_start_date' => ['required', 'date'],
            'evaluation_end_date' => ['required', 'date', 'after_or_equal:evaluation_start_date'],
            // Expected minutes to evaluate one answer sheet — optional (a
            // blank value means no per-sheet time limit, see
            // EvaluatePaperView.vue's totalMinutes); a whole number only
            // when given, no decimals.
            'evaluation_time_per_sheet' => ['nullable', 'integer', 'min:1'],
            // The (admin-editable) subject/body from the "Send Mail and
            // Assign" modal — see SendAssignmentEmailModal.vue. Required:
            // the modal always pre-fills both, so an empty value here means
            // something's wrong on the client, not a legitimate "skip mail".
            'email_subject' => ['required', 'string', 'max:255'],
            'email_body' => ['required', 'string'],
        ]);

        try {
            $summary = $this->assignTeacherService->assign(
                filters: [
                    'program_name' => $data['program_name'],
                    'exam_term_id' => $data['exam_term_id'],
                    'exam_type_id' => $data['exam_type_id'],
                    'course_id' => $data['course_id'],
                    'semester' => $data['semester'],
                    'exam_year' => $data['exam_year'],
                ],
                assignments: $data['assignments'],
                evaluationStartDate: $data['evaluation_start_date'],
                evaluationEndDate: $data['evaluation_end_date'],
                evaluationTimePerSheet: isset($data['evaluation_time_per_sheet']) ? (int) $data['evaluation_time_per_sheet'] : null,
            );
        } catch (ValidationException $e) {
            // Not a field-validation failure in the usual sense (nothing's
            // wrong with the request shape) — a business-rule rejection
            // worked out only once the DB's actually been queried (no
            // matching packet, or not enough sheets still pending). Reused
            // ValidationException from the service purely so its errors()
            // shape matches every other 422 this API returns.
            return $this->error(collect($e->errors())->flatten()->first() ?? 'Could not assign.', 422, $e->errors());
        }

        $totalAssigned = collect($summary)->sum('assigned_count');

        $this->auditLog->log(
            event: 'answer-sheets-assigned',
            module: 'Assign Teacher',
            description: "Assigned {$totalAssigned} answer sheet(s) across ".count($summary).' teacher(s).',
            newValues: ['filters' => $data, 'summary' => $summary],
            tags: ['assign-teacher', 'answer-sheet'],
        );

        // Sent after the HTTP response goes out, not queued — this endpoint
        // has no guaranteed queue worker running, and the whole point is
        // the admin's page refresh must not wait on SendGrid. See
        // TeacherAssignmentMailService's own docblock.
        $senderId = $request->user()->id;
        $emailSubject = $data['email_subject'];
        $emailBody = $data['email_body'];
        $courseId = $data['course_id'];
        $evaluationStartDate = $data['evaluation_start_date'];
        $evaluationEndDate = $data['evaluation_end_date'];
        $evaluationTimePerSheet = isset($data['evaluation_time_per_sheet']) ? (int) $data['evaluation_time_per_sheet'] : null;

        dispatch(function () use ($senderId, $summary, $emailSubject, $emailBody, $courseId, $evaluationStartDate, $evaluationEndDate, $evaluationTimePerSheet) {
            App::make(TeacherAssignmentMailService::class)->sendAssignmentEmails(
                senderId: $senderId,
                summary: $summary,
                emailSubject: $emailSubject,
                emailBody: $emailBody,
                courseId: $courseId,
                evaluationStartDate: $evaluationStartDate,
                evaluationEndDate: $evaluationEndDate,
                evaluationTimePerSheet: $evaluationTimePerSheet,
            );
        })->afterResponse();

        return $this->success([
            'total_assigned' => $totalAssigned,
            'summary' => $summary,
        ], "Assigned {$totalAssigned} answer sheet(s) successfully.");
    }
}
