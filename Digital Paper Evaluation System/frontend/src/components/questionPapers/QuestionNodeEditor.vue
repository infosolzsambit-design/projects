<script setup>
// Recursive editor for one node of a question paper's structure tree (see
// utils/questionPaperNode.js for the shape, and QuestionPaperNode on the
// backend for what mode/children mean) — depth 0 is a "Group", anything
// below is a question / part / OR-alternative, all edited by this same
// component calling itself for its own children. A `<script setup>` SFC
// can reference itself by filename for this in Vue 3.3+ — no manual
// registration needed.
//
// The template below has three mutually-exclusive root branches (one
// `v-if`/`v-else-if`/`v-else` each): "Depth 0" is a full "Group" card;
// "Depth > 0, branch" (mode all/choose) is a nested box containing its own
// children; "Depth > 0, leaf" is a plain Question No. / Marks row with two
// small links to branch out on demand. Deliberately NOT documented with
// inline `<!-- -->` comments right above those branch roots — every
// instance of this component is itself one item of a recursive
// `<draggable>` list (see dragGroup below), and vuedraggable maps its
// slot's rendered vnodes 1:1 to the underlying array by position; in dev
// builds Vue keeps template HTML comments as real sibling DOM nodes
// (production builds strip them), which desyncs that 1:1 mapping and
// corrupts drag tracking. Comments about *why* a branch looks the way it
// does belong here in the script block instead — never immediately beside
// a v-if/v-else-if/v-else root in this file's template.
import { computed } from 'vue'
import draggable from 'vuedraggable'
import { createLeaf, slotCount } from '../../utils/questionPaperNode'
import { useConfirm } from '../../composables/useConfirm'

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  removable: { type: Boolean, default: true },
  // The immediate parent's own label, only when that parent is itself a
  // numbered branch (mode all/choose) below the top-level Group card — see
  // the two recursive <QuestionNodeEditor> calls below, one of which
  // passes this and one of which deliberately doesn't. Shown as a
  // non-editable "1)" prefix right before a leaf's own label input, so
  // "1" + "i" reads as "1)i" without that composed string ever being part
  // of the actual editable/stored value — see QuestionNodeViewer.vue's
  // matching prop for the read-only counterpart, and
  // questionPaperParser.js's docblock for why the stored label is just
  // "i", not "1)i".
  parentLabel: { type: String, default: '' },
})
const emit = defineEmits(['remove'])
const { confirmDialog } = useConfirm()

// The plain auto-computed total of actual selectable questions below this
// node right now — not just node.children.length, since a child can itself
// be an "all" sub-group standing in for several questions (e.g. three
// labeled sub-groups pooled under one "choose 11 of 15" node). Shown as the
// "of N" field's own placeholder (see availableSlotCount()'s own docblock
// for why that field can be manually overridden) — deliberately ignores
// any slots_override already set, so clearing an override back to blank
// visibly reveals what it'll fall back to auto-computing again.
const autoComputedSlots = computed(() => props.node.children.reduce((sum, child) => sum + slotCount(child), 0))

// Sortable's nested-list handling can otherwise get confused about which
// list a drag started in (each level needs its own non-interacting drop
// zone) — a unique, non-sharing group per node keeps every list's
// reordering strictly to itself; nothing here should ever let an item be
// dragged out of its own list into an unrelated one anyway.
const dragGroup = computed(() => ({ name: `nodes-${props.node.key}`, pull: false, put: false }))

function addChild() {
  props.node.children.push(createLeaf())
}
function removeChild(index) {
  props.node.children.splice(index, 1)
}

