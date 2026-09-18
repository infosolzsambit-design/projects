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
use Illuminate\Support\Collection;
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
    /**
     * GET /answer-sheet-mappings — backs AnswerSheetsView.vue's own header
     * search box and Filter dropdown:
     *  - ?search=          → matches packet code, program name, course
     *    name/code, and exam term name
     *  - ?course_id=, ?exam_term_id=, ?semester=
     *  - ?status=uploaded|empty → whether the packet has any rows at all
     *    yet (see storeRows()) — matches the "Uploaded"/"Empty" badge this
     *    same view already renders per row.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $query = QuestionAnswerSheetMapping::with(['course', 'questionPaper', 'examTerm', 'examType', 'creator'])
            ->withCount(['answerSheets', 'answerSheets as pending_answer_sheet_count' => fn ($q) => $q->whereNull('teacher_id')]);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('packet_code', 'like', "%{$search}%")
                    ->orWhere('program_name', 'like', "%{$search}%")
                    ->orWhereHas('course', fn ($q2) => $q2->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('examTerm', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->integer('course_id'));
        }
        if ($request->filled('exam_term_id')) {
            $query->where('exam_term_id', $request->integer('exam_term_id'));
        }
        if ($request->filled('exam_type_id')) {
            $query->where('exam_type_id', $request->integer('exam_type_id'));
        }
        if ($request->filled('semester')) {
            $query->where('semester', $request->integer('semester'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'uploaded') {
                $query->whereHas('answerSheets');
            } elseif ($status === 'empty') {
                $query->whereDoesntHave('answerSheets');
            }
        }

        $mappings = $query->latest('id')->paginate($perPage);

        return $this->paginated($mappings, 'Answer sheet packets fetched successfully.', QuestionAnswerSheetMappingResource::collection($mappings));
    }

    public function show(QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        return $this->success(
            new QuestionAnswerSheetMappingResource($questionAnswerSheetMapping->loadCount(['answerSheets', 'answerSheets as pending_answer_sheet_count' => fn ($q) => $q->whereNull('teacher_id')])->load(['course', 'questionPaper', 'examTerm', 'examType'])),
            'Answer sheet packet fetched successfully.',
        );
    }

    /**
     * GET /answer-sheet-mappings/{mapping}/rows — the packet's own answer
     * sheets, paginated (see AnswerSheetsView.vue's "View Answer Sheets"
     * modal, AnswerSheetRowsModal.vue). A dedicated, paginated endpoint
     * rather than embedding these in show() — a packet can hold thousands
     * of rows, and a modal only ever needs one page of them at a time.
     *
     * ?search= matches Roll No, Name, or Subject Barcode — the same three
     * columns the modal's own search box filters against.
     */
    public function rows(Request $request, QuestionAnswerSheetMapping $questionAnswerSheetMapping): JsonResponse
    {
        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        // teacher.teacherDetail — AnswerSheetRowsModal.vue's own "Evaluation
        // By" column shows the assigned teacher's emp code alongside their
        // name (see AnswerSheetResource's own teacher_emp_code field).
        $query = $questionAnswerSheetMapping->answerSheets()->with('teacher.teacherDetail');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('roll_no', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('subject_barcode', 'like', "%{$search}%");
            });
        }

        $rows = $query->orderBy('id')->paginate($perPage);

        return $this->paginated($rows, 'Answer sheets fetched successfully.', AnswerSheetResource::collection($rows));
    }

    /**
     * DELETE /answer-sheet-mappings/{mapping}/rows/{answer_sheet} — removes
     * one wrongly-uploaded row from a packet (AnswerSheetRowsModal.vue's
     * own row action) without touching the rest of the packet. A plain
     * soft delete (see AnswerSheet's own SoftDeletes) — reversible, and the
     * row simply drops out of every query that already respects deleted_at
     * (this modal's own listing, a teacher's pending queue, ...) — nothing
     * special has to happen for it to "disappear". The row's own PDF is
     * left on disk, same as a whole packet's soft delete does (see
     * QuestionAnswerSheetMapping::booted()) — only a *force* delete ever
     * actually removes files.
     */
    public function deleteRow(QuestionAnswerSheetMapping $questionAnswerSheetMapping, AnswerSheet $answerSheet): JsonResponse
    {
        if ($answerSheet->question_answer_sheet_mapping_id !== $questionAnswerSheetMapping->id) {
            return $this->notFound('No answer sheet found in this packet.');
        }

        $answerSheet->delete();

        return $this->success(null, 'Answer sheet deleted successfully.');
    }

    /**
     * POST /answer-sheet-mappings/check-duplicate-barcodes — the same
     * "QR code already used for this exact packet combination" check
     * StoreAnswerSheetRowsRequest itself authoritatively enforces (see that
     * class's withValidator()), exposed standalone so AnswerSheetUploadView
     * .vue's Check step can surface it *before* Submit — at that point no
     * QuestionAnswerSheetMapping row exists yet at all (phase 1's store()
     * hasn't run), so there's nothing to scope a per-mapping check to; this
     * takes the packet's own identifying fields directly instead.
     *
     * "Combination" here is every field that together identifies one exam
     * sitting (program_name, packet_code, question_paper_id, course_id,
     * exam_term_id, exam_type_id, semester) — the same seven fields Packet
     * Details asks for. The same subject_barcode can absolutely repeat
     * *across* a different combination (a different question paper, say);
     * it's only a duplicate within the same one.
     */
    public function checkDuplicateBarcodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_name' => ['required', 'string'],
            'packet_code' => ['required', 'string'],
            'question_paper_id' => ['required', 'integer'],
            'course_id' => ['required', 'integer'],
            'exam_term_id' => ['required', 'integer'],
            'exam_type_id' => ['required', 'integer'],
            'semester' => ['required', 'integer'],
            'barcodes' => ['required', 'array', 'min:1'],
            'barcodes.*' => ['string'],
        ]);

        $duplicates = AnswerSheet::withTrashed()
            ->whereIn('question_answer_sheet_mapping_id', $this->siblingMappingIds($data))
            ->whereIn('subject_barcode', $data['barcodes'])
            ->pluck('subject_barcode')
            ->unique()
            ->values();

        return $this->success(['duplicate_barcodes' => $duplicates], 'Checked successfully.');
    }

    /**
     * Every (non-deleted) mapping sharing one packet's exact combination of
     * program_name/packet_code/question_paper_id/course_id/exam_term_id/
     * exam_type_id/semester — deliberately plural, since nothing stops the
     * same combination being uploaded as more than one packet (see this
     * controller's own store()), and a QR code must be unique across all
     * of them together, not just within whichever single mapping id a
     * caller happens to already have. Shared by checkDuplicateBarcodes()
     * above and StoreAnswerSheetRowsRequest's own authoritative check.
     *
     * @param  array{program_name:string,packet_code:string,question_paper_id:int,course_id:int,exam_term_id:int,exam_type_id:int,semester:int}  $fields
     */
    public static function siblingMappingIds(array $fields): Collection
    {
        return QuestionAnswerSheetMapping::where('program_name', $fields['program_name'])
            ->where('packet_code', $fields['packet_code'])
            ->where('question_paper_id', $fields['question_paper_id'])
            ->where('course_id', $fields['course_id'])
            ->where('exam_term_id', $fields['exam_term_id'])
            ->where('exam_type_id', $fields['exam_type_id'])
            ->where('semester', $fields['semester'])
            ->pluck('id');
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
