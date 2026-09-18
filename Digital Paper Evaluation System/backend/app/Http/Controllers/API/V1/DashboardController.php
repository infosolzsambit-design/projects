<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\QuestionPaper;
use App\Models\Student;
use App\Models\TeacherDetail;
use App\Traits\ApiResponse;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Backs DashboardView.vue's admin section (AdminDashboardView.vue) — one
 * combined response for the whole page instead of eight separate round
 * trips, since it all loads together. No permission gating (same "not
 * gated yet" shape as most controllers in this app) — the frontend already
 * only ever renders this section behind authStore.can('admin-dashboard').
 *
 * The three "top N" cards (Department Wise Progress / Teacher Workload /
 * Course Wise Pending Evaluation) each have their own "See All" endpoint
 * below (departments()/teacherWorkloadFull()/coursePendingFull()) backing
 * AdminDashboardView.vue's own modal for that card — same underlying query
 * as the summary's own capped version, just without the LIMIT, so the two
 * can never disagree about ordering or what counts as "pending".
 */
class DashboardController extends Controller
{
    use ApiResponse, HasExamTypeScope, HasExamYearScope;

    /**
     * How many rows each "top N" card shows inline before "See All" is
     * needed — the same figures each card already capped at
     * (departmentProgressQuery() et al used to end in ->limit(N) directly;
     * now limitedList() below does the capping instead, so a "See All"
     * modal has somewhere uncapped to fetch from).
     */
    private const DEPARTMENT_LIMIT = 8;

    private const TEACHER_LIMIT = 5;

    private const COURSE_LIMIT = 5;

    public function adminSummary(Request $request): JsonResponse
    {
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $sheetQuery = fn () => AnswerSheet::query()
            ->when($examYear !== null, fn ($q) => $q->whereHas('mapping.questionPaper', fn ($q2) => $q2->where('exam_year', $examYear)))
            ->when($examType !== null, fn ($q) => $q->whereHas('mapping', fn ($q2) => $q2->where('exam_type_id', $examType)));

        [$departmentProgress, $departmentProgressHasMore] = $this->limitedList($this->departmentProgressQuery($examYear, $examType), self::DEPARTMENT_LIMIT);
        [$teacherWorkload, $teacherWorkloadHasMore] = $this->limitedList($this->teacherWorkloadQuery($sheetQuery), self::TEACHER_LIMIT);
        [$coursePending, $coursePendingHasMore] = $this->limitedList($this->coursePendingQuery($examYear, $examType), self::COURSE_LIMIT);

        return $this->success([
            'totals' => $this->totals(),
            'workflow' => $this->workflow($sheetQuery),
            'evaluation_progress' => $this->evaluationProgress($sheetQuery),
            'evaluation_status' => $this->evaluationStatus($sheetQuery),
            'department_progress' => $this->mapDepartmentRows($departmentProgress),
            'department_progress_has_more' => $departmentProgressHasMore,
            'issue_summary' => $this->issueSummary($sheetQuery),
            'teacher_workload' => $this->mapTeacherWorkloadRows($teacherWorkload),
            'teacher_workload_has_more' => $teacherWorkloadHasMore,
            'course_pending' => $this->mapCoursePendingRows($coursePending),
            'course_pending_has_more' => $coursePendingHasMore,
        ], 'Dashboard summary fetched successfully.');
    }

    /**
     * GET /dashboard/department-progress — "See All" behind Department
     * Wise Progress's own card, uncapped.
     */
    public function departments(Request $request): JsonResponse
    {
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $rows = $this->departmentProgressQuery($examYear, $examType)->get();

        return $this->success($this->mapDepartmentRows($rows), 'Department progress fetched successfully.');
    }

    /**
     * GET /dashboard/teacher-workload — "See All" behind Teacher
     * Workload's own card, uncapped.
     */
    public function teacherWorkloadFull(Request $request): JsonResponse
    {
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $sheetQuery = fn () => AnswerSheet::query()
            ->when($examYear !== null, fn ($q) => $q->whereHas('mapping.questionPaper', fn ($q2) => $q2->where('exam_year', $examYear)))
            ->when($examType !== null, fn ($q) => $q->whereHas('mapping', fn ($q2) => $q2->where('exam_type_id', $examType)));
        $rows = $this->teacherWorkloadQuery($sheetQuery)->get();

        return $this->success($this->mapTeacherWorkloadRows($rows), 'Teacher workload fetched successfully.');
    }

    /**
     * GET /dashboard/course-pending — "See All" behind Course Wise
     * Pending Evaluation's own card, uncapped.
     */
    public function coursePendingFull(Request $request): JsonResponse
    {
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $rows = $this->coursePendingQuery($examYear, $examType)->get();

        return $this->success($this->mapCoursePendingRows($rows), 'Course pending evaluation fetched successfully.');
    }

    /**
     * Runs $query capped at $limit + 1 so a single extra query isn't
     * needed just to learn whether there's more beyond the capped list
     * shown inline — if the "+1"th row came back, there is.
     *
     * @return array{0: Collection<int, object>, 1: bool}
     */
    private function limitedList(Builder $query, int $limit): array
    {
        $rows = (clone $query)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return [$rows->take($limit), $hasMore];
    }

    /**
     * Row 1 — org-wide totals. Deliberately NOT exam-year-scoped, same as
     * every other master-data list in this app (Programs/Courses/
     * Departments/Teachers/Students aren't tied to one exam year at all);
     * Question Papers/Answer Sheets are still shown as an all-time grand
     * total here even though the rest of this response scopes to one year
     * — this row is meant to answer "how big is the whole system," not
     * "how much happened this year."
     *
     * @return array<string, int>
     */
    private function totals(): array
    {
        return [
            'programs' => Program::count(),
            'courses' => Course::count(),
            'departments' => Department::count(),
            'teachers' => TeacherDetail::count(),
            'students' => Student::count(),
            'question_papers' => QuestionPaper::count(),
            'answer_sheets' => AnswerSheet::count(),
        ];
    }

    /**
     * Row 2 — evaluation-workflow counts, scoped to $sheetQuery's exam
     * year. "Not Assigned" and "Pending Evaluation" are two different
     * cuts of the same "not yet marked" pool — not assigned = teacher_id
     * still null; pending evaluation = assigned but marks still null —
     * see evaluationStatus() below for how these relate for the donut.
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function workflow(Closure $sheetQuery): array
    {
        return [
            'assigned' => $sheetQuery()->whereNotNull('teacher_id')->count(),
            'pending_assignment' => $sheetQuery()->whereNull('teacher_id')->count(),
            'evaluated' => $sheetQuery()->whereNotNull('marks')->count(),
            'pending_evaluation' => $sheetQuery()->whereNotNull('teacher_id')->whereNull('marks')->count(),
            'raised_issues' => $sheetQuery()->whereNotNull('issue_master_id')->count(),
            'pending_issues' => $sheetQuery()->where('issue_status', 'open')->count(),
        ];
    }

    /**
     * "Evaluation Progress" line chart — the last 8 calendar weeks
     * (oldest first, this week last), each answer_sheets.assigned_at/
     * evaluated_at (see that migration's own docblock) counted into
     * whichever week it falls in. A sheet assigned/evaluated before that
     * column existed has neither stamp, so it simply doesn't appear in
     * any week here — not retroactively wrong, just not plottable (see
     * the follow-up backfill migration for the one-time catch-up this
     * got so already-existing sheets aren't silently invisible here).
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array{weeks: list<string>, assigned: list<int>, evaluated: list<int>}
     */
    private function evaluationProgress(Closure $sheetQuery): array
    {
        $weeks = [];
        $assigned = [];
        $evaluated = [];

        for ($i = 7; $i >= 0; $i--) {
            $weekStart = Carbon::now()->startOfWeek()->subWeeks($i);
            $weekEnd = (clone $weekStart)->endOfWeek();

            $weeks[] = 'Week '.(8 - $i);
            $assigned[] = $sheetQuery()->whereBetween('assigned_at', [$weekStart, $weekEnd])->count();
            $evaluated[] = $sheetQuery()->whereBetween('evaluated_at', [$weekStart, $weekEnd])->count();
        }

        return ['weeks' => $weeks, 'assigned' => $assigned, 'evaluated' => $evaluated];
    }

    /**
     * "Evaluation Status" donut — evaluated + pending_evaluation always
     * sum to total (every sheet is either marked or not); not_assigned is
     * a breakdown *within* pending_evaluation (how many of the not-yet-
     * marked sheets haven't even been handed to a teacher), not a third
     * disjoint bucket — see AdminDashboardView.vue's own docblock on its
     * donut arcs for how that's rendered.
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function evaluationStatus(Closure $sheetQuery): array
    {
        $total = $sheetQuery()->count();
        $evaluated = $sheetQuery()->whereNotNull('marks')->count();

        return [
            'total' => $total,
            'evaluated' => $evaluated,
            'pending_evaluation' => $total - $evaluated,
            'not_assigned' => $sheetQuery()->whereNull('teacher_id')->count(),
        ];
    }

    /**
     * "Department Wise Progress" — every answer sheet's department is
     * resolved via its packet's own program_name (see
     * QuestionAnswerSheetMapping's own migration docblock on why that's a
     * plain string, not a program_id) matched against Program.name, then
     * that program's own department_id. A packet whose program_name
     * doesn't match any current program (renamed/deleted since) is simply
     * excluded — there's no department to attribute it to. Ordered by
     * volume, highest first; mapDepartmentRows() below turns each row's
     * raw counts into the evaluated/pending/not-assigned percentages
     * AdminDashboardView.vue actually renders.
     *
     * @return Builder<AnswerSheet>
     */
    private function departmentProgressQuery(?int $examYear, ?int $examType = null): Builder
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings', 'question_answer_sheet_mappings.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('programs', 'programs.name', '=', 'question_answer_sheet_mappings.program_name')
            ->join('departments', 'departments.id', '=', 'programs.department_id')
            ->whereNull('question_answer_sheet_mappings.deleted_at')
            ->whereNull('programs.deleted_at')
            ->whereNull('departments.deleted_at');

        if ($examYear !== null) {
            $query->join('question_papers', 'question_papers.id', '=', 'question_answer_sheet_mappings.question_paper_id')
                ->where('question_papers.exam_year', $examYear);
        }

        if ($examType !== null) {
            $query->where('question_answer_sheet_mappings.exam_type_id', $examType);
        }

        return $query
            ->groupBy('departments.id', 'departments.name')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw(
                'departments.name as department_name, COUNT(*) as total, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                'SUM(CASE WHEN answer_sheets.marks IS NULL AND answer_sheets.teacher_id IS NOT NULL THEN 1 ELSE 0 END) as pending',
            );
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{name: string, total: int, evaluated_pct: int, pending_pct: int, not_assigned_pct: int}>
     */
    private function mapDepartmentRows(iterable $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $total = max(1, (int) $row->total);
            $evaluatedPct = (int) round($row->evaluated / $total * 100);
            $pendingPct = (int) round($row->pending / $total * 100);

            $mapped[] = [
                'name' => $row->department_name,
                'total' => (int) $row->total,
                'evaluated_pct' => $evaluatedPct,
                'pending_pct' => $pendingPct,
                'not_assigned_pct' => max(0, 100 - $evaluatedPct - $pendingPct),
            ];
        }

        return $mapped;
    }

    /**
     * "Issue Summary" panel — same underlying counts as workflow()'s
     * raised_issues/pending_issues, just re-shaped for that panel's own
     * three cards, plus the resolved count neither of those needs.
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function issueSummary(Closure $sheetQuery): array
    {
        return [
            'total_raised' => $sheetQuery()->whereNotNull('issue_master_id')->count(),
            'pending' => $sheetQuery()->where('issue_status', 'open')->count(),
            'resolved' => $sheetQuery()->where('issue_status', 'resolved')->count(),
        ];
    }

    /**
     * "Teacher Workload" — every teacher with at least one sheet
     * currently assigned to them, busiest first. issues counts any sheet
     * in that teacher's own group with an issue attached (raised against
     * a sheet on their plate), not only ones they personally raised.
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return Builder<AnswerSheet>
     */
    private function teacherWorkloadQuery(Closure $sheetQuery): Builder
    {
        return $sheetQuery()
            ->whereNotNull('answer_sheets.teacher_id')
            ->join('users', 'users.id', '=', 'answer_sheets.teacher_id')
            ->leftJoin('teacher_details', function ($join) {
                $join->on('teacher_details.user_id', '=', 'users.id')->whereNull('teacher_details.deleted_at');
            })
            ->whereNull('users.deleted_at')
            ->groupBy('users.id', 'users.name', 'teacher_details.emp_code')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw(
                'users.name as teacher_name, teacher_details.emp_code as emp_code, COUNT(*) as assigned, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                'SUM(CASE WHEN answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as pending, '.
                'SUM(CASE WHEN answer_sheets.issue_master_id IS NOT NULL THEN 1 ELSE 0 END) as issues',
            );
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{name: string, emp_code: ?string, assigned: int, evaluated: int, pending: int, issues: int}>
     */
    private function mapTeacherWorkloadRows(iterable $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = [
                'name' => $row->teacher_name,
                'emp_code' => $row->emp_code,
                'assigned' => (int) $row->assigned,
                'evaluated' => (int) $row->evaluated,
                'pending' => (int) $row->pending,
                'issues' => (int) $row->issues,
            ];
        }

        return $mapped;
    }

    /**
     * "Course Wise Pending Evaluation" — every course with something
     * still pending (marks still null — regardless of assignment, unlike
     * workflow()'s own narrower "assigned but unmarked" pending_evaluation;
     * this table is about total unfinished workload per course), most
     * pending first. Courses with nothing pending are excluded outright
     * rather than shown at 0%.
     *
     * @return Builder<AnswerSheet>
     */
    private function coursePendingQuery(?int $examYear, ?int $examType = null): Builder
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings', 'question_answer_sheet_mappings.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses', 'courses.id', '=', 'question_answer_sheet_mappings.course_id')
            ->whereNull('question_answer_sheet_mappings.deleted_at')
            ->whereNull('courses.deleted_at');

        if ($examYear !== null) {
            $query->join('question_papers', 'question_papers.id', '=', 'question_answer_sheet_mappings.question_paper_id')
                ->where('question_papers.exam_year', $examYear);
        }

        if ($examType !== null) {
            $query->where('question_answer_sheet_mappings.exam_type_id', $examType);
        }

        return $query
            ->groupBy('courses.id', 'courses.name', 'courses.code')
            ->havingRaw('SUM(CASE WHEN answer_sheets.marks IS NULL THEN 1 ELSE 0 END) > 0')
            ->orderByRaw('SUM(CASE WHEN answer_sheets.marks IS NULL THEN 1 ELSE 0 END) DESC')
            ->selectRaw(
                'courses.name as course_name, courses.code as course_code, COUNT(*) as total, '.
                'SUM(CASE WHEN answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as pending',
            );
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{name: string, code: ?string, pending: int, total: int, pending_pct: int}>
     */
    private function mapCoursePendingRows(iterable $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $total = max(1, (int) $row->total);
            $mapped[] = [
                'name' => $row->course_name,
                'code' => $row->course_code,
                'pending' => (int) $row->pending,
                'total' => (int) $row->total,
                'pending_pct' => (int) round($row->pending / $total * 100),
            ];
        }

        return $mapped;
    }
}
