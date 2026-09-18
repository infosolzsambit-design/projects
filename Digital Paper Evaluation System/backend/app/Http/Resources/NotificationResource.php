<?php

namespace App\Http\Resources;

use App\Models\AnswerSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AnswerSheet
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'answer_sheet_id' => $this->id,
            'unique_number' => $this->barcode ?? $this->subject_barcode ?? $this->roll_no,
            // The literal QR value NotificationsView.vue's printing-issue
            // resolve modal checks a freshly uploaded PDF's own QR code
            // against (see NotificationController::resolvePrintingIssue())
            // — deliberately subject_barcode specifically, the same field
            // the original bulk-upload flow matches PDFs against (see
            // AnswerSheetUploadView.vue), not `barcode` or `unique_number`
            // above (whichever of several fields happens to be set).
            'subject_barcode' => $this->subject_barcode,
            'issue_id' => $this->issue_master_id,
            'issue_name' => $this->whenLoaded('issueMaster', fn () => $this->issueMaster?->name),
            // Resolved by id (config('issues.*')), never by matching a name
            // string — see AnswerSheetResource's own 'blocks_evaluation'
            // field for the same reasoning. Lets NotificationsView.vue pick
            // which Resolve modal to open without ever hardcoding an id.
            'is_printing_issue' => (int) $this->issue_master_id === (int) config('issues.printing_issue_id'),
            'is_timing_issue' => (int) $this->issue_master_id === (int) config('issues.timing_issue_id'),
            'issue_status' => $this->issue_status,
            'remarks' => $this->issue_remarks,
            'raised_by' => $this->whenLoaded('issueRaisedBy', fn () => $this->issueRaisedBy?->name),
            'raised_at' => $this->issue_raised_at?->format('Y-m-d H:i'),
            // The timing-issue resolve modal's own "current timing" display
            // and its start-date picker's minimum bound (see
            // NotificationController::resolveTimingIssue()'s own docblock).
            'evaluation_start_date' => $this->evaluation_start_date?->format('Y-m-d H:i'),
            'evaluation_end_date' => $this->evaluation_end_date?->format('Y-m-d H:i'),
            'evaluation_time_per_sheet' => $this->evaluation_time_per_sheet,
            // Whole seconds the teacher had actually spent on this sheet
            // before the issue was raised — a Timing Issue never resets
            // this (see MyPendingCourseController::raiseIssue()'s own
            // docblock), so it's real context for the Resolve modal:
            // how much of the old window they'd already used. Always
            // null for a Printing Issue, since raising one does reset it.
            'consumed_time' => $this->consumed_time,
            'admin_remarks' => $this->issue_admin_remarks,
            'fixed_by' => $this->whenLoaded('issueFixedBy', fn () => $this->issueFixedBy?->name),
            'fixed_at' => $this->issue_fixed_at?->format('Y-m-d H:i'),
            'course_name' => $this->whenLoaded('mapping', fn () => $this->mapping?->course?->name),
            'course_code' => $this->whenLoaded('mapping', fn () => $this->mapping?->course?->code),
        ];
    }
}
