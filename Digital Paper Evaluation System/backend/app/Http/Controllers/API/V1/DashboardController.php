<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Helpers\CourseLabel;
use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\QuestionPaper;
use App\Models\Student;
use App\Models\TeacherDetail;
use App\Models\User;
use App\Traits\ApiResponse;
use App\Traits\HasExamTypeScope;
use App\Traits\HasExamYearScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** Rows shown on the teacher dashboard's two list cards before "See All". */
    private const TEACHER_LIST_LIMIT = 5;

    /** A course whose evaluation window ends within this many days counts as "due soon". */
    private const DUE_SOON_DAYS = 3;

    public function adminSummary(Request $request): JsonResponse
    {
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $sheetQuery = fn () => AnswerSheet::query()
            ->inEvaluation()
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
            'teacher_progress' => $this->teacherProgress($sheetQuery),
            'teacher_workload_has_more' => $teacherWorkloadHasMore,
            'course_pending' => $this->mapCoursePendingRows($coursePending),
            'course_pending_has_more' => $coursePendingHasMore,
        ], 'Dashboard summary fetched successfully.');
    }

    /**
     * GET /dashboard/department-progress — "See All" behind Department
     * Wise Progress's own card, uncapped.
     */
    /**
     * GET /dashboard/teacher-summary — the logged-in teacher's own dashboard
     * (designed_files/teacher_dashboard.png). Every figure covers only this
     * teacher's own answer sheets (teacher_id = them; unstarted shared-pool
     * sheets aren't theirs yet), scoped to the header's exam year / exam type
     * the same way My Pending Course is. "Pending" is the app-wide rule —
     * AnswerSheet::pendingSql(): not evaluated and no open problem.
     */
    public function teacherSummary(Request $request): JsonResponse
    {
        [$mine, $mineByCourse] = $this->teacherScopes($request);

        $assigned = $mine()->count();
        $evaluated = $mine()->whereNotNull('marks')->count();
        $pending = $mine()->pendingEvaluation()->count();
        $unsolved = $mine()->whereNull('marks')->withOpenIssue()->count();

        // Time taken per evaluated sheet (consumed_time, seconds).
        $time = $mine()->whereNotNull('marks')->where('consumed_time', '>', 0)
            ->selectRaw('AVG(consumed_time) as avg_s, MAX(consumed_time) as max_s, MIN(consumed_time) as min_s')
            ->first();
        $activeDays = (int) $mine()->whereNotNull('evaluated_at')->selectRaw('COUNT(DISTINCT DATE(evaluated_at)) as d')->value('d');

        // Last 7 days: completed that day, and still pending at the end of it.
        $daily = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $end = (clone $day)->endOfDay();
            $daily[] = [
                'label' => $day->format('M d'),
                'completed' => $mine()->whereBetween('evaluated_at', [$day, $end])->count(),
                'pending' => $mine()->where('assigned_at', '<=', $end)
                    ->where(fn ($q) => $q->whereNull('evaluated_at')->orWhere('evaluated_at', '>', $end))
                    ->count(),
            ];
        }

        // This week, Monday to Sunday: evaluated per day.
        $week = $this->teacherWeekData($mine, Carbon::now()->startOfWeek(Carbon::MONDAY));

        // The two lists show the first few on the card, all in a "See All" modal.
        [$coursePending, $coursePendingHasMore] = $this->limitedList($this->teacherCoursePendingQuery($mineByCourse), self::TEACHER_LIST_LIMIT);
        [$recent, $recentHasMore] = $this->limitedList($this->teacherRecentQuery($mineByCourse), self::TEACHER_LIST_LIMIT);
        $pendingSql = AnswerSheet::pendingSql();

        return $this->success([
            'kpis' => [
                'assigned' => $assigned,
                'evaluated' => $evaluated,
                'pending' => $pending,
                'problem' => $mine()->whereNotNull('issue_master_id')->count(),
                'unsolved' => $unsolved,
                'resolved' => $mine()->where('issue_status', 'resolved')->count(),
                'pending_courses' => $mineByCourse()->whereRaw($pendingSql)->distinct()->count('c.id'),
                'avg_consume_seconds' => $time?->avg_s !== null ? (int) round($time->avg_s) : null,
                'max_consume_seconds' => $time?->max_s !== null ? (int) $time->max_s : null,
                'min_consume_seconds' => $time?->min_s !== null ? (int) $time->min_s : null,
                'avg_per_day' => $activeDays ? round($evaluated / $activeDays, 1) : 0,
            ],
            'daily' => $daily,
            // Disjoint — completed + pending + problem = assigned.
            'status' => ['completed' => $evaluated, 'pending' => $pending, 'problem' => $unsolved],
            'course_pending' => $this->mapTeacherCourseRows($coursePending),
            'course_pending_has_more' => $coursePendingHasMore,
            'recent' => $this->mapTeacherRecentRows($recent),
            'recent_has_more' => $recentHasMore,
            'recent_status' => $this->teacherRecentStatus($mineByCourse),
            'week' => $week['days'],
            'week_start' => $week['week_start'],
            'week_label' => $week['week_label'],
        ], 'Teacher dashboard fetched successfully.');
    }

    /** GET /dashboard/teacher-course-pending — every course with pending sheets (teacher dashboard's "See All"). */
    public function teacherCoursePending(Request $request): JsonResponse
    {
        [, $mineByCourse] = $this->teacherScopes($request);

        return $this->success($this->mapTeacherCourseRows($this->teacherCoursePendingQuery($mineByCourse)->get()), 'Course wise pending evaluation fetched successfully.');
    }

    /** GET /dashboard/teacher-recent — every course with its window and pending count (teacher dashboard's "See All"). */
    public function teacherRecent(Request $request): JsonResponse
    {
        [, $mineByCourse] = $this->teacherScopes($request);

        return $this->success($this->mapTeacherRecentRows($this->teacherRecentQuery($mineByCourse)->get()), 'Recent evaluation activity fetched successfully.');
    }

    /**
     * GET /dashboard/teacher-week?week_start=YYYY-MM-DD — evaluated per day for
     * the week containing that date (Monday to Sunday), for the teacher
     * dashboard's week picker. Defaults to the current week.
     */
    public function teacherWeek(Request $request): JsonResponse
    {
        $request->validate(['week_start' => ['nullable', 'date_format:Y-m-d']]);
        [$mine] = $this->teacherScopes($request);
        $date = $request->filled('week_start') ? Carbon::createFromFormat('Y-m-d', $request->string('week_start')->toString()) : Carbon::now();

        return $this->success($this->teacherWeekData($mine, $date->startOfWeek(Carbon::MONDAY)->startOfDay()), 'Weekly performance fetched successfully.');
    }

    /**
     * GET /dashboard/hod-summary — the HOD dashboard (designed_files/
     * hod_dashboard.png). Everything is the HOD's own department(s) only —
     * the departments they're mapped to (teacher_department_mappings, same
     * as on the Teacher form): a sheet belongs to a department through its
     * packet's department_id (required on both Upload Answer Sheets and
     * Generate QR). Scoped to the header's exam year / examination like the
     * other dashboards. The "My …" cards are the HOD's own evaluation work
     * as a teacher (every sheet assigned to them, any department).
     */
    public function hodSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $departments = $user->departments()->get(['departments.id', 'departments.name', 'departments.code']);
        $departmentIds = $departments->pluck('id')->all();

        $scoped = fn (Builder $q) => $q
            ->when($examYear !== null, fn ($q) => $q->whereHas('mapping.questionPaper', fn ($q2) => $q2->where('exam_year', $examYear)))
            ->when($examType !== null, fn ($q) => $q->whereHas('mapping', fn ($q2) => $q2->where('exam_type_id', $examType)));
        // The department's answer sheets (none when the HOD has no department).
        $dept = fn () => $scoped(AnswerSheet::query()->inEvaluation())
            ->whereHas('mapping', fn ($q) => $q->whereIn('department_id', $departmentIds));
        $mine = fn () => $scoped(AnswerSheet::query()->inEvaluation())->where('answer_sheets.teacher_id', $user->id);
        $pendingSql = AnswerSheet::pendingSql();

        $status = $this->evaluationStatus($dept);
        $myTime = $mine()->whereNotNull('marks')->where('consumed_time', '>', 0)->avg('consumed_time');
        $teachers = $this->mapTeacherWorkloadRows($this->teacherWorkloadQuery($dept)->get());
        $totalTeachers = $departmentIds ? User::whereHas('departments', fn ($q) => $q->whereIn('departments.id', $departmentIds))->count() : 0;

        // Last 7 days, oldest first.
        $days = collect(range(6, 0))->map(fn (int $i) => Carbon::today()->subDays($i));
        $between = fn (Carbon $day) => [$day, (clone $day)->endOfDay()];
        $progress = $days->map(fn (Carbon $day) => [
            'label' => $day->format('M d'),
            'assigned' => $dept()->whereBetween('assigned_at', $between($day))->count(),
            'evaluated' => $dept()->whereBetween('evaluated_at', $between($day))->count(),
        ])->all();
        // Issues raised each day, split by where they stand now.
        $issueTrend = $days->map(function (Carbon $day) use ($dept, $between) {
            $row = $dept()->whereNotNull('issue_master_id')->whereBetween('issue_raised_at', $between($day))
                ->selectRaw("COUNT(*) as raised, COALESCE(SUM(CASE WHEN issue_status = 'open' THEN 1 ELSE 0 END), 0) as under_review, COALESCE(SUM(CASE WHEN issue_status = 'resolved' THEN 1 ELSE 0 END), 0) as resolved")
                ->first();

            return ['label' => $day->format('M d'), 'raised' => (int) $row->raised, 'under_review' => (int) $row->under_review, 'resolved' => (int) $row->resolved];
        })->all();

        // Per program + course: evaluated + pending + problem = sheets.
        $courseRows = $dept()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses as c', 'c.id', '=', 'm.course_id')
            ->groupBy('m.program_name', 'c.id', 'c.name', 'c.code', 'c.type')
            ->orderByRaw("SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) DESC")
            ->orderBy('c.name')
            ->selectRaw('m.program_name as program, c.name as name, c.code as code, c.type as type, COUNT(*) as sheets, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                "SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) as pending, ".
                'SUM(CASE WHEN '.AnswerSheet::problemSql().' THEN 1 ELSE 0 END) as problem')
            ->get()
            ->map(fn ($row) => [
                'program' => $row->program,
                'course' => CourseLabel::full($row->name, $row->code, $row->type),
                'sheets' => (int) $row->sheets,
                'evaluated' => (int) $row->evaluated,
                'pending' => (int) $row->pending,
                'problem' => (int) $row->problem,
            ]);
        // Course Wise Pending — the same rows merged across programs, most pending first.
        $coursePending = $courseRows->groupBy('course')
            ->map(fn (Collection $rows, string $course) => ['name' => $course, 'pending' => $rows->sum('pending')])
            ->filter(fn (array $row) => $row['pending'] > 0)
            ->sortByDesc('pending')
            ->values();

        return $this->success([
            'departments' => $departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code])->values()->all(),
            'kpis' => [
                'total_teachers' => $totalTeachers,
                'assigned_teachers' => count($teachers),
                // Courses with answer sheets in the department's packets (the
                // program-course mapping is too sparse to rely on).
                'courses' => $courseRows->pluck('course')->unique()->count(),
                'total_sheets' => $status['total'],
                'evaluated' => $status['evaluated'],
                'pending' => $status['pending_evaluation'],
                'problem' => $status['problem'],
                'resolved' => $dept()->where('issue_status', 'resolved')->count(),
                'my_assigned' => $mine()->count(),
                'my_completed' => $mine()->whereNotNull('marks')->count(),
                'my_pending_subjects' => $mine()->whereRaw($pendingSql)
                    ->join('question_answer_sheet_mappings as mm', 'mm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
                    ->distinct()->count('mm.course_id'),
                'my_avg_review_seconds' => $myTime !== null ? (int) round($myTime) : null,
            ],
            'progress' => $progress,
            'status' => $status,
            'course_pending' => $coursePending->take(self::TEACHER_LIST_LIMIT)->all(),
            'course_pending_total' => $coursePending->count(),
            // Generic, no names (a department can have many teachers):
            // teachers by share of their sheets evaluated, and how the
            // department's sheets are spread across them.
            'teacher_progress' => $this->teacherProgress($dept),
            'teacher_allocation' => $this->teacherAllocation($teachers, $totalTeachers),
            'issues' => [
                'raised' => $dept()->whereNotNull('issue_master_id')->count(),
                'under_review' => $dept()->where('issue_status', 'open')->count(),
                'resolved' => $dept()->where('issue_status', 'resolved')->count(),
                'trend' => $issueTrend,
            ],
            'courses' => $courseRows->values()->all(),
        ], 'HOD dashboard fetched successfully.');
    }

    /** Sheets-per-teacher ranges for the HOD dashboard's allocation chart: [label, min, max|null]. */
    private const LOAD_BUCKETS = [['1–10', 1, 10], ['11–25', 11, 25], ['26–50', 26, 50], ['51–100', 51, 100], ['100+', 101, null]];

    /**
     * HOD dashboard's Teacher Allocation Overview — how the department's
     * sheets are spread over its teachers, without naming anyone: teachers
     * with sheets vs all department teachers, sheets per teacher (avg / min
     * / max) and how many teachers fall in each LOAD_BUCKETS range.
     *
     * @param  list<array{assigned: int}>  $teachers  mapTeacherWorkloadRows() rows
     * @return array<string, mixed>
     */
    private function teacherAllocation(array $teachers, int $totalTeachers): array
    {
        $loads = collect($teachers)->pluck('assigned');

        return [
            'with_sheets' => $loads->count(),
            // Never below the teachers actually holding sheets (one may be
            // from outside the department's mapping).
            'total_teachers' => max($totalTeachers, $loads->count()),
            'avg' => $loads->isEmpty() ? 0 : (int) round($loads->avg()),
            'min' => (int) ($loads->min() ?? 0),
            'max' => (int) ($loads->max() ?? 0),
            'buckets' => array_map(fn (array $b) => [
                'label' => $b[0],
                'count' => $loads->filter(fn (int $n) => $n >= $b[1] && ($b[2] === null || $n <= $b[2]))->count(),
            ], self::LOAD_BUCKETS),
        ];
    }

    /**
     * Evaluated count per day, Monday to Sunday, from $weekStart.
     *
     * @return array{week_start: string, week_label: string, is_current: bool, days: list<array{day: string, date: string, count: int}>}
     */
    private function teacherWeekData(Closure $mine, Carbon $weekStart): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (clone $weekStart)->addDays($i);
            $days[] = [
                'day' => $day->format('D'),
                'date' => $day->format('M d'),
                'count' => $mine()->whereBetween('evaluated_at', [$day, (clone $day)->endOfDay()])->count(),
            ];
        }
        $weekEnd = (clone $weekStart)->addDays(6);

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            // "05 Oct – 11 Oct 2026" (year on both ends only across a year change).
            'week_label' => $weekStart->format($weekStart->year === $weekEnd->year ? 'd M' : 'd M Y').' – '.$weekEnd->format('d M Y'),
            'is_current' => $weekStart->isSameDay(Carbon::now()->startOfWeek(Carbon::MONDAY)),
            'days' => $days,
        ];
    }

    /**
     * The logged-in teacher's own sheets (teacher_id = them), scoped to the
     * header's exam year / exam type like My Pending Course — and the same
     * joined to its packet + course for per-course figures.
     *
     * @return array{0: Closure(): Builder, 1: Closure(): Builder}
     */
    private function teacherScopes(Request $request): array
    {
        $teacherId = $request->user()->id;
        $examYear = $this->examYearScope($request);
        $examType = $this->examTypeScope($request);
        $mine = fn () => AnswerSheet::query()
            ->where('answer_sheets.teacher_id', $teacherId)
            ->when($examYear !== null, fn ($q) => $q->whereHas('mapping.questionPaper', fn ($q2) => $q2->where('exam_year', $examYear)))
            ->when($examType !== null, fn ($q) => $q->whereHas('mapping', fn ($q2) => $q2->where('exam_type_id', $examType)));
        $mineByCourse = fn () => $mine()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('courses as c', 'c.id', '=', 'm.course_id')
            ->whereNull('m.deleted_at');

        return [$mine, $mineByCourse];
    }

    /** Courses with pending sheets, most pending first. */
    private function teacherCoursePendingQuery(Closure $mineByCourse): Builder
    {
        $pendingSql = AnswerSheet::pendingSql();

        return $mineByCourse()
            ->groupBy('c.id', 'c.name', 'c.code', 'c.type')
            ->havingRaw("SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) > 0")
            ->orderByRaw("SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) DESC")
            ->orderBy('c.name')
            ->selectRaw("c.name as name, c.code as code, c.type as type, SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) as pending");
    }

    /** Per course: evaluation window and pending count, most recently active first. */
    private function teacherRecentQuery(Closure $mineByCourse): Builder
    {
        $pendingSql = AnswerSheet::pendingSql();

        return $mineByCourse()
            ->groupBy('c.id', 'c.name', 'c.code', 'c.type')
            ->orderByRaw('MAX(COALESCE(answer_sheets.evaluated_at, answer_sheets.assigned_at)) DESC')
            ->orderBy('c.name')
            ->selectRaw("c.name as name, c.code as code, c.type as type, MIN(answer_sheets.evaluation_start_date) as start_date, MAX(answer_sheets.evaluation_end_date) as end_date, SUM(CASE WHEN {$pendingSql} THEN 1 ELSE 0 END) as pending");
    }

    /**
     * "Recent Evaluation Activity" chart — how many of the teacher's courses
     * are at each stage of their evaluation window: completed (nothing
     * pending), on track, due soon (window ends within DUE_SOON_DAYS days,
     * today included) and overdue (window ended, sheets still pending). A
     * course with no end date and something pending counts as on track.
     *
     * @return array<string, int>
     */
    private function teacherRecentStatus(Closure $mineByCourse): array
    {
        $today = Carbon::today();
        $dueSoonBy = (clone $today)->addDays(self::DUE_SOON_DAYS);
        $counts = ['courses' => 0, 'pending' => 0, 'completed' => 0, 'on_track' => 0, 'due_soon' => 0, 'overdue' => 0];

        foreach ($this->teacherRecentQuery($mineByCourse)->reorder()->get() as $row) {
            $pending = (int) $row->pending;
            $end = $row->end_date ? Carbon::parse($row->end_date)->startOfDay() : null;
            $stage = match (true) {
                $pending === 0 => 'completed',
                $end !== null && $end->lt($today) => 'overdue',
                $end !== null && $end->lte($dueSoonBy) => 'due_soon',
                default => 'on_track',
            };
            $counts[$stage]++;
            $counts['courses']++;
            $counts['pending'] += $pending;
        }

        return $counts;
    }

    private function mapTeacherCourseRows(iterable $rows): array
    {
        // Name (Code) – Type, the code left out when the course has none.
        return collect($rows)->map(fn ($row) => ['name' => CourseLabel::full($row->name, $row->code, $row->type), 'pending' => (int) $row->pending])->values()->all();
    }

    private function mapTeacherRecentRows(iterable $rows): array
    {
        return collect($rows)->map(fn ($row) => [
            'course' => CourseLabel::full($row->name, $row->code, $row->type),
            'start' => $row->start_date ? Carbon::parse($row->start_date)->format('d M Y') : null,
            'end' => $row->end_date ? Carbon::parse($row->end_date)->format('d M Y') : null,
            'pending' => (int) $row->pending,
        ])->values()->all();
    }

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
            ->inEvaluation()
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
            // Absent students' and still-being-scanned sheets aren't answer sheets to evaluate.
            'answer_sheets' => AnswerSheet::inEvaluation()->count(),
        ];
    }

    /**
     * Row 2 — evaluation-workflow counts, scoped to $sheetQuery's exam
     * year. Pending evaluation = assigned, not evaluated and no open
     * issue (AnswerSheet::pendingSql() — sheets with an open issue are
     * counted under pending_issues instead, never both).
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function workflow(Closure $sheetQuery): array
    {
        return [
            'assigned' => $sheetQuery()->whereNotNull('teacher_id')->count(),
            'pending_assignment' => $sheetQuery()->awaitingAssignment()->count(),
            // Shared-pool sheets nobody has started yet (neither assigned
            // nor pending assignment).
            'in_pool' => $sheetQuery()->waitingInPool()->count(),
            'evaluated' => $sheetQuery()->whereNotNull('marks')->count(),
            'pending_evaluation' => $sheetQuery()->whereNotNull('teacher_id')->pendingEvaluation()->count(),
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
     * "Evaluation Status" donut — five disjoint buckets that always sum
     * to total: evaluated; pending_evaluation (assigned, not evaluated, no
     * open issue); problem (open issue); not_assigned (no teacher, not in
     * a pool); in_pool (waiting in a shared pool, not started yet).
     * Same rule as AnswerSheet::pendingSql().
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function evaluationStatus(Closure $sheetQuery): array
    {
        return [
            'total' => $sheetQuery()->count(),
            'evaluated' => $sheetQuery()->whereNotNull('marks')->count(),
            'pending_evaluation' => $sheetQuery()->whereNotNull('teacher_id')->pendingEvaluation()->count(),
            'problem' => $sheetQuery()->whereNull('marks')->withOpenIssue()->count(),
            'not_assigned' => $sheetQuery()->awaitingAssignment()->pendingEvaluation()->count(),
            'in_pool' => $sheetQuery()->waitingInPool()->count(),
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
            // Same as AnswerSheet::scopeInEvaluation() — not absent, packet scanned.
            ->where('answer_sheets.absent', false)
            ->where('question_answer_sheet_mappings.is_scan_complete', true)
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
                'SUM(CASE WHEN answer_sheets.teacher_id IS NOT NULL AND '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) as pending, '.
                'SUM(CASE WHEN '.AnswerSheet::problemSql().' THEN 1 ELSE 0 END) as problem, '.
                'SUM(CASE WHEN answer_sheets.teacher_id IS NULL AND answer_sheets.answer_sheet_pool_id IS NOT NULL AND answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as in_pool',
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
            $problemPct = (int) round($row->problem / $total * 100);
            $poolPct = (int) round(($row->in_pool ?? 0) / $total * 100);

            $mapped[] = [
                'name' => $row->department_name,
                'total' => (int) $row->total,
                'evaluated_pct' => $evaluatedPct,
                'pending_pct' => $pendingPct,
                'problem_pct' => $problemPct,
                'in_pool_pct' => $poolPct,
                'not_assigned_pct' => max(0, 100 - $evaluatedPct - $pendingPct - $problemPct - $poolPct),
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
            ->groupBy('users.id', 'users.name', 'teacher_details.emp_code', 'teacher_details.designation')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('users.name')
            ->selectRaw(
                'users.id as teacher_id, users.name as teacher_name, teacher_details.emp_code as emp_code, teacher_details.designation as designation, COUNT(*) as assigned, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                'SUM(CASE WHEN '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) as pending, '.
                // Not evaluated and stuck on an open issue — with evaluated and
                // pending, the three add up to assigned (the dashboard's chart).
                'SUM(CASE WHEN '.AnswerSheet::problemSql().' THEN 1 ELSE 0 END) as problem, '.
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
                'id' => (int) $row->teacher_id,
                'name' => $row->teacher_name,
                'emp_code' => $row->emp_code,
                'designation' => $row->designation,
                'assigned' => (int) $row->assigned,
                'evaluated' => (int) $row->evaluated,
                'pending' => (int) $row->pending,
                'problem' => (int) $row->problem,
                'issues' => (int) $row->issues,
            ];
        }

        return $mapped;
    }

    /**
     * Teacher Workload card's chart — no names, just how many teachers are
     * at each stage, by the share of their own assigned sheets evaluated:
     * not started (0), under 50%, 50% or more, completed (all). Covers every
     * teacher with sheets (not only the card's top 5), one aggregate query
     * over teacherWorkloadQuery()'s per-teacher rows.
     *
     * @param  Closure(): Builder<AnswerSheet>  $sheetQuery
     * @return array<string, int>
     */
    private function teacherProgress(Closure $sheetQuery): array
    {
        $row = DB::query()
            ->fromSub($this->teacherWorkloadQuery($sheetQuery)->reorder()->toBase(), 't')
            ->selectRaw(
                'COUNT(*) as teachers, COALESCE(SUM(assigned), 0) as assigned, '.
                'COALESCE(SUM(CASE WHEN evaluated = 0 THEN 1 ELSE 0 END), 0) as not_started, '.
                'COALESCE(SUM(CASE WHEN evaluated > 0 AND evaluated * 2 < assigned THEN 1 ELSE 0 END), 0) as under_half, '.
                'COALESCE(SUM(CASE WHEN evaluated * 2 >= assigned AND evaluated < assigned THEN 1 ELSE 0 END), 0) as over_half, '.
                'COALESCE(SUM(CASE WHEN evaluated > 0 AND evaluated = assigned THEN 1 ELSE 0 END), 0) as completed',
            )
            ->first();

        return [
            'teachers' => (int) $row->teachers,
            'assigned' => (int) $row->assigned,
            'not_started' => (int) $row->not_started,
            'under_half' => (int) $row->under_half,
            'over_half' => (int) $row->over_half,
            'completed' => (int) $row->completed,
        ];
    }

    /**
     * "Course Wise Pending Evaluation" — every course with something
     * still pending (AnswerSheet::pendingSql() — regardless of assignment,
     * unlike workflow()'s own assigned-only pending_evaluation; this table
     * is about total unfinished workload per course), most
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
            ->whereNull('courses.deleted_at')
            // Same as AnswerSheet::scopeInEvaluation() — not absent, packet scanned.
            ->where('answer_sheets.absent', false)
            ->where('question_answer_sheet_mappings.is_scan_complete', true);

        if ($examYear !== null) {
            $query->join('question_papers', 'question_papers.id', '=', 'question_answer_sheet_mappings.question_paper_id')
                ->where('question_papers.exam_year', $examYear);
        }

        if ($examType !== null) {
            $query->where('question_answer_sheet_mappings.exam_type_id', $examType);
        }

        return $query
            ->groupBy('courses.id', 'courses.name', 'courses.code', 'courses.type')
            ->havingRaw('SUM(CASE WHEN '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) > 0')
            ->orderByRaw('SUM(CASE WHEN '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) DESC')
            ->selectRaw(
                'courses.name as course_name, courses.code as course_code, courses.type as course_type, COUNT(*) as total, '.
                'SUM(CASE WHEN '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) as pending',
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
                'type' => $row->course_type,
                'pending' => (int) $row->pending,
                'total' => (int) $row->total,
                'pending_pct' => (int) round($row->pending / $total * 100),
            ];
        }

        return $mapped;
    }
}
