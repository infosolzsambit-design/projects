<script setup>
// Recursive per-question marks-entry row for EvaluatePaperView.vue's side
// panel — one node of the *real* question-paper structure tree (see
// QuestionPaperNode on the backend / QuestionPaperResource::buildTree()),
// not a hardcoded scheme. A `<script setup>` SFC can reference itself by
// filename for recursion in Vue 3.3+ — no manual registration needed, same
// pattern as QuestionNodeEditor.vue's own recursion in the structure
// builder.
//
// 'all' and 'choose' both render as a group wrapping their own children —
// 'choose' just gets an "OR" badge, since it's alternatives the student
// picked one of, not parts to answer all of. Each one gets its own input
// here (not one shared input for the whole choose group) so the teacher
// can try/compare more than one before settling — EvaluatePaperView.vue's
// own total-marks calculation is what actually only counts the *best* of
// a choose group's alternatives once, not the sum of all of them (see its
// computeNodeAwarded()). 'leaf' nodes are the only ones with a real input.
const props = defineProps({
  node: { type: Object, required: true },
  marksByNode: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  // Leaf node ids EvaluatePaperView.vue's completeEvaluation() flagged as
  // missing — reddens just that input, not the whole group, so a "choose
  // 1 of 2" only highlights whichever alternative(s) are still empty.
  errorNodeIds: { type: Set, default: () => new Set() },
  // Marks/annotations are frozen once the evaluation window's time is up
  // (see EvaluatePaperView.vue's isOvertime) — Complete is still clickable,
  // this just stops any further editing.
  locked: { type: Boolean, default: false },
})
</script>

<template>
  <div v-if="node.mode !== 'leaf'" class="mb-2.5">
    <div class="flex items-center gap-1.5 bg-page-bg text-[12px] font-semibold text-gray-800 px-2.5 py-1.5 rounded-lg mb-1.5">
      <span>{{ node.label || 'Group' }}</span>
      <span v-if="node.mode === 'choose'" class="shrink-0 text-[10px] font-bold text-brand-blue bg-brand-blue/10 rounded-full px-1.5 py-0.5">OR</span>
    </div>
    <p v-if="node.instruction" class="text-[11px] text-muted mb-1.5 px-0.5">{{ node.instruction }}</p>
    <div class="pl-2.5 border-l-2 border-input-border space-y-0.5">
      <EvaluateQuestionNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :marks-by-node="marksByNode"
        :depth="depth + 1"
        :error-node-ids="errorNodeIds"
        :locked="locked"
      />
    </div>
  </div>

  <div v-else class="flex items-center gap-2 py-1 px-1">
    <span class="min-w-0 flex-1 text-[12px] font-semibold text-brand-blue truncate">{{ node.label }}</span>
    <input
      v-model="marksByNode[node.id]"
      type="number"
      :min="0"
      :max="node.marks || 0"
      :disabled="locked"
      class="w-14 h-7 px-1.5 rounded-md bg-input-bg text-center text-[12px] text-gray-800 outline-none border transition disabled:opacity-60 disabled:cursor-not-allowed"
      :class="errorNodeIds.has(node.id) ? 'border-brand ring-1 ring-brand/40' : 'border-input-border focus:border-brand-blue'"
      :title="errorNodeIds.has(node.id) ? 'This question still needs marks.' : ''"
    />
    <span class="text-[11px] text-muted w-10 shrink-0">/ {{ node.marks ?? 0 }}</span>
  </div>
</template>