// Converting a leaf into a branch carries over whatever marks/bloom
// level/CO the person already typed onto the first child, so filling in a
// question and *then* realizing it needs an alternative doesn't lose
// anything.
function carryLeafFieldsInto(child, source) {
  child.marks = source.marks
  child.bloom_level = source.bloom_level
  child.co = source.co
}
function breakIntoParts() {
  const first = createLeaf()
  carryLeafFieldsInto(first, props.node)
  props.node.mode = 'all'
  props.node.marks = ''
  props.node.bloom_level = ''
  props.node.co = ''
  props.node.children = [first]
}
function addAlternative() {
  if (props.node.mode === 'leaf') {
    const first = createLeaf()
    carryLeafFieldsInto(first, props.node)
    props.node.mode = 'choose'
    props.node.choose_count = 1
    props.node.marks = ''
    props.node.bloom_level = ''
    props.node.co = ''
    props.node.children = [first, createLeaf()]
  } else {
    props.node.children.push(createLeaf())
  }
}

// The undo for breakIntoParts()/addAlternative() — collapses a branch back
// into a single plain question, carrying its first child's fields back up
// (mirroring how splitting carries them *down*) so a quick "oops, didn't
// mean to split this" round-trip doesn't lose anything typed in either
// direction. Confirms first if there's real content below that would
// actually be discarded.
async function mergeBack() {
  const hasContent = props.node.children.some((child) => child.label.trim() || child.marks || child.bloom_level || child.co || child.children.length)
  if (hasContent) {
    const confirmed = await confirmDialog({
      title: 'Merge back to one question',
      message: 'This removes the parts/alternatives below and turns this back into a single question. Anything filled in below will be lost.',
      confirmText: 'Merge back',
    })
    if (!confirmed) return
  }
  const firstLeaf = props.node.children.find((child) => child.mode === 'leaf')
  if (firstLeaf) {
    carryLeafFieldsInto(props.node, firstLeaf)
  } else {
    props.node.marks = ''
    props.node.bloom_level = ''
    props.node.co = ''
  }
  props.node.mode = 'leaf'
  props.node.choose_count = ''
  props.node.children = []
  props.node.chooseCountError = ''
  props.node.childrenError = ''
}
</script>

