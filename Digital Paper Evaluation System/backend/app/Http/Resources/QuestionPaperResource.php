<?php

namespace App\Http\Resources;

use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin QuestionPaper
 */
class QuestionPaperResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_year' => $this->exam_year,
            'course_id' => $this->course_id,
            'course_name' => $this->whenLoaded('course', fn () => $this->course?->name),
            'course_code' => $this->whenLoaded('course', fn () => $this->course?->code),
            'semester' => $this->semester,
            'exam_term_id' => $this->exam_term_id,
            'exam_term_name' => $this->whenLoaded('examTerm', fn () => $this->examTerm?->name),
            'pdf_url' => $this->pdf_path,
            'full_marks' => $this->full_marks,
            'time_allotted' => $this->time_allotted,
            'status' => $this->status,
            // Set by QuestionPaperController — a subquery in index(), a
            // direct check in show() (see hasStartedEvaluation()). True
            // once any mapped answer sheet has any real evaluation work on
            // it (see that method for exactly what counts) — the
            // structure is locked at that point since editing it would
            // silently orphan draft_marks_breakdown's node-id references.
            // The PDF file itself stays swappable even then — see
            // QuestionPaperController::updatePdf().
            'evaluation_started' => (bool) $this->evaluation_started,
            // whenLoaded('groups') just gates whether the structure tree is
            // included at all (index() doesn't load it, show()/update() do)
            // — the tree itself is built here from one flat query across
            // every depth, not from the eager-loaded relation, since a
            // chained with('children.children...') can't express unbounded
            // depth. See QuestionPaperNode's docblock for what a node is.
            'groups' => $this->whenLoaded('groups', fn () => $this->buildTree(
                QuestionPaperNode::where('question_paper_id', $this->id)->orderBy('sort_order')->get(),
                null,
            )),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_by' => $this->when($this->trashed(), $this->deleted_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),
        ];
    }

    /**
     * @param  Collection<int, QuestionPaperNode>  $allNodes
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(Collection $allNodes, ?int $parentId): array
    {
        return $allNodes
            ->where('parent_id', $parentId)
            ->map(fn (QuestionPaperNode $node) => [
                'id' => $node->id,
                'label' => $node->label,
                'instruction' => $node->instruction,
                'mode' => $node->mode,
                'choose_count' => $node->choose_count,
                'slots_override' => $node->slots_override,
                'marks' => $node->marks,
                'bloom_level' => $node->bloom_level,
                'co' => $node->co,
                'sort_order' => $node->sort_order,
                'children' => $this->buildTree($allNodes, $node->id),
            ])
            ->values()
            ->all();
    }
}
