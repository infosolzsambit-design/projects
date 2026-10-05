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
 * Backs the sidebar's "Problem Course" — the logged-in teacher's own
 * answer sheets that have had an issue raised (printing or timing, via
 * MyPendingCourseController::raiseIssue()). Open ones are hidden from
 * Pending Course and can't be evaluated; once an admin resolves one
 * (NotificationController) it goes back to Pending but stays listed here
 * too, marked Resolved, as a record. Read-only, scoped to the calling
 * teacher, same exam-year/exam-type scoping as the pending and completed
 * lists.
 */
class MyProblemCourseController extends Controller
{
    use ApiResponse, HasExamTypeScope, HasExamYearScope;

    /**
     * GET /my-problem-courses — one row per course this teacher has
     * raised issues in, with how many are still open vs resolved.
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
            ->whereIn('answer_sheets.issue_status', ['open', 'resolved'])
            ->whereNull('qasm.deleted_at')
            ->whereNull('courses.deleted_at')
            ->selectRaw('courses.id as course_id, courses.code as course_code, courses.name as course_name, courses.type as course_type')
            ->selectRaw("SUM(CASE WHEN answer_sheets.issue_status = 'open' AND answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as problem_count")
            ->selectRaw("SUM(CASE WHEN answer_sheets.issue_status = 'resolved' THEN 1 ELSE 0 END) as resolved_count")
            ->groupBy('courses.id', 'courses.code', 'courses.name', 'courses.type')
            ->orderBy('courses.name')
            ->get()
            ->map(fn ($row) => [
                'course_id' => (int) $row->course_id,
                'course_code' => $row->course_code,
                'course_name' => $row->course_name,
                'course_type' => $row->course_type,
                'problem_count' => (int) $row->problem_count,
                'resolved_count' => (int) $row->resolved_count,
            ]);

        return $this->success($courses, 'Problem courses fetched successfully.');
    }

    /**
     * GET /my-problem-courses/papers?course_id=.. — the sheets with an
     * issue for one course: open ones first, then resolved, each most
     * recently raised first.
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
            ->whereIn('issue_status', ['open', 'resolved'])
            ->orderByRaw("CASE WHEN issue_status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('issue_raised_at')
            ->get();

        return $this->success(AnswerSheetResource::collection($sheets), 'Problem papers fetched successfully.');
    }
}
