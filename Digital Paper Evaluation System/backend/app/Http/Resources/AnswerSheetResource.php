<?php

namespace App\Http\Resources;

use App\Models\AnswerSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// QuestionPaperResource nested below — its own 'groups' field only
// renders when the caller eager-loaded that relation (see this file's
// 'question_paper' field for exactly when that happens).

/**
 * @mixin AnswerSheet
 */
class AnswerSheetResource extends JsonResource
{
    // Without this, JsonResource's own filter()/removeMissingValues()
    // silently re-indexes any nested array field whose keys are *all*
    // numeric (via array_values()) — exactly what 'draft_marks_breakdown'
    // below is (question-paper node ids as keys), turning
    // {"338":4,"340":3} into a plain [4,3] list and losing which mark
    // belongs to which question. A known Laravel Resources gotcha, not
    // specific to this field — see ConditionallyLoadsAttributes::
    // removeMissingValues() in the framework itself.
    public $preserveKeys = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'teacher_name' => $this->whenLoaded('teacher', fn () => $this->teacher?->name),
            // Only ever non-null when the caller also eager-loaded
            // teacher.teacherDetail (see QuestionAnswerSheetMappingController
            // ::rows()) — everyone else gets null here, same as every other
            // whenLoaded field in this resource.
            'teacher_emp_code' => $this->whenLoaded('teacher', fn () => $this->teacher?->teacherDetail?->emp_code),
            // Set together with teacher_id on assign — see
            // AssignTeacherService::assign()'s own docblock. A future
            // "change timings" screen will let these be edited after the
            // fact.
            'evaluation_start_date' => $this->evaluation_start_date?->format('Y-m-d H:i'),
            'evaluation_end_date' => $this->evaluation_end_date?->format('Y-m-d H:i'),
            'evaluation_time_per_sheet' => $this->evaluation_time_per_sheet,
            // Whole seconds actually spent so far — autosaved alongside
            // the draft fields below (see
            // MyPendingCourseController::saveDraft()); EvaluatePaperView.vue
            // converts this to minutes only where it's compared against
            // evaluation_time_per_sheet's own coarser, admin-set budget.
            'consumed_time' => $this->consumed_time,
            // In-progress marks — a teacher's own not-yet-"Complete"d work,
            // restored into EvaluatePaperView.vue's marks sidebar (total +
            // per-question breakdown) and drawn annotations on reopening a
            // sheet. Never what makes a sheet count as evaluated — only
            // `marks` (below) does that.
            'draft_marks' => $this->draft_marks,
            'draft_marks_breakdown' => $this->draft_marks_breakdown,
            'draft_annotations' => $this->draft_annotations,
            // Set via MyPendingCourseController::raiseIssue() (the
            // "Problem" action on EvaluatePaperView.vue) — null unless a
            // teacher has raised one against this physical sheet.
            // issue_master_name only renders when the caller eager-loaded
            // 'issueMaster' (see papers() above); everyone else gets null,
            // same as every other whenLoaded field in this resource.
            'issue_master_id' => $this->issue_master_id,
            'issue_master_name' => $this->whenLoaded('issueMaster', fn () => $this->issueMaster?->name),
            'issue_status' => $this->issue_status,
            'issue_remarks' => $this->issue_remarks,
            'issue_raised_at' => $this->issue_raised_at?->format('Y-m-d H:i'),
            // Only meaningful once issue_status is 'resolved' — the admin's
            // own note from NotificationController's resolve*Issue() methods,
            // shown as the "Resolved" flag's tooltip on MyPendingCoursesView.vue.
            'issue_admin_remarks' => $this->issue_admin_remarks,
            'issue_fixed_at' => $this->issue_fixed_at?->format('Y-m-d H:i'),
            // MyPendingCoursesView.vue's own "Evaluate"/"Continue Evaluate"
            // button reads this to decide whether to disable itself — an
            // *open* Printing Issue means the physical sheet itself needs
            // fixing before anyone can evaluate it, but an open Timing
            // Issue doesn't block anything (the teacher can keep working
            // against the existing schedule while an admin sorts the new
            // one out). Resolved by id (config('issues.printing_issue_id')),
            // never by matching a name string, and computed here so the
            // frontend never needs to know either id itself.
            'blocks_evaluation' => $this->issue_status === 'open'
                && (int) $this->issue_master_id === (int) config('issues.printing_issue_id'),
            'branch_code' => $this->branch_code,
            'branch_name' => $this->branch_name,
            'subject_code' => $this->subject_code,
            'subject_name' => $this->subject_name,
            'semester' => $this->semester,
            'subject_barcode' => $this->subject_barcode,
            'fi_code' => $this->fi_code,
            'roll_no' => $this->roll_no,
            'name' => $this->name,
            'registration_no' => $this->registration_no,
            'absent' => $this->absent,
            'locked_time' => $this->locked_time,
            'packet_no' => $this->packet_no,
            'barcode' => $this->barcode,
            'marks' => $this->marks,
            // Only present when the caller eager-loaded mapping.questionPaper
            // (see MyPendingCourseController::papers()) — everyone else gets
            // null here, same as any other whenLoaded field.
            'max_marks' => $this->whenLoaded('mapping', fn () => $this->mapping->questionPaper?->full_marks),
            // The real question-paper structure this sheet is being marked
            // against — only present when the caller also eager-loaded
            // mapping.questionPaper.groups (see
            // MyPendingCourseController::show(), used by
            // EvaluatePaperView.vue's per-question marks sidebar). Everyone
            // else (including papers()'s own list, which only loads
            // mapping.questionPaper for max_marks above) gets null here —
            // QuestionPaperResource's own 'groups' field is itself gated by
            // whenLoaded('groups'), so nesting it costs nothing when unused.
            'question_paper' => $this->whenLoaded('mapping', fn () => $this->mapping->questionPaper
                ? new QuestionPaperResource($this->mapping->questionPaper)
                : null),
            'top_sheet' => $this->top_sheet,
            'pdf_name' => $this->pdf_name,
            'pdf_url' => $this->pdf_path,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