<template>
  <section v-if="depth === 0" class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="flex-1 flex items-start gap-2">
        <span class="drag-handle select-none mt-8 shrink-0 cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-400" title="Drag to reorder">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" /><circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" /><circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" /></svg>
        </span>
        <div class="flex-1 flex flex-col gap-1.5">
        <label :for="`node-label-${node.key}`" class="text-[13px] text-label">Group Name <span class="text-brand">*</span></label>
        <input
          :id="`node-label-${node.key}`"
          v-model="node.label"
          type="text"
          placeholder="e.g. Group A"
          @input="node.labelError = ''"
          class="w-full max-w-[280px] h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
          :class="node.labelError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
        />
        <p v-if="node.labelError" class="text-[12px] text-brand">{{ node.labelError }}</p>
        </div>
      </div>
      <button
        v-if="removable"
        type="button"
        class="mt-6 shrink-0 h-9 px-3.5 rounded-lg border border-input-border text-[12px] font-semibold text-gray-600 hover:border-brand hover:text-brand transition-colors"
        @click="emit('remove')"
      >
        Remove Group
      </button>
    </div>

    <div class="flex flex-col gap-1.5 mb-3">
      <label :for="`node-instruction-${node.key}`" class="text-[13px] text-label">Instruction</label>
      <input
        :id="`node-instruction-${node.key}`"
        v-model="node.instruction"
        type="text"
        placeholder="e.g. Answer all questions. Each question carries 2 marks."
        class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
      />
    </div>

    <fieldset class="rounded-2xl border border-input-border bg-page-bg/60 p-3 mb-4">
      <legend class="px-2 text-[13px] font-medium text-label">How should this group be attempted?</legend>
      <div class="mt-2 flex flex-wrap items-center gap-4">
        <label class="form-check">
          <input type="radio" class="form-radio" :name="`selection-${node.key}`" value="all" v-model="node.mode" />
          <span>Answer all questions</span>
        </label>
        <label class="form-check">
          <input type="radio" class="form-radio" :name="`selection-${node.key}`" value="choose" v-model="node.mode" />
          <span>Choose</span>
        </label>
        <template v-if="node.mode === 'choose'">
          <input
            v-model="node.choose_count"
            type="number"
            min="1"
            placeholder="N"
            @input="node.chooseCountError = ''"
            class="w-16 h-9 px-2 rounded-lg bg-white text-sm text-gray-800 text-center outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
            :class="node.chooseCountError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
          />
          <span class="text-[13px] text-gray-600">of</span>
          <input
            v-model="node.slots_override"
            type="number"
            min="1"
            :placeholder="String(autoComputedSlots)"
            title="Auto-computed from the questions below — type a number to override it if it's wrong"
            class="w-14 h-9 px-2 rounded-lg bg-white text-sm text-gray-800 text-center outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
          />
          <span class="text-[13px] text-gray-600">question(s) below</span>
        </template>
      </div>
      <p v-if="node.chooseCountError" class="mt-2 text-[12px] text-brand">{{ node.chooseCountError }}</p>
    </fieldset>

    <p v-if="node.childrenError" class="text-[12px] text-brand mb-2">{{ node.childrenError }}</p>
    <draggable v-model="node.children" item-key="key" handle=".drag-handle" tag="div" :force-fallback="true" :group="dragGroup" class="flex flex-col gap-2">
      <template #item="{ element: child, index }">
        <QuestionNodeEditor
          :node="child"
          :depth="depth + 1"
          :removable="node.children.length > 1"
          @remove="removeChild(index)"
        />
      </template>
    </draggable>
    <button
      type="button"
      class="mt-2 self-start inline-flex items-center gap-1.5 text-[12px] font-semibold text-brand-blue hover:underline"
      @click="addChild"
    >
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
      Add Question
    </button>
  </section>

  <div v-else-if="node.mode !== 'leaf'" class="rounded-2xl border border-input-border bg-page-bg/40 p-3">
    <div class="flex items-start justify-between gap-3 mb-2">
      <div class="flex-1 flex items-center gap-2">
        <span class="drag-handle select-none shrink-0 cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-400" title="Drag to reorder">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" /><circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" /><circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" /></svg>
        </span>
        <input
          v-model="node.label"
          type="text"
          placeholder="e.g. 2"
          @input="node.labelError = ''"
          class="w-24 h-9 px-2.5 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
          :class="node.labelError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
        />
        <span class="text-[12px] text-muted">{{ node.mode === 'choose' ? 'choose one of these' : 'all of these required' }}</span>
      </div>
      <button
        v-if="removable"
        type="button"
        aria-label="Remove"
        class="h-8 w-8 shrink-0 rounded-lg border border-input-border text-gray-400 hover:border-brand hover:text-brand transition-colors flex items-center justify-center"
        @click="emit('remove')"
      >
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
      </button>
    </div>
    <p v-if="node.labelError" class="text-[11px] text-brand mb-1.5">{{ node.labelError }}</p>

    <input
      v-if="node.mode === 'all'"
      v-model="node.instruction"
      type="text"
      placeholder="Instruction for this sub-group (optional) — e.g. Answer all questions. Each question carries 5 marks."
      class="w-full h-8 px-2.5 mb-2 rounded-lg bg-input-bg text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
    />

    <div class="flex flex-wrap items-center gap-3 mb-2">
      <label class="form-check">
        <input type="radio" class="form-radio" :name="`selection-${node.key}`" value="all" v-model="node.mode" />
        <span class="text-[13px]">All required</span>
      </label>
      <label class="form-check">
        <input type="radio" class="form-radio" :name="`selection-${node.key}`" value="choose" v-model="node.mode" />
        <span class="text-[13px]">Choose</span>
      </label>
      <template v-if="node.mode === 'choose'">
        <input
          v-model="node.choose_count"
          type="number"
          min="1"
          placeholder="N"
          @input="node.chooseCountError = ''"
          class="w-14 h-8 px-2 rounded-lg bg-white text-sm text-gray-800 text-center outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
          :class="node.chooseCountError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
        />
        <span class="text-[12px] text-gray-600">of</span>
        <input
          v-model="node.slots_override"
          type="number"
          min="1"
          :placeholder="String(autoComputedSlots)"
          title="Auto-computed from the questions below — type a number to override it if it's wrong"
          class="w-12 h-8 px-2 rounded-lg bg-white text-sm text-gray-800 text-center outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
        />
      </template>
      <button type="button" class="text-[11px] text-muted hover:text-brand-blue hover:underline" @click="mergeBack">
        ↩ Merge back to one question
      </button>
    </div>
    <p v-if="node.chooseCountError" class="text-[11px] text-brand mb-2">{{ node.chooseCountError }}</p>
    <p v-if="node.childrenError" class="text-[11px] text-brand mb-2">{{ node.childrenError }}</p>

    <draggable
      v-model="node.children"
      item-key="key"
      handle=".drag-handle"
      tag="div"
      :force-fallback="true"
      :group="dragGroup"
      class="flex flex-col gap-2 pl-3 border-l-2 border-input-border"
    >
      <template #item="{ element: child, index }">
        <QuestionNodeEditor
          :node="child"
          :depth="depth + 1"
          :removable="node.children.length > (node.mode === 'choose' ? 2 : 1)"
          :parent-label="node.label"
          @remove="removeChild(index)"
        />
      </template>
    </draggable>
    <button
      type="button"
      class="mt-2 self-start inline-flex items-center gap-1 text-[12px] font-semibold text-brand-blue hover:underline"
      @click="node.mode === 'choose' ? addAlternative() : addChild()"
    >
      + {{ node.mode === 'choose' ? 'Add alternative' : 'Add part' }}
    </button>
  </div>

  <div v-else class="flex flex-col gap-1">
    <div class="grid grid-cols-[20px_1fr_60px_60px_84px_32px] gap-2 items-start">
      <span class="drag-handle select-none mt-2.5 cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-400 flex items-center justify-center" title="Drag to reorder">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" /><circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" /><circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" /></svg>
      </span>
      <div class="flex flex-col gap-1">
        <div class="flex items-center gap-1.5">
          <span v-if="parentLabel" class="shrink-0 text-sm font-medium text-muted select-none" :title="`Part of question ${parentLabel}`">{{ parentLabel }})</span>
          <input
            v-model="node.label"
            type="text"
            :placeholder="parentLabel ? 'e.g. i' : 'e.g. 1'"
            @input="node.labelError = ''"
            class="w-full h-9 px-3 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
            :class="node.labelError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
          />
        </div>
        <p v-if="node.labelError" class="text-[11px] text-brand">{{ node.labelError }}</p>
      </div>
      <input
        v-model="node.bloom_level"
        type="text"
        placeholder="K1"
        title="Bloom's Taxonomy level, e.g. K1 — leave blank if the paper doesn't print one"
        class="w-full h-9 px-2 rounded-lg bg-input-bg text-sm text-gray-800 text-center outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
      />
      <input
        v-model="node.co"
        type="text"
        placeholder="CO1"
        title="Course Outcome, e.g. CO1 — leave blank if the paper doesn't print one"
        class="w-full h-9 px-2 rounded-lg bg-input-bg text-sm text-gray-800 text-center outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
      />
      <div class="flex flex-col gap-1">
        <input
          v-model="node.marks"
          type="number"
          min="1"
          placeholder="Marks"
          @input="node.marksError = ''"
          class="w-full h-9 px-3 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
          :class="node.marksError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
        />
        <p v-if="node.marksError" class="text-[11px] text-brand">{{ node.marksError }}</p>
      </div>
      <button
        v-if="removable"
        type="button"
        aria-label="Remove question"
        class="h-9 w-9 rounded-lg border border-input-border text-gray-400 hover:border-brand hover:text-brand transition-colors flex items-center justify-center"
        @click="emit('remove')"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
      </button>
    </div>
    <div class="flex items-center gap-3 pl-0.5">
      <button type="button" class="text-[11px] text-muted hover:text-brand-blue hover:underline" @click="breakIntoParts">
        + Split into parts
      </button>
      <button type="button" class="text-[11px] text-muted hover:text-brand-blue hover:underline" @click="addAlternative">
        + Add OR alternative
      </button>
    </div>
  </div>
</template>
