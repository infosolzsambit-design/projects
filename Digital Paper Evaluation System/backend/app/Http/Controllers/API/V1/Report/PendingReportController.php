<?php

namespace App\Http\Controllers\API\V1\Report;

use App\Helpers\CourseLabel;
use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Models\Course;
use App\Models\ExamType;
use App\Services\MailBrandingService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Sidebar's Report → Pending Report — which teacher still has how many
 * answer sheets left to evaluate, for one searched exam offering. Same
 * search fields as the Teacher Wise Report (optional Department and
 * Teacher), but one row per teacher (all their packets in the search
 * added together) and only teachers who actually have something pending.
 * "Pending" is the app-wide rule — AnswerSheet::pendingSql(): not
 * evaluated and no open problem (problems are counted separately).
 *
 * index() and export() share rows(), so the on-screen table and the
 * downloaded Excel/PDF can never disagree. No permission gate.
 */
class PendingReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MailBrandingService $branding) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->success([
            'rows' => $this->rows($filters),
            'pool' => $this->poolSummary($filters),
        ], 'Pending report fetched successfully.');
    }

    public function export(Request $request): Response
    {
        $filters = $this->validateFilters($request);
        $format = $request->string('format')->lower()->toString() === 'pdf' ? 'pdf' : 'excel';

        $html = view('reports.pending-report', [
            'siteTitle' => $this->branding->resolve()['site_title'],
            'logoDataUri' => $this->branding->reportLogoDataUri(),
            'organizationLogoDataUri' => $this->branding->organizationLogoDataUri(),
            'downloadedAt' => now()->format('d-m-Y h:i:s A'),
            'printedBy' => $request->user()->name,
            'examinationName' => ExamType::find($filters['exam_type_id'])?->name,
            'courseLabel' => CourseLabel::of(Course::find($filters['course_id'])),
            'rows' => $this->rows($filters),
            'pool' => $this->poolSummary($filters),
            'isPdf' => $format === 'pdf',
        ])->render();

        if ($format === 'pdf') {
            return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->setOption('isPhpEnabled', true)->download('pending_report.pdf');
        }

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="pending_report.xls"',
        ]);
    }

    /**
     * @return array{program_name: string, course_id: int, department_id: ?int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'program_name' => ['required', 'string'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            // Optional — the packet's department (question_answer_sheet_mappings.department_id).
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_year' => ['required', 'integer', 'digits:4'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at')],
        ]);
    }

    /**
     * Sheets of this search still waiting in a shared pool (Assign Teacher →
     * Pool) — not started by anyone yet, so they belong to no teacher row
     * above; listed with the teachers who share those pools. With a Teacher
     * filter, only pools that teacher is in.
     *
     * @return array{pending: int, teachers: list<string>}
     */
    private function poolSummary(array $filters): array
    {
        $sheets = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->whereNull('m.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.course_id', $filters['course_id'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year'])
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('m.department_id', $filters['department_id']))
            ->when(! empty($filters['teacher_id']), fn ($q) => $q->whereIn('answer_sheets.answer_sheet_pool_id', AnswerSheet::poolIdsFor((int) $filters['teacher_id'])))
            ->waitingInPool();

        $pending = (clone $sheets)->count();
        $teachers = $pending
            ? \Illuminate\Support\Facades\DB::table('answer_sheet_pool_teachers as apt')
                ->join('users as u', 'u.id', '=', 'apt.teacher_id')
                ->whereIn('apt.answer_sheet_pool_id', (clone $sheets)->select('answer_sheets.answer_sheet_pool_id')->distinct())
                ->orderBy('u.name')
                ->distinct()
                ->pluck('u.name')
                ->all()
            : [];

        return ['pending' => $pending, 'teachers' => $teachers];
    }

    /**
     * One row per teacher with at least one pending sheet, most pending first.
     *
     * @param  array{program_name: string, course_id: int, department_id: ?int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters): array
    {
        $pending = AnswerSheet::pendingSql();

        $rows = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->join('users as u', 'u.id', '=', 'answer_sheets.teacher_id')
            ->leftJoin('teacher_details as td', function ($join) {
                $join->on('td.user_id', '=', 'u.id')->whereNull('td.deleted_at');
            })
            ->whereNotNull('answer_sheets.teacher_id')
            ->whereNull('m.deleted_at')
            ->whereNull('u.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.course_id', $filters['course_id'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year'])
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('m.department_id', $filters['department_id']))
            ->when(! empty($filters['teacher_id']), fn ($q) => $q->where('answer_sheets.teacher_id', $filters['teacher_id']))
            ->groupBy('u.id', 'u.name', 'u.email', 'u.phone_no', 'td.emp_code')
            ->havingRaw("SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) > 0")
            ->orderByRaw("SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) DESC")
            ->orderBy('u.name')
            ->selectRaw(
                'u.name as teacher_name, td.emp_code as emp_code, u.email as email, u.phone_no as mobile_no, '.
                // The packets' own departments (chosen on Answer Sheet Upload).
                "GROUP_CONCAT(DISTINCT m.department_name ORDER BY m.department_name SEPARATOR '||') as department_names, ".
                'COUNT(*) as allotted, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                "SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) as pending, ".
                'SUM(CASE WHEN '.AnswerSheet::problemSql().' THEN 1 ELSE 0 END) as problem, '.
                'MIN(answer_sheets.evaluation_start_date) as evaluation_start_date, '.
                'MAX(answer_sheets.evaluation_end_date) as evaluation_end_date',
            )
            ->get();

        $formatDate = fn ($value) => $value ? Carbon::parse($value)->format('d-m-Y h:i A') : null;

        return $rows->map(fn ($row) => [
            'teacher_name' => $row->teacher_name,
            'emp_code' => $row->emp_code,
            'email' => $row->email,
            'mobile_no' => $row->mobile_no,
            'department_names' => $row->department_names ? explode('||', $row->department_names) : [],
            'allotted' => (int) $row->allotted,
            'evaluated' => (int) $row->evaluated,
            'pending' => (int) $row->pending,
            'problem' => (int) $row->problem,
            'evaluation_start_date' => $formatDate($row->evaluation_start_date),
            'evaluation_end_date' => $formatDate($row->evaluation_end_date),
            // Window already closed with sheets still pending.
            'overdue' => $row->evaluation_end_date !== null && Carbon::parse($row->evaluation_end_date)->isPast(),
        ])->values()->all();
    }
}
