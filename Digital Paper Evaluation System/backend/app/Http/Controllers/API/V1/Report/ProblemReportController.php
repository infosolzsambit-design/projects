<?php

namespace App\Http\Controllers\API\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Models\ExamType;
use App\Services\MailBrandingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Sidebar's Report → Problem Report — one row per answer sheet a teacher
 * has raised an issue on (printing or timing, open or resolved), for the
 * searched program/exam term/exam type/semester/exam year, optionally
 * narrowed to one course and/or one teacher. Same search shape as
 * TeacherWiseEvaluationReportController (Course is optional here), and the
 * same rows() feeds both the on-screen table and the Excel download so they
 * can never disagree. Excel only — no PDF for this report.
 */
class ProblemReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MailBrandingService $branding) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->success(['rows' => $this->rows($filters)], 'Problem report fetched successfully.');
    }

    public function export(Request $request): Response
    {
        $filters = $this->validateFilters($request);

        $html = view('reports.problem-report', [
            'siteTitle' => $this->branding->resolve()['site_title'],
            'logoDataUri' => $this->branding->reportLogoDataUri(),
            'organizationLogoDataUri' => $this->branding->organizationLogoDataUri(),
            'downloadedAt' => now()->format('d-m-Y h:i:s A'),
            'printedBy' => $request->user()->name,
            'examinationName' => ExamType::find($filters['exam_type_id'])?->name,
            'rows' => $this->rows($filters),
        ])->render();

        // Same HTML-table-as-.xls approach as the Teacher Wise report.
        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="problem_report.xls"',
        ]);
    }


    /**
     * @return array{program_name: string, course_id: ?int, department_id: ?int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'program_name' => ['required', 'string'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
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
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters): array
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            // The teacher who actually raised it — not necessarily today's
            // teacher_id if the sheet was reassigned since.
            ->leftJoin('users as u', 'u.id', '=', 'answer_sheets.issue_raised_by')
            ->leftJoin('teacher_details as td', function ($join) {
                $join->on('td.user_id', '=', 'u.id')->whereNull('td.deleted_at');
            })
            ->leftJoin('users as fixer', 'fixer.id', '=', 'answer_sheets.issue_fixed_by')
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->leftJoin('exam_terms as term', 'term.id', '=', 'm.exam_term_id')
            ->leftJoin('exam_types as et', 'et.id', '=', 'm.exam_type_id')
            ->leftJoin('issue_masters as im', 'im.id', '=', 'answer_sheets.issue_master_id')
            ->whereNotNull('answer_sheets.issue_master_id')
            ->whereNull('m.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year']);

        if (! empty($filters['course_id'])) {
            $query->where('m.course_id', $filters['course_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('m.department_id', $filters['department_id']);
        }

        if (! empty($filters['teacher_id'])) {
            $query->where('answer_sheets.issue_raised_by', $filters['teacher_id']);
        }

        $rows = $query
            ->orderByDesc('answer_sheets.issue_raised_at')
            ->selectRaw(
                'u.name as teacher_name, td.emp_code as emp_code, m.program_name as program_name, '.
                'c.name as course_name, c.code as course_code, c.type as course_type, m.semester as semester, '.
                'term.name as exam_term_name, et.name as exam_type_name, qp.exam_year as exam_year, '.
                'answer_sheets.subject_barcode as subject_barcode, answer_sheets.barcode as barcode, '.
                'im.name as issue_type, answer_sheets.issue_status as issue_status, '.
                'answer_sheets.issue_remarks as teacher_remarks, answer_sheets.issue_raised_at as issue_raised_at, '.
                'fixer.name as solved_by, answer_sheets.issue_fixed_at as solved_at, '.
                'answer_sheets.issue_admin_remarks as admin_remarks',
            )
            ->get();

        $formatDate = fn ($value) => $value ? Carbon::parse($value)->format('d-m-Y h:i A') : null;

        return $rows->map(fn ($row) => [
            'teacher_name' => $row->teacher_name,
            'emp_code' => $row->emp_code,
            'program_name' => $row->program_name,
            'course_name' => $row->course_name,
            'course_code' => $row->course_code,
            'course_type' => $row->course_type,
            'semester' => (int) $row->semester,
            'exam_term_name' => $row->exam_term_name,
            'exam_type_name' => $row->exam_type_name,
            'exam_year' => (int) $row->exam_year,
            'qr_code' => $row->subject_barcode ?: $row->barcode,
            'issue_type' => $row->issue_type,
            'status' => $row->issue_status === 'resolved' ? 'Resolved' : 'Open',
            'teacher_remarks' => $row->teacher_remarks,
            'issue_raised_at' => $formatDate($row->issue_raised_at),
            'solved_by' => $row->solved_by,
            'solved_at' => $formatDate($row->solved_at),
            'admin_remarks' => $row->admin_remarks,
        ])->values()->all();
    }
}
