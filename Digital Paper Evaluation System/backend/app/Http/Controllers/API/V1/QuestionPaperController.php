<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionPaper\StoreQuestionPaperRequest;
use App\Http\Requests\QuestionPaper\UpdateQuestionPaperPdfRequest;
use App\Http\Requests\QuestionPaper\UpdateQuestionPaperRequest;
use App\Http\Resources\QuestionPaperResource;
use App\Models\AnswerSheet;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Setup flow (see QuestionPapersView.vue — the list page has no "Add"
 * button, only "Setup Question Paper"):
 *  - store() — exam_year/course/semester, the PDF, and the full structure
 *    tree arrive together in one request (built client-side across two UI
 *    steps — see QuestionPaperStructureBuilder.vue — but nothing is
 *    written to the database until this one call, so an abandoned setup
 *    never leaves a stray row behind). Created "ready" directly; "draft"
 *    stays a valid status value but nothing produces it any more.
 *  - update() — replaces an *existing* saved paper's structure later (the
 *    "Edit Setup" list action).
 *  - updatePdf() — swaps in a new scan for the file only, leaving the
 *    structure/marks untouched (the "Replace PDF" list action) — see that
 *    method's own docblock for why this stays open even once update() and
 *    destroy() are locked by evaluation_started.
 * No permission gating yet, same as every other master-data controller.
 */
