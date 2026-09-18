<script setup>
// Read-only counterpart to QuestionNodeEditor.vue — same recursive
// {label, instruction, mode, choose_count, marks, bloom_level, co,
// children} shape, just displayed rather than edited. Used by
// QuestionPaperViewView.vue's "View" action in the question papers list,
// for looking at a finished setup without any risk of accidentally
// changing it (no inputs, no drag handles, no save).
import { computed } from 'vue'
import { availableSlotCount } from '../../utils/questionPaperNode'

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  // The immediate parent's own label, only when that parent is itself a
  // numbered branch (mode all/choose) below the top-level Group card — see
  // the two recursive <QuestionNodeViewer> calls below, one of which
  // passes this and one of which deliberately doesn't. A leaf whose
  // parent is a plain question number like "1" or "4" reads as "1)i",
  // "4)ii" (the number and its sub-part composed back together here,
  // purely for display — the stored label is just "i"/"ii", see
  // questionPaperParser.js's own docblock on this); a leaf sitting
  // directly under the Group card keeps its own plain label ("2", "6")
  // with no group-name prefix.
  parentLabel: { type: String, default: '' },
})

// Respects a reviewer's own manual slots_override (set in
// QuestionNodeEditor.vue), same as that component's own display, so this
// read-only view never contradicts what was actually saved. See
// availableSlotCount()'s own docblock.
const availableSlots = computed(() => availableSlotCount(props.node))

const modeText = computed(() => {
  if (props.node.mode === 'choose') return `Choose ${props.node.choose_count} of ${availableSlots.value}`
  return 'Answer all questions'
})

const displayLabel = computed(() => (props.parentLabel ? `${props.parentLabel})${props.node.label}` : props.node.label))
</script>

<template>
  <!-- Depth 0: a full "Group" card, matching the editor's own styling. -->
  <section v-if="depth === 0" class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
    <div class="flex items-start justify-between gap-3 mb-2">
      <h3 class="text-[15px] sm:text-base font-semibold text-gray-900">{{ node.label }}</h3>
      <span class="shrink-0 inline-flex items-center rounded-full bg-brand-blue/10 text-brand-blue px-3 py-1 text-[11px] font-semibold">
        {{ modeText }}
      </span>
    </div>
    <p v-if="node.instruction" class="text-[13px] text-muted mb-3">{{ node.instruction }}</p>

    <div class="flex flex-col gap-2">
      <QuestionNodeViewer v-for="child in node.children" :key="child.id ?? child.label" :node="child" :depth="depth + 1" />
    </div>
  </section>

  <!-- Depth > 0, branch (mode all/choose): a nested box containing its own children. -->
  <div v-else-if="node.mode !== 'leaf'" class="rounded-2xl border border-input-border bg-page-bg/40 p-3">
    <div class="flex items-center justify-between gap-3 mb-1.5">
      <span class="text-[13px] font-semibold text-gray-800">{{ node.label }}</span>
      <span class="shrink-0 text-[11px] font-medium text-brand-blue">{{ modeText }}</span>
    </div>
    <p v-if="node.instruction" class="text-[12px] text-muted mb-2">{{ node.instruction }}</p>

    <div class="flex flex-col gap-2 pl-3 border-l-2 border-input-border">
      <QuestionNodeViewer v-for="child in node.children" :key="child.id ?? child.label" :node="child" :depth="depth + 1" :parent-label="node.label" />
    </div>
  </div>

  <!-- Depth > 0, leaf: the actual question — label, optional Bloom/CO pills, marks. -->
  <div v-else class="flex items-center justify-between gap-3 rounded-lg bg-white border border-input-border px-3 py-2">
    <span class="text-[13px] text-gray-800">{{ displayLabel }}</span>
    <div class="flex items-center gap-2 shrink-0">
      <span v-if="node.bloom_level" class="inline-flex items-center rounded-md bg-badge/15 text-badge px-2 py-0.5 text-[11px] font-medium">{{ node.bloom_level }}</span>
      <span v-if="node.co" class="inline-flex items-center rounded-md bg-badge/15 text-badge px-2 py-0.5 text-[11px] font-medium">{{ node.co }}</span>
      <span class="text-[12px] font-semibold text-gray-600">{{ node.marks }} {{ node.marks === 1 ? 'mark' : 'marks' }}</span>
    </div>
  </div>
</template>
