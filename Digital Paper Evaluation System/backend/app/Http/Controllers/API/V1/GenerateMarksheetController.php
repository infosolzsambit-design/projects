<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Helpers\CourseLabel;
use App\Models\AnswerSheet;
use App\Models\GeneralSetting;
use App\Services\AuditLogService;
use App\Services\MailBrandingService;
use App\Services\StudentIdCheckService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Sidebar's "Generate Marksheet" — per course, how many answer sheets are
 * evaluated/pending and whether each sheet's handwritten STUDENT'S ID NO.
 * matches its system roll number (read once per sheet in the background —
 * see StudentIdCheckService). Everything here is a count/list over stored
 * results, so it stays fast across thousands of sheets. No permission gate
 * yet (to be added later).
 */
class GenerateMarksheetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /** GET /generate-marksheet — one row per course in the searched exam offering. */
    public function summary(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);
        $matched = StudentIdCheckService::matchedSql();
        $needsReview = StudentIdCheckService::needsReviewSql();

        $rows = $this->scoped($filters)
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->leftJoin('exam_terms as term', 'term.id', '=', 'm.exam_term_id')
            ->leftJoin('exam_types as et', 'et.id', '=', 'm.exam_type_id')
            ->groupBy('c.id', 'c.name', 'c.code', 'c.type', 'term.name', 'et.name', 'm.semester')
            ->orderBy('c.name')
            ->selectRaw(
                'c.id as course_id, c.name as course_name, c.code as course_code, c.type as course_type, '.
                'term.name as exam_term_name, et.name as exam_type_name, m.semester as semester, '.
                'COUNT(*) as total_count, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated_count, '.
                'SUM(CASE WHEN '.AnswerSheet::pendingSql().' THEN 1 ELSE 0 END) as pending_count, '.
                'SUM(CASE WHEN '.AnswerSheet::problemSql().' THEN 1 ELSE 0 END) as problem_count, '.
                "SUM(CASE WHEN {$matched} THEN 1 ELSE 0 END) as matched_count, ".
                "SUM(CASE WHEN {$needsReview} THEN 1 ELSE 0 END) as mismatched_count, ".
                'SUM(CASE WHEN answer_sheets.roll_no_check_status IS NULL THEN 1 ELSE 0 END) as not_checked_count, '.
                // The packets' own departments (chosen on Answer Sheet Upload) —
                // usually one per course; every one is listed if not.
                "GROUP_CONCAT(DISTINCT m.department_name ORDER BY m.department_name SEPARATOR '||') as department_names",
            )
            ->get()
            ->map(fn ($row) => [
                'course_id' => (int) $row->course_id,
                'course_name' => $row->course_name,
                'course_code' => $row->course_code,
                'course_type' => $row->course_type,
                'exam_term_name' => $row->exam_term_name,
                'exam_type_name' => $row->exam_type_name,
                'semester' => (int) $row->semester,
                'total_count' => (int) $row->total_count,
                'evaluated_count' => (int) $row->evaluated_count,
                'pending_count' => (int) $row->pending_count,
                'problem_count' => (int) $row->problem_count,
                'matched_count' => (int) $row->matched_count,
                'mismatched_count' => (int) $row->mismatched_count,
                'not_checked_count' => (int) $row->not_checked_count,
                'department_names' => $row->department_names ? explode('||', $row->department_names) : [],
            ]);

        return $this->success(['rows' => $rows], 'Marksheet summary fetched successfully.');
    }

    /**
     * GET /generate-marksheet/mismatches?course_id=.. (+ same filters) —
     * the sheets needing review for one course, paginated.
     */
    public function mismatches(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, courseRequired: true);
        $perPage = min((int) $request->input('per_page', 20), 100);

        $page = $this->scoped($filters)
            ->whereRaw(StudentIdCheckService::needsReviewSql())
            ->orderBy('answer_sheets.id')
            ->select('answer_sheets.*')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($page->items())->map(fn (AnswerSheet $sheet) => [
                'id' => $sheet->id,
                'qr_code' => $sheet->subject_barcode ?: $sheet->barcode,
                'roll_no' => $sheet->roll_no,
                'student_id_read' => $sheet->student_id_read,
                'status' => $sheet->roll_no_check_status,
                'crop_url' => $sheet->student_id_crop_path,
                'pdf_url' => $sheet->pdf_path,
            ])->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ], 'Roll number mismatches fetched successfully.');
    }

    /**
     * POST /generate-marksheet/check — reads the student ID on the next
     * batch of not-yet-checked sheets in the searched scope, right now (no
     * queue), and reports progress. The page calls this repeatedly until
     * `remaining` is 0, driving its progress bar from `checked`/`total`.
     */
    public function check(Request $request, StudentIdCheckService $service): JsonResponse
    {
        $filters = $this->validateFilters($request);
        $limit = min(max((int) $request->input('limit', 25), 1), 50);

        $sheets = $this->scoped($filters)
            ->whereNull('answer_sheets.roll_no_check_status')
            ->whereNotNull('answer_sheets.pdf_path')
            ->orderBy('answer_sheets.id')
            ->limit($limit)
            ->get(['answer_sheets.id', 'answer_sheets.question_answer_sheet_mapping_id', 'answer_sheets.pdf_path']);

        if ($sheets->isNotEmpty()) {
            $service->checkMany($sheets);
        }

        return $this->success($this->progress($filters) + ['processed' => $sheets->count()], 'Roll number check progress.');
    }

    /**
     * POST /generate-marksheet/recheck — clears the stored reading for the
     * searched scope (optionally one course) so the next check() rounds
     * read those sheets again. Sheets a person has manually confirmed are
     * left alone — a recheck never overrides a human decision.
     */
    public function recheck(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        $reset = AnswerSheet::whereIn('id', $this->scoped($filters)
            ->whereNotNull('answer_sheets.roll_no_check_status')
            ->whereNull('answer_sheets.student_id_verified_by')
            ->pluck('answer_sheets.id'))
            ->update([
                'roll_no_check_status' => null,
                'student_id_read' => null,
                'roll_no_checked_at' => null,
            ]);

        return $this->success($this->progress($filters) + ['reset' => $reset], "{$reset} answer sheet(s) will be checked again.");
    }

    /**
     * GET /generate-marksheet/export?course_id=..&format=excel|pdf (+ same
     * filters) — the course's marksheet, one row per answer sheet. Only
     * available once every sheet is evaluated and every roll number
     * matches (the page hides the buttons otherwise; this enforces it).
     */
    public function export(Request $request, MailBrandingService $branding): SymfonyResponse
    {
        $filters = $this->validateFilters($request, courseRequired: true);
        $format = $request->string('format')->lower()->toString() === 'pdf' ? 'pdf' : 'excel';

        $readiness = $this->scoped($filters)
            ->selectRaw(
                'COUNT(*) as total, '.
                'SUM(CASE WHEN answer_sheets.marks IS NOT NULL THEN 1 ELSE 0 END) as evaluated, '.
                'SUM(CASE WHEN '.StudentIdCheckService::matchedSql().' THEN 1 ELSE 0 END) as matched',
            )
            ->first();
        $total = (int) $readiness->total;
        if ($total === 0 || (int) $readiness->evaluated < $total || (int) $readiness->matched < $total) {
            return $this->error('The marksheet can be generated only when every answer sheet is evaluated and every roll number matches.', 422);
        }

        $rows = $this->scoped($filters)
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->leftJoin('exam_terms as term', 'term.id', '=', 'm.exam_term_id')
            ->leftJoin('exam_types as et', 'et.id', '=', 'm.exam_type_id')
            ->orderBy('answer_sheets.roll_no')
            ->get([
                'm.program_name', 'm.department_name', 'c.name as course_name', 'c.code as course_code', 'c.type as course_type',
                'term.name as exam_term_name', 'et.name as exam_type_name', 'm.semester',
                'answer_sheets.subject_barcode', 'answer_sheets.barcode', 'answer_sheets.name as student_name',
                'answer_sheets.roll_no', 'answer_sheets.registration_no', 'qp.full_marks',
                'answer_sheets.marks', 'answer_sheets.evaluated_at',
            ])
            ->map(fn ($row) => [
                'program_name' => $row->program_name,
                'department_name' => $row->department_name,
                'course_name' => $row->course_name,
                'course_code' => $row->course_code,
                'course_type' => $row->course_type,
                'exam_term_name' => $row->exam_term_name,
                'exam_type_name' => $row->exam_type_name,
                'semester' => self::ordinal((int) $row->semester),
                'barcode' => $row->subject_barcode ?: $row->barcode,
                'student_name' => $row->student_name,
                'roll_no' => $row->roll_no,
                'registration_no' => $row->registration_no,
                'total_marks' => $row->full_marks,
                'obtained_marks' => $row->marks !== null ? rtrim(rtrim(number_format((float) $row->marks, 2, '.', ''), '0'), '.') : null,
                'lock_in_time' => $row->evaluated_at ? Carbon::parse($row->evaluated_at)->format('d-m-Y h:i A') : null,
            ]);

        $first = $rows->first();
        $html = view('reports.marksheet', [
            'siteTitle' => $branding->resolve()['site_title'],
            'logoDataUri' => $branding->reportLogoDataUri(),
            'organizationLogoDataUri' => $branding->organizationLogoDataUri(),
            'downloadedAt' => now()->format('d-m-Y h:i:s A'),
            'printedBy' => $request->user()->name,
            'examinationName' => $first['exam_type_name'] ?? null,
            'courseLabel' => $first ? CourseLabel::full($first['course_name'], $first['course_code'], $first['course_type']) : null,
            'rows' => $rows,
            // General Settings → "University Code" (looked up by field
            // name, not id, so it's the same on every server). Same value
            // on every row.
            'universityCode' => GeneralSetting::where('field_name', 'university_code')->where('status', true)->value('value'),
            'isPdf' => $format === 'pdf',
        ])->render();

        $filename = 'marksheet_'.Str::slug($first['course_code'] ?? 'course', '_');

        if ($format === 'pdf') {
            return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->setOption('isPhpEnabled', true)->download($filename.'.pdf');
        }

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xls"',
        ]);
    }

    /** 1 -> "1st", 2 -> "2nd", 3 -> "3rd", 4 -> "4th", 11 -> "11th"… */
    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /**
     * @return array{total: int, checked: int, remaining: int}
     */
    private function progress(array $filters): array
    {
        $counts = $this->scoped($filters)
            ->whereNotNull('answer_sheets.pdf_path')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN answer_sheets.roll_no_check_status IS NOT NULL THEN 1 ELSE 0 END) as checked')
            ->first();
        $total = (int) $counts->total;
        $checked = (int) $counts->checked;

        return ['total' => $total, 'checked' => $checked, 'remaining' => $total - $checked];
    }

    /**
     * POST /generate-marksheet/confirm-match {answer_sheet_ids: [...]} — a
     * reviewer confirms that, on each selected sheet, the ID the student
     * wrote IS the system roll number (the machine reading was wrong, or
     * couldn't read it). student_id_read is corrected to the current roll
     * number, so the sheet counts as matched — and would become a mismatch
     * again if that roll number were later changed. The original reading is
     * kept in the audit log.
     */
    public function confirmMatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'answer_sheet_ids' => ['required', 'array', 'min:1', 'max:500'],
            'answer_sheet_ids.*' => ['integer', 'distinct', Rule::exists('answer_sheets', 'id')->whereNull('deleted_at')],
        ]);

        $sheets = AnswerSheet::whereIn('id', $data['answer_sheet_ids'])
            ->get(['id', 'roll_no', 'student_id_read', 'roll_no_check_status']);

        $userId = $request->user()->id;
        $confirmed = 0;
        foreach ($sheets as $sheet) {
            $rollDigits = StudentIdCheckService::digitsOnly($sheet->roll_no);
            if ($rollDigits === '') {
                continue; // nothing to confirm against
            }
            AnswerSheet::whereKey($sheet->id)->update([
                'student_id_read' => $rollDigits,
                'roll_no_check_status' => StudentIdCheckService::READ,
                'student_id_verified_by' => $userId,
                'student_id_verified_at' => now(),
            ]);
            $confirmed++;
        }

        $this->auditLog->log(
            event: 'student-id-confirmed',
            module: 'Generate Marksheet',
            description: "Confirmed the written student ID matches the roll number on {$confirmed} answer sheet(s).",
            oldValues: ['readings' => $sheets->mapWithKeys(fn ($s) => [$s->id => $s->student_id_read])->all()],
            newValues: ['answer_sheet_ids' => $sheets->pluck('id')->all()],
            tags: ['generate-marksheet', 'roll-number'],
        );

        return $this->success(['confirmed' => $confirmed], "Marked {$confirmed} answer sheet(s) as matched.");
    }

    /**
     * @return array{program_name: string, course_id: ?int, department_id: ?int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int}
     */
    private function validateFilters(Request $request, bool $courseRequired = false): array
    {
        return $request->validate([
            'program_name' => ['required', 'string'],
            'course_id' => [$courseRequired ? 'required' : 'nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            // Optional — the packet's department (question_answer_sheet_mappings.department_id).
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'exam_term_id' => ['required', 'integer', Rule::exists('exam_terms', 'id')->whereNull('deleted_at')],
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->whereNull('deleted_at')],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'exam_year' => ['required', 'integer', 'digits:4'],
        ]);
    }

    private function scoped(array $filters): Builder
    {
        return AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->whereNull('m.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year'])
            ->when(! empty($filters['course_id']), fn ($q) => $q->where('m.course_id', $filters['course_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('m.department_id', $filters['department_id']));
    }
}