class QuestionPaperController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * GET /question-papers — paginated, searchable across every column the
     * list actually shows (exam year, course name/code, semester, full
     * marks, status — see QuestionPapersView.vue's table), filterable by
     * exam_year/semester/status/course_id/exam_term_id — the last two
     * exist purely so QuestionPaperSetupView.vue can pre-check for a
     * duplicate (exam_year, course_id, exam_term_id, semester) combination
     * right when "Setup" is clicked, before building out an entire
     * structure only to have it rejected at the very end (see
     * StoreQuestionPaperRequest's own authoritative duplicate check, which
     * this is purely a faster-feedback mirror of).
     */
    public function index(Request $request): JsonResponse
    {
        $query = QuestionPaper::with(['course', 'examTerm'])
            ->addSelect(['evaluation_started' => $this->evaluationStartedSubquery()])
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->whereHas('course', function ($cq) use ($search) {
                    $cq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
                })
                    ->orWhere('exam_year', 'like', "%{$search}%")
                    ->orWhere('semester', 'like', "%{$search}%")
                    ->orWhere('full_marks', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        // Deliberately *not* defaulted/forced for a non-super-admin the
        // way HasExamYearScope's other users are (see that trait's own
        // docblock) — this same endpoint is also how AssignTeacherView.vue
        // bulk-loads every "ready" paper across every year for its own
        // client-side exam-year search (see that view's own
        // loadBackingData()), and forcing this one to the current year by
        // default would silently break that unrelated page's ability to
        // assign against a past year's papers. QuestionPapersView.vue (the
        // page this *is* meant to scope) instead always sends exam_year
        // itself for a non-super-admin — see that view's own fetchPapers().
        if ($request->filled('exam_year')) {
            $query->where('exam_year', $request->integer('exam_year'));
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->integer('semester'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->integer('course_id'));
        }

        if ($request->filled('exam_term_id')) {
            $query->where('exam_term_id', $request->integer('exam_term_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $papers = $query->paginate($perPage);

        return $this->paginated($papers, 'Question papers fetched successfully.', QuestionPaperResource::collection($papers));
    }

    /**
     * POST /question-papers — the whole paper, PDF, and structure tree
     * arrive together and are created in one transaction. Validation (see
     * StoreQuestionPaperRequest) runs before this method is ever entered,
     * so an invalid submission never opens a transaction or touches
     * storage at all.
     */
    public function store(StoreQuestionPaperRequest $request): JsonResponse
    {
        $data = $request->validated();

        $pdfPath = '/storage/'.$request->file('pdf')->store('question-papers', 'public');

        $paper = DB::transaction(function () use ($data, $pdfPath) {
            $paper = QuestionPaper::create([
                'exam_year' => $data['exam_year'],
                'course_id' => $data['course_id'],
                'semester' => $data['semester'],
                'exam_term_id' => $data['exam_term_id'],
                'pdf_path' => $pdfPath,
                'full_marks' => $data['full_marks'] ?? null,
                'time_allotted' => $data['time_allotted'] ?? null,
                'status' => 'ready',
            ]);

            $this->createNodes($paper, $data['groups'], null);

            return $paper;
        });

        $this->auditLog->log(
            event: 'question-paper-uploaded',
            module: 'Question Papers',
            description: "Question paper created for exam year {$paper->exam_year}, semester {$paper->semester}: ".count($data['groups']).' group(s).',
            auditable: $paper,
        );

        return $this->success(
            new QuestionPaperResource($paper->load(['course', 'examTerm', 'groups'])),
            'Question paper created successfully.',
            201,
        );
    }

    public function show(QuestionPaper $questionPaper): JsonResponse
    {
        $questionPaper->setAttribute('evaluation_started', $this->hasStartedEvaluation($questionPaper));

        return $this->success(
            new QuestionPaperResource($questionPaper->load(['course', 'examTerm', 'groups'])),
            'Question paper retrieved successfully.',
        );
    }

    /**
     * PUT /question-papers/{id} — edits an *existing* saved paper's
     * structure (the "Edit Setup" list action). Replaces the entire
     * structure tree in one transaction (delete-then-recreate — see
     * UpdateQuestionPaperRequest's docblock for why that's simpler than
     * diffing a nested tree).
     */
    public function update(UpdateQuestionPaperRequest $request, QuestionPaper $questionPaper): JsonResponse
    {
        if ($this->hasStartedEvaluation($questionPaper)) {
            return $this->error(
                'This question paper can no longer be edited — a teacher has already started evaluating an answer sheet mapped to it.',
                422,
            );
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $questionPaper) {
            $questionPaper->update([
                'exam_year' => $data['exam_year'],
                'course_id' => $data['course_id'],
                'semester' => $data['semester'],
                'exam_term_id' => $data['exam_term_id'],
                'full_marks' => $data['full_marks'] ?? null,
                'time_allotted' => $data['time_allotted'] ?? null,
                'status' => 'ready',
            ]);

            QuestionPaperNode::where('question_paper_id', $questionPaper->id)->delete();

            $this->createNodes($questionPaper, $data['groups'], null);
        });

        $this->auditLog->log(
            event: 'question-paper-setup-completed',
            module: 'Question Papers',
            description: 'Question paper structure saved: '.count($data['groups']).' group(s).',
            auditable: $questionPaper,
        );

        return $this->success(
            new QuestionPaperResource($questionPaper->fresh()->load(['course', 'examTerm', 'groups'])),
            'Question paper setup saved successfully.',
        );
    }

    /**
     * POST /question-papers/{id}/pdf — swaps in a new scan for an
     * already-set-up paper without touching its structure or marks at all
     * (see UpdateQuestionPaperPdfRequest's own docblock). Deliberately
     * available even once hasStartedEvaluation() has otherwise locked the
     * paper against update()/destroy(): a plain file swap can never orphan
     * draft_marks_breakdown's node-id references the way a structure edit
     * would, and a teacher partway through evaluating against a bad scan
     * is exactly who benefits most from a corrected one being dropped in
     * mid-evaluation.
     */
    public function updatePdf(UpdateQuestionPaperPdfRequest $request, QuestionPaper $questionPaper): JsonResponse
    {
        $oldPath = $questionPaper->pdf_path;
        $newPath = '/storage/'.$request->file('pdf')->store('question-papers', 'public');

        $questionPaper->update(['pdf_path' => $newPath]);

        if ($oldPath && str_starts_with($oldPath, '/storage/')) {
            Storage::disk('public')->delete(substr($oldPath, strlen('/storage/')));
        }

        $this->auditLog->log(
            event: 'question-paper-pdf-replaced',
            module: 'Question Papers',
            description: 'Question paper PDF file replaced.',
            auditable: $questionPaper,
        );

        return $this->success(
            new QuestionPaperResource($questionPaper->fresh()->load(['course', 'examTerm'])),
            'Question paper PDF replaced successfully.',
        );
    }

    /**
     * Recursively recreates a submitted structure tree under $parentId (null
     * for the paper's own top-level "groups") — one call per depth level,
     * same shape all the way down since a QuestionPaperNode's children are
     * just more QuestionPaperNodes.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function createNodes(QuestionPaper $questionPaper, array $nodes, ?int $parentId): void
    {
        foreach ($nodes as $index => $nodeData) {
            $node = QuestionPaperNode::create([
                'question_paper_id' => $questionPaper->id,
                'parent_id' => $parentId,
                'label' => $nodeData['label'],
                'instruction' => $nodeData['instruction'] ?? null,
                'mode' => $nodeData['mode'],
                'choose_count' => $nodeData['mode'] === 'choose' ? $nodeData['choose_count'] : null,
                // Only meaningful on a 'choose' node — see that column's own
                // migration docblock. '' (the frontend's own "not set"
                // value) and 0 both mean "keep auto-computing it", same as
                // null.
                'slots_override' => $nodeData['mode'] === 'choose' && ! empty($nodeData['slots_override']) ? (int) $nodeData['slots_override'] : null,
                'marks' => $nodeData['mode'] === 'leaf' ? $nodeData['marks'] : null,
                'bloom_level' => $nodeData['mode'] === 'leaf' ? (($nodeData['bloom_level'] ?? null) ?: null) : null,
                'co' => $nodeData['mode'] === 'leaf' ? (($nodeData['co'] ?? null) ?: null) : null,
                'sort_order' => $index,
            ]);

            if ($nodeData['mode'] !== 'leaf' && ! empty($nodeData['children'])) {
                $this->createNodes($questionPaper, $nodeData['children'], $node->id);
            }
        }
    }

    public function destroy(QuestionPaper $questionPaper): JsonResponse
    {
        if ($this->hasStartedEvaluation($questionPaper)) {
            return $this->error(
                'This question paper can no longer be deleted — a teacher has already started evaluating an answer sheet mapped to it.',
                422,
            );
        }

        $questionPaper->delete();

        $this->auditLog->log(
            event: 'question-paper-deleted',
            module: 'Question Papers',
            description: 'Question paper deleted.',
            auditable: $questionPaper,
        );

        return $this->success(null, 'Question paper deleted successfully.');
    }

    /**
     * True once any answer sheet mapped to this paper has real evaluation
     * work on it — a non-zero consumed_time (EvaluatePaperView.vue's live
     * timer, ticking as soon as a teacher opens it), a saved draft_marks,
     * or a completed `marks` — see the migration that added
     * consumed_time/draft_marks for what each of those means. Once true,
     * the structure can no longer be edited: update() deletes and
     * recreates every QuestionPaperNode, which would orphan
     * draft_marks_breakdown's node-id references for whoever's already
     * mid-evaluation.
     */
    private function hasStartedEvaluation(QuestionPaper $questionPaper): bool
    {
        return AnswerSheet::whereHas('mapping', fn ($q) => $q->where('question_paper_id', $questionPaper->id))
            ->where(function ($q) {
                $q->where('consumed_time', '>', 0)
                    ->orWhereNotNull('marks')
                    ->orWhereNotNull('draft_marks');
            })
            ->exists();
    }

    /**
     * Same check as hasStartedEvaluation(), as a correlated subquery
     * instead — used by index() so listing many papers costs one query
     * total instead of one per row.
     */
    private function evaluationStartedSubquery(): Builder
    {
        return AnswerSheet::query()
            ->join('question_answer_sheet_mappings', 'question_answer_sheet_mappings.id', '=', 'answer_sheets.question_answer_sheet_mapping_id')
            ->whereColumn('question_answer_sheet_mappings.question_paper_id', 'question_papers.id')
            ->whereNull('question_answer_sheet_mappings.deleted_at')
            ->whereNull('answer_sheets.deleted_at')
            ->where(function ($q) {
                $q->where('answer_sheets.consumed_time', '>', 0)
                    ->orWhereNotNull('answer_sheets.marks')
                    ->orWhereNotNull('answer_sheets.draft_marks');
            })
            ->selectRaw('1')
            ->limit(1);
    }

    public function restore(QuestionPaper $questionPaper): JsonResponse
    {
        $questionPaper->restore();

        $this->auditLog->log(
            event: 'question-paper-restored',
            module: 'Question Papers',
            description: 'Question paper restored.',
            auditable: $questionPaper,
        );

        return $this->success(new QuestionPaperResource($questionPaper), 'Question paper restored successfully.');
    }
}
