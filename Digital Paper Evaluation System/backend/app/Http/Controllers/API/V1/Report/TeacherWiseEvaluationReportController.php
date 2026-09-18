<?php

namespace App\Http\Controllers\API\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Models\ExamType;
use App\Services\MailBrandingService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sidebar's Report → Teacher Wise Report — one row per (teacher, packet) a
 * teacher has any answer sheets in, for the searched program/course/exam
 * term/semester/exam year (and optionally one teacher). Mirrors
 * TeacherController::assignments()'s own per-packet breakdown, just
 * filtered down to one exam offering instead of "everything this teacher
 * has," and enriched with the teacher's own emp_code/phone since this is
 * meant to be printed/exported, not just browsed on screen.
 *
 * Two endpoints share the exact same filtered/grouped query (rows()) so
 * the on-screen table and the downloaded file can never disagree:
 *  - index()  — GET, returns the rows as JSON for TeacherWiseEvaluation
 *    ReportView.vue's own results table.
 *  - export() — GET .../export?format=excel|pdf, renders the same rows
 *    through one shared Blade view (resources/views/reports/teacher-wise-
 *    evaluation.blade.php) either straight to a browser download (Excel —
 *    an HTML table served as .xls; Excel opens this natively, no
 *    spreadsheet library needed for something this simple) or through
 *    dompdf for an actual PDF.
 */
class TeacherWiseEvaluationReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MailBrandingService $branding) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->success(['rows' => $this->rows($filters)], 'Teacher wise evaluation report fetched successfully.');
    }

    public function export(Request $request): Response
    {
        $filters = $this->validateFilters($request);
        $format = $request->string('format')->lower()->toString() === 'pdf' ? 'pdf' : 'excel';

        $html = view('reports.teacher-wise-evaluation', [
            'siteTitle' => $this->branding->resolve()['site_title'],
            'logoDataUri' => $this->resolveLogoDataUri(),
            'downloadedAt' => now()->format('d-m-Y h:i:s A'),
            'printedBy' => $request->user()->name,
            // Resolved once here rather than read off the first row —
            // every row already carries the same exam_type_name (it's a
            // search filter, not something that varies row to row), but
            // this stays correct even for a search with zero matching rows.
            'examinationName' => ExamType::find($filters['exam_type_id'])?->name,
            'rows' => $this->rows($filters),
            // Only the PDF branch gets the "Page X of Y" footer stamped
            // (see the view's own docblock on why) — Excel's HTML import
            // has no concept of pages at all, so there'd be nothing
            // meaningful to stamp on that download.
            'isPdf' => $format === 'pdf',
        ])->render();

        if ($format === 'pdf') {
            // The page-number footer is drawn by a <script type="text/php">
            // block in the shared view, which dompdf only executes when
            // this option is on. Scoped to just this one PDF instance
            // (not the global config) since the HTML it runs against is
            // always ours, never user-supplied.
            return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->setOption('isPhpEnabled', true)->download('teacher_wise_evaluation_report.pdf');
        }

        // Deliberately not a real .xlsx — a plain HTML <table> served with
        // an Excel content-type/filename, which Excel opens natively as a
        // spreadsheet. No spreadsheet library needed for a flat table this
        // simple; see the shared Blade view's own docblock.
        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="teacher_wise_evaluation_report.xls"',
        ]);
    }

    /**
     * The report's own header logo, inlined as a data: URI so the exported
     * file is fully self-contained — an <img src="https://…"> would need
     * the viewer to still be online (and CORS-friendly) at *open* time,
     * which defeats the point of a downloaded report, and Excel's own
     * HTML-as-.xls import doesn't reliably fetch remote images at all. A
     * custom-uploaded logo lives on this same backend's own public disk
     * (see MailBrandingService::resolve()'s own docblock on the two paths
     * a logo_url can take) and is read straight off disk, no network
     * round trip; the seeded default points at the *frontend's* own
     * public/ folder instead, which this backend can't read directly, so
     * that case falls back to the bundled copy at resources/images/
     * report-logo.png — same image, just duplicated here for exactly
     * this reason.
     */
    private function resolveLogoDataUri(): ?string
    {
        $logoPath = $this->branding->resolve()['logo_url'];

        if ($logoPath && str_contains($logoPath, '/storage/')) {
            $relative = Str::after($logoPath, '/storage/');
            if (Storage::disk('public')->exists($relative)) {
                $mime = Storage::disk('public')->mimeType($relative) ?: 'image/png';

                return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($relative));
            }
        }

        $fallback = resource_path('images/report-logo.png');

        return is_file($fallback)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($fallback))
            : null;
    }

    /**
     * @return array{program_name: string, course_id: int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'program_name' => ['required', 'string'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_year' => ['required', 'integer', 'digits:4'],
            // Optional — narrows the same search down to one faculty
            // member instead of listing every teacher who touched this
            // exam offering.
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at')],
        ]);
    }

    /**
     * @param  array{program_name: string, course_id: int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters): array
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->join('users as u', 'u.id', '=', 'answer_sheets.teacher_id')
            ->leftJoin('teacher_details as td', function ($join) {
                $join->on('td.user_id', '=', 'u.id')->whereNull('td.deleted_at');
            })
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->leftJoin('exam_types as et', 'et.id', '=', 'm.exam_type_id')
            ->whereNotNull('answer_sheets.teacher_id')
            ->whereNull('m.deleted_at')
            ->whereNull('u.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.course_id', $filters['course_id'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year']);

        if (! empty($filters['teacher_id'])) {
            $query->where('answer_sheets.teacher_id', $filters['teacher_id']);
        }

        $rows = $query
            ->groupBy('u.id', 'u.name', 'td.emp_code', 'u.phone_no', 'c.name', 'c.code', 'et.name', 'm.id', 'm.semester')
            ->orderBy('u.name')
            ->selectRaw(
                'u.name as teacher_name, td.emp_code as emp_code, u.phone_no as mobile_no, '.
                'et.name as exam_type_name, '.
                'c.name as subject_name, c.code as subject_code, m.semester as semester, COUNT(*) as allotted_script, '.
                'MIN(answer_sheets.assigned_at) as allocation_date, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as total_evaluated, '.
                'SUM(CASE WHEN answer_sheets.issue_master_id IS NOT NULL THEN 1 ELSE 0 END) as total_problem_script, '.
                'SUM(CASE WHEN answer_sheets.marks IS NULL THEN 1 ELSE 0 END) as total_pending, '.
                'MIN(answer_sheets.evaluation_start_date) as evaluation_start_date, '.
                'MAX(answer_sheets.evaluation_end_date) as evaluation_end_date',
            )
            ->get();

        $formatDate = fn ($value) => $value ? Carbon::parse($value)->format('d-m-Y h:i A') : null;

        return $rows->map(fn ($row) => [
            'teacher_name' => $row->teacher_name,
            'emp_code' => $row->emp_code,
            'mobile_no' => $row->mobile_no,
            'exam_type_name' => $row->exam_type_name,
            'subject_name' => $row->subject_name,
            'subject_code' => $row->subject_code,
            'semester' => (int) $row->semester,
            'allotted_script' => (int) $row->allotted_script,
            'allocation_date' => $formatDate($row->allocation_date),
            'total_evaluated' => (int) $row->total_evaluated,
            'total_problem_script' => (int) $row->total_problem_script,
            'total_pending' => (int) $row->total_pending,
            'evaluation_start_date' => $formatDate($row->evaluation_start_date),
            'evaluation_end_date' => $formatDate($row->evaluation_end_date),
        ])->values()->all();
    }
}
