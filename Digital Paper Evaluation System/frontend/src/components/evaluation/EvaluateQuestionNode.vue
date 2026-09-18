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
// 'choose' gets a "Choose N of M" badge (see availableSlotCount() — the
// same override-aware total the structure builder itself shows, so this
// never disagrees with what was actually set up) instead of "all"'s plain
// progress count, since it's alternatives the student picked one of, not
// parts to answer all of. Each alternative still gets its own input here
// (not one shared input for the whole choose group) so the teacher can
// try/compare more than one before settling — EvaluatePaperView.vue's own
// total-marks calculation is what actually only counts the *best* of a
// choose group's alternatives once, not the sum of all of them (see its
// computeNodeAwarded()).
//
// Once the teacher puts anything into one alternative, every part of it
// becomes required to finish it (EvaluatePaperView.vue's own
// collectMarksErrors() is what actually enforces this), while a *different*
// alternative that's still fully untouched stays optional once enough
// others already cover the group's own choose_count — but deliberately
// with NO visual "locked"/"not required" treatment here: every alternative
// stays fully editable the whole time (a teacher can still try/compare, or
// change their mind and switch which one they answered), and only a field
// actually flagged bad by Complete (errorNodeIds) ever gets styled
// differently. 'leaf' nodes are the only ones with a real input.
import { availableSlotCount } from '../../utils/questionPaperNode'

const props = defineProps({
  node: { type: Object, required: true },
  marksByNode: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  // Leaf node ids EvaluatePaperView.vue's completeEvaluation() flagged as
  // missing — reddens just that input, not the whole group, so a "choose
  // 1 of 2" only highlights whichever alternative(s) are still needed.
  errorNodeIds: { type: Set, default: () => new Set() },
  // Marks/annotations are frozen once the evaluation window's time is up
  // (see EvaluatePaperView.vue's isOvertime) — Complete is still clickable,
  // this just stops any further editing.
  locked: { type: Boolean, default: false },
})

function hasValue(nodeId) {
  const v = props.marksByNode[nodeId]
  return v !== undefined && v !== null && v !== ''
}

// Simple "X / Y answered" progress for an 'all' branch — not shown on
// 'choose' nodes (their own "Choose N of M" badge already says what's
// needed; a raw leaf count across every alternative would double-count
// options nobody has to fill in). Purely a navigation aid on a long list
// like a 10-part question — never affects validation.
function countLeaves(node) {
  if (node.mode === 'leaf') return 1
  return (node.children || []).reduce((sum, child) => sum + countLeaves(child), 0)
}
function countFilledLeaves(node) {
  if (node.mode === 'leaf') return hasValue(node.id) ? 1 : 0
  return (node.children || []).reduce((sum, child) => sum + countFilledLeaves(child), 0)
}

// Corrects the value the moment it goes out of range — not just a
// max="…" attribute (native <input type="number"> shows an invalid state
// for a value over max, but never actually stops one being typed or
// scrolled/spun in), so a teacher genuinely cannot leave more marks in a
// box than that question is worth. Runs after v-model's own update (see
// the input below), so it corrects what was just typed, not what's about
// to be.
function clampMarks(node) {
  const raw = props.marksByNode[node.id]
  if (raw === '' || raw === null || raw === undefined) return
  const value = Number(raw)
  if (Number.isNaN(value)) return
  const max = node.marks ?? 0
  const clamped = Math.min(Math.max(value, 0), max)
  if (clamped !== value) props.marksByNode[node.id] = clamped
}
</script>

<template>
  <div v-if="node.mode !== 'leaf'" :class="depth === 0 ? 'mb-3' : 'mb-2'">
    <div
      class="flex items-center gap-1.5 px-2.5 rounded-lg mb-1.5"
      :class="depth === 0
        ? 'py-2 bg-brand-blue/10 text-brand-blue text-[13px] font-bold'
        : 'py-1.5 bg-page-bg text-gray-800 text-[12px] font-semibold'"
    >
      <span class="truncate">{{ node.label || 'Group' }}</span>
      <span v-if="node.mode === 'choose'" class="shrink-0 text-[10px] font-bold text-brand-blue bg-white border border-brand-blue/25 rounded-full px-1.5 py-0.5">
        Choose {{ node.choose_count }} of {{ availableSlotCount(node) }}
      </span>
      <span v-else-if="node.mode === 'all'" class="ml-auto shrink-0 text-[10.5px] font-semibold" :class="countFilledLeaves(node) >= countLeaves(node) ? 'text-success' : 'text-muted'">
        {{ countFilledLeaves(node) }}/{{ countLeaves(node) }}
      </span>
    </div>
    <p v-if="node.instruction" class="text-[11px] text-muted mb-1.5 px-0.5">{{ node.instruction }}</p>
    <div class="pl-2.5 border-l-2 space-y-0.5" :class="node.mode === 'choose' ? 'border-brand-blue/25' : 'border-input-border'">
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

  <div v-else class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-page-bg/60 transition-colors">
    <span class="min-w-0 flex-1 text-[12px] font-semibold text-brand-blue truncate">{{ node.label }}</span>
    <input
      v-model="marksByNode[node.id]"
      type="number"
      inputmode="decimal"
      :min="0"
      :max="node.marks || 0"
      :disabled="locked"
      class="w-16 h-8 px-2 rounded-lg bg-input-bg text-center text-[12.5px] font-semibold text-gray-800 outline-none border transition disabled:opacity-60 disabled:cursor-not-allowed"
      :class="errorNodeIds.has(node.id) ? 'border-brand ring-1 ring-brand/40' : 'border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15'"
      :title="errorNodeIds.has(node.id) ? `This question still needs marks (0–${node.marks ?? 0}).` : ''"
      @input="clampMarks(node)"
      @blur="clampMarks(node)"
    />
    <span class="text-[11px] text-muted w-9 shrink-0 text-right">/ {{ node.marks ?? 0 }}</span>
  </div>
</template>
