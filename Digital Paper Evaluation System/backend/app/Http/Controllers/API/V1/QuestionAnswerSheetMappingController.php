<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerSheet\StoreAnswerSheetRowsRequest;
use App\Http\Requests\AnswerSheet\StoreQuestionAnswerSheetMappingRequest;
use App\Http\Resources\AnswerSheetResource;
use App\Http\Resources\QuestionAnswerSheetMappingResource;
use App\Models\AnswerSheet;
use App\Models\QuestionAnswerSheetMapping;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Backs AnswerSheetUploadView.vue's Submit action — a two-phase upload
 * (store() creates the empty packet, storeRows() streams its rows/PDFs in
 * afterward a batch at a time) rather than one request carrying
 * everything, since a real submission runs into the thousands of rows/PDFs
 * — see StoreAnswerSheetRowsRequest's own docblock for why.
 */
class QuestionAnswerSheetMappingController extends Controller
{
    use ApiResponse;

    /**
     * GET /answer-sheet-mappings — plain paginated listing (no ?status=all/
     * search/sort branches yet, unlike the "Laravel index() endpoint
     * pattern" this backend otherwise follows everywhere — see
     * AnswerSheetsView.vue). ->withCount() instead of eager-loading the
     * actual rows — a packet can hold thousands of them (see
     * StoreAnswerSheetRowsRequest's own docblock), and the list only ever
     * needs the count, not every row.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $mappings = QuestionAnswerSheetMapping::with(['course', 'questionPaper', 'examTerm', 'creator'])
            ->withCount(['answerSheets', 'answerSheets as pending_answer_sheet_count' => fn ($q) => $q->whereNull('teacher_id')])
            ->latest('id')
            ->paginate($perPage);

        return $this->paginated($mappings, 'Answer sheet packets fetched successfully.', QuestionAnswerSheetMappingResource::collection($mappings));
    }

    public function show(QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        return $this->success(
            new QuestionAnswerSheetMappingResource($questionAnswerSheetMapping->loadCount(['answerSheets', 'answerSheets as pending_answer_sheet_count' => fn ($q) => $q->whereNull('teacher_id')])->load(['course', 'questionPaper', 'examTerm'])),
            'Answer sheet packet fetched successfully.',
        );
    }

    /**
     * GET /answer-sheet-mappings/{mapping}/rows — the packet's own answer
     * sheets, paginated (see AnswerSheetsView.vue's "View Answer Sheets"
     * modal). A dedicated, paginated endpoint rather than embedding these
     * in show() — a packet can hold thousands of rows, and a modal only
     * ever needs one page of them at a time.
     */
    public function rows(Request $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $rows = $questionAnswerSheetMapping->answerSheets()->with('teacher')->orderBy('id')->paginate($perPage);

        return $this->paginated($rows, 'Answer sheets fetched successfully.', AnswerSheetResource::collection($rows));
    }

    /**
     * POST /answer-sheet-mappings — phase 1: just the packet itself, no
     * rows/PDFs. The frontend calls storeRows() below repeatedly right
     * after this to actually fill it in.
     */
    public function store(StoreQuestionAnswerSheetMappingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $mapping = QuestionAnswerSheetMapping::create($data); // $data already carries every validated field, including exam_term_id

        return $this->success(
            new QuestionAnswerSheetMappingResource($mapping->load(['course', 'examTerm'])),
            'Answer sheet packet created — ready for rows.',
            201,
        );
    }

    /**
     * POST /answer-sheet-mappings/{mapping}/rows — phase 2, called once per
     * batch. Each PDF is stored only after this chunk's own validation
     * passes, keyed by its row's own subject_barcode.
     */
    public function storeRows(StoreAnswerSheetRowsRequest $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $data = $request->validated();
        $pdfs = $request->file('pdfs', []);

        // A blank CSV cell arrives here as '' (the frontend just trims
        // whatever the sheet had, empty or not), not null — fine for a
        // nullable *string* column, but MySQL rejects '' outright for
        // marks/semester's numeric columns. Normalized once per row below
        // rather than trusting each field's own blank convention.
        $nullIfBlank = fn ($value) => $value === '' || $value === null ? null : $value;

        $created = DB::transaction(function () use ($data, $pdfs, $questionAnswerSheetMapping, $nullIfBlank) {
            $rows = [];

            foreach ($data['rows'] as $row) {
                $barcode = $row['subject_barcode'];
                $pdfName = null;
                $pdfPath = null;

                if (isset($pdfs[$barcode])) {
                    // Plain store() (not storeAs()) — same as
                    // QuestionPaper::store()'s own pdf_path — lets Laravel
                    // generate the filename itself, guaranteed unique,
                    // rather than naming the file after the barcode (an
                    // exam center could plausibly reuse a barcode value
                    // across different packets, and letting the original
                    // uploaded filename through at all would let one center
                    // silently overwrite another's "scan1.pdf").
                    $stored = $pdfs[$barcode]->store("answer-sheets/{$questionAnswerSheetMapping->id}", 'public');
                    $pdfName = basename($stored);
                    $pdfPath = '/storage/'.$stored;
                }

                $rows[] = AnswerSheet::create([
                    'question_answer_sheet_mapping_id' => $questionAnswerSheetMapping->id,
                    'branch_code' => $nullIfBlank($row['branch_code'] ?? null),
                    'branch_name' => $nullIfBlank($row['branch_name'] ?? null),
                    'subject_code' => $nullIfBlank($row['subject_code'] ?? null),
                    'subject_name' => $nullIfBlank($row['subject_name'] ?? null),
                    'semester' => $nullIfBlank($row['semester'] ?? null),
                    'subject_barcode' => $barcode,
                    'fi_code' => $nullIfBlank($row['fi_code'] ?? null),
                    'roll_no' => $row['roll_no'],
                    'name' => $nullIfBlank($row['name'] ?? null),
                    'registration_no' => $nullIfBlank($row['registration_no'] ?? null),
                    'absent' => $row['absent'] ?? false,
                    'locked_time' => $nullIfBlank($row['locked_time'] ?? null),
                    'packet_no' => $nullIfBlank($row['packet_no'] ?? null),
                    'barcode' => $nullIfBlank($row['barcode'] ?? null),
                    'marks' => $nullIfBlank($row['marks'] ?? null),
                    'top_sheet' => $nullIfBlank($row['top_sheet'] ?? null),
                    'pdf_name' => $pdfName,
                    'pdf_path' => $pdfPath,
                ]);
            }

            return $rows;
        });

        return $this->success([
            'created_count' => count($created),
            'total_in_mapping' => $questionAnswerSheetMapping->answerSheets()->count(),
        ], count($created).' row(s) saved.', 201);
    }

    public function destroy(QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $questionAnswerSheetMapping->delete();

        return $this->success(null, 'Answer sheet packet deleted successfully.');
    }
}
