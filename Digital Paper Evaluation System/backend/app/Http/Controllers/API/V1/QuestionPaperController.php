<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionPaper\StoreQuestionPaperRequest;
use App\Http\Requests\QuestionPaper\UpdateQuestionPaperRequest;
use App\Http\Resources\QuestionPaperResource;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * exam_year/semester/status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = QuestionPaper::with(['course', 'examTerm'])->latest('id');

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

        if ($request->filled('exam_year')) {
            $query->where('exam_year', $request->integer('exam_year'));
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->integer('semester'));
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
        $questionPaper->delete();

        $this->auditLog->log(
            event: 'question-paper-deleted',
            module: 'Question Papers',
            description: 'Question paper deleted.',
            auditable: $questionPaper,
        );

        return $this->success(null, 'Question paper deleted successfully.');
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
