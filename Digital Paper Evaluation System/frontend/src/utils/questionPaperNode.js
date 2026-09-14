// Shared factory/mapper for the question-paper structure tree's local
// reactive shape — used by both QuestionPaperConfigureView.vue (the ancestor
// holding the `groups` array) and QuestionNodeEditor.vue (which recurses
// into it). Lives here rather than inline in either component so the `key`
// counter below is one shared, monotonically-increasing sequence across
// every node in the tree, no matter how deep — a per-component-instance
// counter would restart at 1 on every recursive instance and produce
// duplicate :key values.
let nextKey = 1

/**
 * A leaf is an actual answerable question — no children, has its own marks
 * plus two optional fields many papers print alongside each question:
 * bloom_level (Bloom's Taxonomy, e.g. "K1") and co (Course Outcome, e.g.
 * "CO1"). Both stay blank unless the PDF actually has them — see
 * questionPaperParser.js, which never guesses a value for either.
 */
export function createLeaf() {
  return {
    key: nextKey++,
    label: '',
    mode: 'leaf',
    choose_count: '',
    marks: '',
    bloom_level: '',
    co: '',
    children: [],
    labelError: '',
    chooseCountError: '',
    childrenError: '',
    marksError: '',
  }
}

/** A branch groups children under one selection rule — 'all' or 'choose'. */
export function createBranch(mode, instruction = '') {
  return {
    key: nextKey++,
    label: '',
    instruction,
    mode,
    choose_count: mode === 'choose' ? 1 : '',
    marks: '',
    children: [],
    labelError: '',
    chooseCountError: '',
    childrenError: '',
    marksError: '',
  }
}

/**
 * Maps a plain node (from the server's saved tree, or the PDF auto-fill
 * parser — both use the same {label, instruction?, mode, choose_count,
 * marks, children} shape) into this file's local reactive shape, recursing
 * into children. `key`/error fields are local-only and never sent back to
 * either source.
 */
export function mapNodeFromData(raw) {
  return {
    key: nextKey++,
    label: raw.label ?? '',
    instruction: raw.instruction ?? '',
    mode: raw.mode ?? 'leaf',
    choose_count: raw.choose_count ?? '',
    marks: raw.marks ?? '',
    bloom_level: raw.bloom_level ?? '',
    co: raw.co ?? '',
    children: (raw.children ?? []).map(mapNodeFromData),
    labelError: '',
    chooseCountError: '',
    childrenError: '',
    marksError: '',
  }
}

/** Strips the local-only key/error fields back out for the API payload. */
export function nodeToPayload(node) {
  const payload = {
    label: node.label,
    mode: node.mode,
  }
  if (node.instruction) payload.instruction = node.instruction
  if (node.mode === 'leaf') {
    payload.marks = Number(node.marks)
    payload.bloom_level = node.bloom_level || null
    payload.co = node.co || null
  } else {
    if (node.mode === 'choose') payload.choose_count = Number(node.choose_count)
    payload.children = node.children.map(nodeToPayload)
  }
  return payload
}

/** Recursively sums leaf marks across the whole (sub)tree. */
export function sumMarks(node) {
  if (node.mode === 'leaf') return Number(node.marks) || 0
  return node.children.reduce((sum, child) => sum + sumMarks(child), 0)
}

/**
 * The number of actual selectable question positions in a (sub)tree — a
 * 'leaf' or 'choose' node is always exactly one position from its
 * parent's point of view (a 'choose' node's alternatives are still only
 * ever one question being answered), while an 'all' node is pure grouping
 * and contributes the sum of its own children's slot counts. This is what
 * a 'choose' node's own bounds are validated against (see validateNode()
 * below and its backend mirror, ValidatesQuestionPaperNodes::slotCount()),
 * not the node's raw children.length, so a "choose 11" pool wrapping
 * several labeled sub-groups (each itself holding several questions) pools
 * correctly instead of being compared against the sub-group count.
 */
export function slotCount(node) {
  if (node.mode !== 'all') return 1
  return node.children.reduce((sum, child) => sum + slotCount(child), 0)
}

/** Clears every error field in the (sub)tree, recursively. */
export function clearErrors(node) {
  node.labelError = ''
  node.chooseCountError = ''
  node.childrenError = ''
  node.marksError = ''
  node.children.forEach(clearErrors)
}

/**
 * Recursively validates one node, writing messages onto its own error
 * fields (and recursing into children) — mirrors the backend's own
 * recursive walk in UpdateQuestionPaperRequest so the two never drift.
 * Returns true if this (sub)tree is valid.
 */
export function validateNode(node) {
  let ok = true

  if (!node.label.trim()) {
    node.labelError = 'Name is required.'
    ok = false
  }

  if (node.mode === 'leaf') {
    if (!node.marks || Number(node.marks) < 1) {
      node.marksError = 'Required.'
      ok = false
    }
    return ok
  }

  const minChildren = node.mode === 'choose' ? 2 : 1
  if (node.children.length < minChildren) {
    node.childrenError = node.mode === 'choose'
      ? 'A "choose" item needs at least 2 options below it.'
      : 'Add at least one question below.'
    ok = false
  }

  if (node.mode === 'choose') {
    const count = Number(node.choose_count)
    const slots = node.children.reduce((sum, child) => sum + slotCount(child), 0)
    if (!count) {
      node.chooseCountError = 'Enter how many of these must be attempted.'
      ok = false
    } else if (count > slots) {
      node.chooseCountError = `Cannot require more than the ${slots} option(s) below.`
      ok = false
    }
  }

  node.children.forEach((child) => {
    if (!validateNode(child)) ok = false
  })

  return ok
}
