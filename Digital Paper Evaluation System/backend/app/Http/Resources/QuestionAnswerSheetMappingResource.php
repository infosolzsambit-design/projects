<?php

namespace App\Http\Resources;

use App\Models\QuestionAnswerSheetMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuestionAnswerSheetMapping
 */
class QuestionAnswerSheetMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_paper_id' => $this->question_paper_id,
            'exam_year' => $this->whenLoaded('questionPaper', fn () => $this->questionPaper?->exam_year),
            'course_id' => $this->course_id,
            'course_name' => $this->whenLoaded('course', fn () => $this->course?->name),
            'course_code' => $this->whenLoaded('course', fn () => $this->course?->code),
            'semester' => $this->semester,
            'exam_term_id' => $this->exam_term_id,
            'exam_term_name' => $this->whenLoaded('examTerm', fn () => $this->examTerm?->name),
            'exam_type_id' => $this->exam_type_id,
            'exam_type_name' => $this->whenLoaded('examType', fn () => $this->examType?->name),
            'program_name' => $this->program_name,
            'packet_code' => $this->packet_code,
            // Set via ->withCount('answerSheets') on the query (index()/
            // show()) — a *count* query, not an eager-loaded collection, so
            // this stays cheap even for a packet with thousands of rows.
            // The actual rows are fetched separately, paginated, via
            // GET /answer-sheet-mappings/{id}/rows (see rows() below) —
            // loading them all here just to show one page of a modal would
            // be exactly the "load everything at once" problem this whole
            // feature exists to avoid.
            'answer_sheet_count' => $this->answer_sheets_count,
            // Same ->withCount() pattern, constrained to teacher_id IS
            // NULL — the pool AssignTeacherService::assign() actually
            // draws from. Distinct from answer_sheet_count above once any
            // of a packet's sheets have been assigned to a teacher.
            'pending_answer_sheet_count' => $this->pending_answer_sheet_count,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }
}
