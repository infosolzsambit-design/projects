<?php

namespace App\Http\Resources;

use App\Helpers\ProgramLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shared pool (Assign Teacher → Pool) for the Shared Pools page: what
 * it was made from, who shares it, and how far its sheets have got.
 */
class AnswerSheetPoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_name' => $this->program_name,
            'program_display' => ProgramLabel::display($this->program_name),
            'course_id' => $this->course_id,
            'course_name' => $this->course?->name,
            'course_code' => $this->course?->code,
            'course_type' => $this->course?->type,
            'department_id' => $this->department_id,
            'department_name' => $this->department?->name,
            'exam_term_name' => $this->examTerm?->name,
            'exam_type_name' => $this->examType?->name,
            'semester' => $this->semester,
            'exam_year' => $this->exam_year,
            'evaluation_start_date' => $this->evaluation_start_date?->format('Y-m-d H:i:s'),
            'evaluation_end_date' => $this->evaluation_end_date?->format('Y-m-d H:i:s'),
            'evaluation_time_per_sheet' => $this->evaluation_time_per_sheet,
            'teachers' => $this->teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'emp_code' => $teacher->teacherDetail?->emp_code,
            ])->values(),
            // Per teacher: sheets started / evaluated from this pool (see
            // AnswerSheetPoolController::withTeacherCounts()).
            'teacher_breakdown' => $this->teacher_breakdown ?? [],
            // total = every sheet ever put in the pool; waiting = not started
            // by anyone yet; started = claimed by a teacher; evaluated = marks in.
            'sheet_count' => (int) $this->sheets_count,
            'waiting_count' => (int) $this->waiting_count,
            'started_count' => (int) $this->started_count,
            'evaluated_count' => (int) $this->evaluated_count,
            'created_by_name' => $this->creator?->name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $this->deleted_at?->format('Y-m-d H:i:s'),
        ];
    }
}
