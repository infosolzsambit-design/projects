<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerSheetResource;
use App\Models\AnswerSheet;
use App\Traits\ApiResponse;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the sidebar's "My Completed Course" menu — the mirror image of
 * "My Pending Course" (see MyPendingCourseController's own docblock for
 * everything that applies identically here: course grouping via the
 * packet's actual course rather than the sheet's own free-text
 * subject_code/subject_name, no permission gate since every query is
 * scoped to $request->user()->id, and the same exam-year scoping via
 * HasExamYearScope). The only real difference is the marks filter itself
 * — whereNotNull instead of whereNull: sheets this teacher has already
 * evaluated (via "Complete" on EvaluatePaperView.vue), not what's still
 * waiting. A completed sheet is read-only here — there's no "resume
 * evaluating" action, on purpose; re-opening a finished evaluation is a
 * separate feature this doesn't attempt.
 */
class MyCompletedCourseController extends Controller
{
    use ApiResponse, HasExamTypeScope, HasExamYearScope;

    /**
     * GET /my-completed-courses — the course chips across the top,
     * mirroring subject-list.html the same way My Pending Course's own
     * subjects() does. One row per distinct course this teacher has
     * completed sheets for, with how many.
     */
    public function subjects(Request $request): JsonResponse
    {
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
            ->whereNotNull('answer_sheets.marks')
            ->whereNull('qasm.deleted_at')
            ->whereNull('courses.deleted_at')
            ->selectRaw('courses.id as course_id, courses.code as course_code, courses.name as course_name, COUNT(*) as completed_count')
            ->groupBy('courses.id', 'courses.code', 'courses.name')
            ->orderBy('courses.name')
            ->get()
            ->map(fn ($row) => [
                'course_id' => (int) $row->course_id,
                'course_code' => $row->course_code,
                'course_name' => $row->course_name,
                'completed_count' => (int) $row->completed_count,
            ]);

        return $this->success($courses, 'Completed courses fetched successfully.');
    }

    /**
     * GET /my-completed-courses/papers?course_id=.. — the paper-by-paper
     * list below the chips, newest-evaluated first.
     */
    public function papers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer'],
        ]);
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);

        $sheets = AnswerSheet::query()
            ->with('mapping.questionPaper')
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
            ->whereNotNull('marks')
            ->orderByDesc('updated_at')
            ->get();

        return $this->success(AnswerSheetResource::collection($sheets), 'Completed papers fetched successfully.');
    }
}
