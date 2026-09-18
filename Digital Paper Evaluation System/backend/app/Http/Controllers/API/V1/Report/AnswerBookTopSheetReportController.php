<?php

namespace App\Http\Controllers\API\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\AnswerSheet;
use App\Models\QuestionPaperNode;
use App\Services\MailBrandingService;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Sidebar's Report → Answer Book / Top Sheet — one generated "Evaluation
 * Answer Book" cover sheet per physical answer sheet (see top_sheet.jpeg
 * in designed_files/ for the reference layout this mirrors), never
 * grouped/aggregated the way TeacherWiseEvaluationReportController's rows
 * are. This is NOT the student's own scanned script — that's
 * answer_sheets.pdf_path, a wholly separate file uploaded at packet
 * intake — this is a fresh document generated from this sheet's own
 * stored data (identity fields + its per-question draft_marks_breakdown).
 *
 * Three endpoints, same filters throughout (program_name, course_id,
 * exam_term_id, exam_type_id, semester, exam_year required; teacher_id
 * optional — see validateFilters()):
 *  - index()  — GET, returns EVERY matching answer sheet (evaluated or
 *    still pending) for ReportView.vue's own results table. A pending
 *    sheet (marks still null) has nothing to view/download yet.
 *  - view()   — GET .../{answerSheet}/view, streams ONE sheet's own top
 *    sheet inline — 422s if that sheet has no final marks yet.
 *  - export() — GET .../export?format=pdf|zip, bundles every *evaluated*
 *    sheet matching the filters (pending ones are silently skipped, not
 *    failing the whole export) — 'pdf' as one combined multi-page PDF
 *    (each sheet its own page), 'zip' as one PDF file per sheet.
 */
class AnswerBookTopSheetReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MailBrandingService $branding) {}

    /**
     * Every question_paper_nodes row for a given question paper, keyed by
     * question_paper_id — an export can render many sheets that all share
     * the same question paper (the common case: one packet, one paper),
     * so this avoids re-querying the same tree once per sheet.
     *
     * @var array<int, Collection<int, QuestionPaperNode>>
     */
    private array $questionNodesCache = [];

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->success(['rows' => $this->rows($filters)], 'Answer book / top sheet report fetched successfully.');
    }

    public function view(AnswerSheet $answerSheet): Response
    {
        if ($answerSheet->marks === null) {
            abort(422, 'This answer sheet has not been evaluated yet — there is no top sheet to view.');
        }

        $html = $this->renderSheets([$answerSheet]);

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->setOption('isPhpEnabled', true)
            ->stream('top_sheet_'.($answerSheet->roll_no ?: $answerSheet->id).'.pdf');
    }

    public function export(Request $request): Response
    {
        $filters = $this->validateFilters($request);
        $format = $request->string('format')->lower()->toString() === 'zip' ? 'zip' : 'pdf';

        $sheets = $this->evaluatedSheetsQuery($filters)->get();

        if ($sheets->isEmpty()) {
            abort(422, 'No evaluated answer sheets found for this search — a top sheet needs final marks first.');
        }

        if ($format === 'zip') {
            return $this->zipResponse($sheets);
        }

        $html = $this->renderSheets($sheets->all());

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->setOption('isPhpEnabled', true)
            ->download('answer_book_top_sheets.pdf');
    }

    /**
     * @param  Collection<int, AnswerSheet>  $sheets
     */
    private function zipResponse(Collection $sheets): Response
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'top-sheets-').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Two sheets can slug down to the same filename (blank/duplicate
        // roll numbers happen in real CSV uploads) — silently overwriting
        // inside the zip would just make one sheet vanish from the
        // download, so every collision gets a -2/-3/… suffix instead.
        $usedNames = [];
        foreach ($sheets as $sheet) {
            $pdfBytes = Pdf::loadHTML($this->renderSheets([$sheet]))
                ->setPaper('a4', 'portrait')->setOption('isPhpEnabled', true)->output();

            $base = Str::slug($sheet->roll_no ?: $sheet->id).'-'.Str::slug($sheet->subject_barcode ?: $sheet->packet_no ?: (string) $sheet->id);
            $name = $base.'.pdf';
            for ($i = 2; isset($usedNames[$name]); $i++) {
                $name = "{$base}-{$i}.pdf";
            }
            $usedNames[$name] = true;

            $zip->addFromString($name, $pdfBytes);
        }

        $zip->close();

        return response()->download($zipPath, 'answer_book_top_sheets.zip')->deleteFileAfterSend(true);
    }

    /**
     * @param  list<AnswerSheet>  $sheets
     */
    private function renderSheets(array $sheets): string
    {
        return view('reports.answer-book-top-sheet', [
            'siteTitle' => $this->branding->resolve()['site_title'],
            'logoDataUri' => $this->resolveLogoDataUri(),
            'sheets' => collect($sheets)->map(fn (AnswerSheet $sheet) => $this->sheetData($sheet))->all(),
        ])->render();
    }

    /**
     * Same reasoning/fallback chain as TeacherWiseEvaluationReportController's
     * own resolveLogoDataUri() — see that method's docblock.
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
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_details', 'user_id')->whereNull('deleted_at')],
        ]);
    }

    /**
     * @param  array{program_name: string, course_id: int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $query = AnswerSheet::query()
            ->join('question_answer_sheet_mappings as m', 'm.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->join('question_papers as qp', 'qp.id', '=', 'm.question_paper_id')
            ->whereNull('m.deleted_at')
            ->where('m.program_name', $filters['program_name'])
            ->where('m.course_id', $filters['course_id'])
            ->where('m.exam_term_id', $filters['exam_term_id'])
            ->where('m.exam_type_id', $filters['exam_type_id'])
            ->where('m.semester', $filters['semester'])
            ->where('qp.exam_year', $filters['exam_year']);

        if (! empty($filters['teacher_id'])) {
            $query->where('answer_sheets.teacher_id', $filters['teacher_id']);
        }

        return $query;
    }

    /**
     * @param  array{program_name: string, course_id: int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters): array
    {
        $rows = $this->baseQuery($filters)
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->select('answer_sheets.*', 'c.name as course_name', 'c.code as course_code')
            ->orderBy('answer_sheets.roll_no')
            ->get();

        return $rows->map(fn (AnswerSheet $row) => [
            'id' => $row->id,
            'roll_no' => $row->roll_no,
            'name' => $row->name,
            'registration_no' => $row->registration_no,
            'packet_no' => $row->packet_no,
            'script_code' => $row->subject_barcode ?: $row->barcode,
            'course_name' => $row->course_name ?? $row->subject_name,
            'course_code' => $row->course_code ?? $row->subject_code,
            'semester' => (int) $row->semester,
            'marks' => $row->marks !== null ? (float) $row->marks : null,
            'evaluated' => $row->marks !== null,
            'evaluated_at' => $row->evaluated_at ? Carbon::parse($row->evaluated_at)->format('d-m-Y h:i A') : null,
        ])->values()->all();
    }

    /**
     * @param  array{program_name: string, course_id: int, exam_term_id: int, exam_type_id: int, semester: int, exam_year: int, teacher_id: ?int}  $filters
     */
    private function evaluatedSheetsQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->whereNotNull('answer_sheets.marks')
            ->with(['mapping.course', 'mapping.examType', 'mapping.questionPaper'])
            ->select('answer_sheets.*')
            ->orderBy('answer_sheets.roll_no');
    }

    /**
     * The printed top sheet is deliberately anonymized — no roll/name/
     * registration/packet identifies the student at all, only the
     * physical script's own QR/barcode (see AnswerBookTopSheetReportView
     * .vue's own results list, which drops the same identifying columns
     * in favour of a QR Code one) — so only what the "top" identity block
     * and the question grid actually print is computed here.
     *
     * @return array<string, mixed>
     */
    private function sheetData(AnswerSheet $sheet): array
    {
        $sheet->loadMissing(['mapping.course', 'mapping.examType', 'mapping.questionPaper']);
        $mapping = $sheet->mapping;
        $paper = $mapping?->questionPaper;

        return [
            'script_code' => $sheet->subject_barcode ?: $sheet->barcode,
            'course_name' => $mapping?->course?->name ?? $sheet->subject_name,
            'course_code' => $mapping?->course?->code ?? $sheet->subject_code,
            'exam_type' => $mapping?->examType?->name,
            'full_marks' => $paper?->full_marks,
            'total_marks' => $sheet->marks !== null ? (float) $sheet->marks : null,
            'questions' => $paper ? $this->questionRows($paper->id, $sheet->draft_marks_breakdown ?? []) : [],
        ];
    }

    /**
     * @param  array<int, mixed>  $breakdown
     * @return list<array{label: string, max_marks: ?int, obtained: mixed}>
     */
    private function questionRows(int $questionPaperId, array $breakdown): array
    {
        $allNodes = $this->questionNodesCache[$questionPaperId] ??= QuestionPaperNode::query()
            ->where('question_paper_id', $questionPaperId)
            ->orderBy('sort_order')
            ->get();

        $rows = [];
        $this->flattenLeaves($allNodes, null, [], $rows);

        return array_map(fn (array $row) => [
            ...$row,
            'obtained' => array_key_exists($row['id'], $breakdown) ? $breakdown[$row['id']] : null,
        ], $rows);
    }

    /**
     * Walks the tree exactly like QuestionPaperResource::buildTree() does
     * (a flat, already sort_order-ordered collection, filtered by
     * parent_id at each level — filtering preserves the original relative
     * order, so no per-level re-sort is needed), but only collects leaf
     * nodes (the actual gradeable questions) instead of the whole tree.
     *
     * @param  Collection<int, QuestionPaperNode>  $allNodes
     * @param  list<string>  $ancestorLabels
     * @param  list<array{id: int, label: string, max_marks: ?int}>  $out
     */
    private function flattenLeaves(Collection $allNodes, ?int $parentId, array $ancestorLabels, array &$out): void
    {
        foreach ($allNodes->where('parent_id', $parentId) as $node) {
            $path = [...$ancestorLabels, $node->label];

            if ($node->mode === 'leaf') {
                $out[] = ['id' => $node->id, 'label' => $this->questionLabel($path), 'max_marks' => $node->marks];

                continue;
            }

            $this->flattenLeaves($allNodes, $node->id, $path, $out);
        }
    }

    /**
     * Top-level nodes are section/group containers (e.g. "Group A"), not
     * part of the printed question number — QuestionPaperStructureBuilder.vue
     * only lets a paper admin instruct/label at that depth for grouping —
     * so the group's own label is dropped and only the levels below it are
     * joined (e.g. "1" + "i" → "1.i"). Node labels are otherwise free text
     * a paper admin typed by hand (no enforced numbering scheme — see
     * QuestionPaperNode's own docblock), so a "Q" is only prepended when
     * the label doesn't already start with one, to match the reference
     * layout's "Q1.i"/"Q2" style without ever double-prefixing a label an
     * admin already wrote as "Q1" themselves.
     *
     * @param  list<string>  $path
     */
    private function questionLabel(array $path): string
    {
        $labels = count($path) > 1 ? array_slice($path, 1) : $path;
        $joined = implode('.', $labels);

        return Str::startsWith($joined, ['Q', 'q']) ? $joined : "Q{$joined}";
    }
}
