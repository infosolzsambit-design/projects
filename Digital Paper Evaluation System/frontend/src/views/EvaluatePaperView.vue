<script setup>
// The real marking screen "Start Evaluate"/"Start Evaluation" on
// MyPendingCoursesView.vue opens once the face-scan gate (if any) has
// passed — same PDF-viewer + annotation-toolbar shape as the original R&D
// prototype (ReviewDetailView.vue, a client-only demo), but wired to a
// real AnswerSheet instead: the PDF comes from GET /my-pending-courses/
// papers/{id}, the marks sidebar is built from that sheet's *actual*
// question-paper structure (mapping.questionPaper.groups — see
// MyPendingCourseController::show()), not a hardcoded scheme.
//
// Deliberately a top-level route (see router/index.js) — no
// AppSidebar/AppHeader/AppFooter chrome eating into a screen this dense;
// the slim bar below is this page's own, not the app's persistent one.
//
// Everything (marks + drawn annotations) autosaves as a draft the whole
// time (see saveDraft()) — "Complete" (icon rail) is the one action that
// actually finalizes. Once the evaluation window's own time budget runs
// out (isOvertime), every input locks — marks fields, annotation tools,
// undo/redo — Complete is the only thing left to click, and at that point
// it bypasses the completeness checks below entirely (there's nothing
// left the teacher can do to fix a gap, so blocking would just strand
// them). Before that point, Complete validates two things and highlights
// whatever's missing in red rather than silently accepting a rushed
// evaluation: every visible page needs at least one annotation, and every
// question needs marks — for a `choose` group specifically, that means at
// least choose_count of its alternatives, not necessarily all of them
// (see collectMarksErrors()). A `choose` group's alternatives each get
// their own input so the teacher can compare before settling, but only
// the *best* one counts toward the total (see computeNodeAwarded()) —
// never the sum of all of them.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import api, { resolveStorageUrl } from '../utils/api'
import { useToast } from '../composables/useToast'
import { useConfirm } from '../composables/useConfirm'
import { loadPdf, renderPageToCanvas } from '../utils/pdf'
import { decodeId } from '../utils/obfuscateId'
import EvaluateQuestionNode from '../components/evaluation/EvaluateQuestionNode.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { confirmDialog } = useConfirm()

// Set the instant "Complete" actually succeeds — skips the unsaved-work
// confirm below (see onBeforeRouteLeave/onBeforeUnload) for the
// programmatic navigation that follows a real completion, while still
// catching every other way off this page (back button, closing the tab,
// an in-app link) up until then.
let completed = false

const sheetId = computed(() => decodeId(route.params.token))

// --- sheet + question-paper structure ---------------------------------
const sheet = ref(null)
const sheetLoading = ref(true)
const sheetLoadError = ref('')

async function loadSheet() {
  if (!sheetId.value) {
    sheetLoadError.value = 'Invalid link.'
    sheetLoading.value = false
    return
  }
  sheetLoading.value = true
  sheetLoadError.value = ''
  try {
    const res = await api.get(`/my-pending-courses/papers/${sheetId.value}`)
    sheet.value = res.data.data
  } catch (err) {
    sheetLoadError.value = err.response?.data?.message || 'Could not load this answer sheet.'
    sheetLoading.value = false
    return
  }

  // Resume any prior draft — a teacher reopening a sheet they'd already
  // started (closed the tab, machine restarted, whatever) picks up
  // exactly where they left off instead of starting over. Restoring
  // *before* the PDF renders means the drawn annotations paint correctly
  // the first time (redrawOverlay() reads straight from `annotations`
  // per page) rather than needing a second pass.
  if (sheet.value.draft_marks_breakdown) Object.assign(marksByNode, sheet.value.draft_marks_breakdown)
  if (sheet.value.draft_annotations) Object.assign(annotations, sheet.value.draft_annotations)
  startTimer(sheet.value.consumed_time || 0)

  // Flips the template over to the marking-screen shell (canvas-viewport
  // included) *before* loading the PDF — not after, in a shared finally.
  // loadCurrentPdf()'s own renderAllPages() reads canvas elements that
  // only exist once that shell has actually mounted; doing this the other
  // way around (sheetLoading only turning false once loadCurrentPdf() has
  // already returned) meant every renderPage() call found no canvas yet,
  // silently rendered nothing, and never got asked again — pages showing
  // with correct numbers/labels but staying visibly blank.
  sheetLoading.value = false
  await nextTick()
  await loadCurrentPdf()
}

// Opens the source question paper's own PDF in a new tab — the "Question
// Paper" rail button below. A side-by-side embedded view is a fair
// follow-up, but a new tab is the simplest thing that actually works
// today without shrinking the answer-sheet viewer to make room for it.
function openQuestionPaper() {
  const url = resolveStorageUrl(sheet.value?.question_paper?.pdf_url)
  if (url) window.open(url, '_blank', 'noopener')
}

const tools = [
  { id: 'select', label: 'Select', icon: '🖱' },
  { id: 'pencil', label: 'Pencil', icon: '✏' },
  { id: 'wrong', label: 'Wrong', icon: '✗' },
  { id: 'correct', label: 'Correct', icon: '✓' },
  { id: 'blank', label: 'Blank', icon: '▭' },
  { id: 'delete', label: 'Delete', icon: '🗑' },
]

const canvasViewportEl = ref(null)

// DOM/pdf.js handles keyed by page number. Plain objects on purpose — pdf.js
// objects use native private class fields, which break if Vue wraps them in
// a reactive Proxy, and canvas elements don't need reactivity at all.
const pageContainerEls = {}
const baseCanvasEls = {}
const overlayCanvasEls = {}
const pageViewports = {}

function setPageContainerRef(page, el) {
  if (el) pageContainerEls[page] = el
  else delete pageContainerEls[page]
}
function setBaseCanvasRef(page, el) {
  if (el) baseCanvasEls[page] = el
  else delete baseCanvasEls[page]
}
function setOverlayCanvasRef(page, el) {
  if (el) overlayCanvasEls[page] = el
  else delete overlayCanvasEls[page]
}

const pdfDoc = shallowRef(null)
const loadingPdf = ref(true)
const pdfLoadError = ref('')
const pdfPageCount = ref(0)
// Page 1 is the exam center's cover sheet — student name/roll number/etc.
// (see AnswerSheet's own roll_no/name fields, printed there by whoever
// scanned the physical sheet) — and must stay out of a teacher's view for
// blind evaluation. Excluded here, not just visually hidden: renderPage()
// only ever gets called for pages in this list (see renderAllPages()), so
// page 1's content is never even fetched onto a canvas, not merely
// covered up by CSS.
const pageNumbers = computed(() => Array.from({ length: Math.max(0, pdfPageCount.value - 1) }, (_, i) => i + 2))
const pageNumber = ref(2) // the page currently in view while scrolling — starts at the first visible (non-cover) page

// What the toolbar/page badges actually display — 1-based position among
// the *visible* pages (so a 5-page PDF with its cover hidden reads "1 of
// 4", "2 of 4", …), not the real PDF page number, which would otherwise
// confusingly start at 2 and make it look like a page is missing rather
// than deliberately withheld.
function visiblePageIndex(page) {
  return pageNumbers.value.indexOf(page) + 1
}
const scale = ref(1)
const rotation = ref(0)
const activeTool = ref('select')

// --- checking timer — starts the moment the page opens; the face-scan
// gate on MyPendingCoursesView.vue already served as the "start" gesture,
// so there's no separate "Start Checking" click here like the prototype
// had. Resumable: reopening a sheet with a saved draft seeds this from
// its consumed_time instead of restarting at zero (see loadSheet()).
const elapsedSeconds = ref(0)
let timerInterval = null
let checkingStartTimestamp = null
function startTimer(resumeSeconds = 0) {
  checkingStartTimestamp = Date.now() - resumeSeconds * 1000
  elapsedSeconds.value = resumeSeconds
  timerInterval = setInterval(() => {
    elapsedSeconds.value = Math.floor((Date.now() - checkingStartTimestamp) / 1000)
  }, 1000)
}
function stopTimer() {
  if (timerInterval) clearInterval(timerInterval)
  timerInterval = null
}
function formatDuration(totalSeconds) {
  const total = Math.abs(totalSeconds)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = Math.floor(total % 60)
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}
// The per-sheet budget the admin set on "Assign" (evaluation_time_per_sheet,
// minutes — see AssignTeacherView.vue) — null when none was set, in which
// case there's nothing to count down against.
const totalMinutes = computed(() => sheet.value?.evaluation_time_per_sheet ?? null)
const remainingSeconds = computed(() => (totalMinutes.value != null ? totalMinutes.value * 60 - elapsedSeconds.value : null))
// Negative once over budget — shown as "+MM:SS over" in red rather than a
// confusing negative-looking duration.
const isOvertime = computed(() => remainingSeconds.value != null && remainingSeconds.value < 0)
const remainingDisplay = computed(() => (remainingSeconds.value != null ? formatDuration(remainingSeconds.value) : '—'))
// The instant time runs out, drop back to the inert 'select' tool — a
// drawing tool that was already active shouldn't keep working just
// because it was picked a moment before the deadline.
watch(isOvertime, (over) => {
  if (over) activeTool.value = 'select'
})

// --- marks entry, against the sheet's real question-paper structure ---
// Keyed by leaf node id (every leaf gets its own input, including each
// alternative inside a `choose` group — see EvaluateQuestionNode.vue).
const marksByNode = reactive({})

function collectLeafNodes(nodes, acc = []) {
  for (const node of nodes || []) {
    if (node.mode === 'leaf') acc.push(node)
    else collectLeafNodes(node.children, acc)
  }
  return acc
}
const leafNodes = computed(() => collectLeafNodes(sheet.value?.question_paper?.groups))

// A `choose` group's own contribution is the *best* of its alternatives
// (only one was ever actually answered), never their sum; 'all' sums its
// children; a leaf is just whatever's typed in. Recurses the same tree
// EvaluateQuestionNode.vue renders, so this always matches what's on
// screen.
function computeNodeAwarded(node) {
  if (node.mode === 'leaf') return Number(marksByNode[node.id]) || 0
  const childTotals = (node.children || []).map(computeNodeAwarded)
  return node.mode === 'choose' ? Math.max(0, ...childTotals) : childTotals.reduce((sum, v) => sum + v, 0)
}
const totalAwarded = computed(() =>
  (sheet.value?.question_paper?.groups || []).reduce((sum, group) => sum + computeNodeAwarded(group), 0),
)
const maxTotalMarks = computed(() => sheet.value?.max_marks ?? null)

// --- completeness checks — run by completeEvaluation() below (skipped
// once isOvertime; see this file's own top docblock for why) ---
function hasValue(nodeId) {
  const v = marksByNode[nodeId]
  return v !== undefined && v !== null && v !== ''
}
// Whether *anything* under this node has a value — a `choose` alternative
// counts as "attempted" the moment any leaf beneath it does, even if it's
// itself a further-nested branch rather than a plain leaf.
function subtreeHasValue(node) {
  if (node.mode === 'leaf') return hasValue(node.id)
  return (node.children || []).some(subtreeHasValue)
}
function collectSubtreeLeafIds(node, acc = []) {
  if (node.mode === 'leaf') acc.push(node.id)
  else (node.children || []).forEach((child) => collectSubtreeLeafIds(child, acc))
  return acc
}

// Walks the real question-paper tree and returns the set of leaf node ids
// that need the teacher's attention before this can be completed: an
// empty leaf outside any `choose` group, or — inside one — as many of its
// still-empty alternatives as it takes to explain why that group doesn't
// have its required choose_count filled in yet (e.g. "choose 1 of 2" with
// neither filled flags both; with one already filled, flags neither).
function collectMarksErrors() {
  const badIds = new Set()

  function walk(node) {
    if (node.mode === 'leaf') {
      if (!hasValue(node.id)) badIds.add(node.id)
      return
    }
    if (node.mode === 'choose') {
      const alternatives = node.children || []
      const filledCount = alternatives.filter(subtreeHasValue).length
      if (filledCount < (node.choose_count || 1)) {
        alternatives.forEach((alt) => {
          if (!subtreeHasValue(alt)) collectSubtreeLeafIds(alt).forEach((id) => badIds.add(id))
        })
      }
      return
    }
    (node.children || []).forEach(walk) // 'all' — every child stands on its own
  }
  (sheet.value?.question_paper?.groups || []).forEach(walk)

  return badIds
}

// Which pages still have no annotation on them at all.
function collectEmptyPages() {
  return pageNumbers.value.filter((page) => getAnnotations(page).length === 0)
}

// Populated by completeEvaluation() when it blocks; EvaluateQuestionNode.vue
// reads errorNodeIds to redden a specific input, and the page badges below
// read errorPages the same way. Cleared field-by-field/page-by-page as the
// teacher actually fixes each one (see the two watchers further down),
// rather than only all at once on the next Complete click.
const errorNodeIds = ref(new Set())
const errorPages = ref(new Set())

// --- per-page undo/redo history + annotations — autosaved as a draft
// (see saveDraft() below), so these survive a reload; only undo/redo
// history itself is session-only (there's no real reason to persist it —
// the current annotation state is what matters, not how it got there) ---
const annotations = reactive({}) // page -> list of {type, ...}
const history = reactive({}) // page -> {undo:[], redo:[]}
function getAnnotations(page) {
  return annotations[page] || []
}
function getHistory(page) {
  if (!history[page]) history[page] = { undo: [], redo: [] }
  return history[page]
}
const canUndo = computed(() => (history[pageNumber.value]?.undo.length || 0) > 0)
const canRedo = computed(() => (history[pageNumber.value]?.redo.length || 0) > 0)

// --- draft autosave — everything typed/drawn here is a draft until
// "Complete" (see completeEvaluation() below); this is what makes that
// true in practice rather than just in name. Fires on a short debounce
// after any marks/annotation change, plus a plain periodic timer
// regardless of changes, so a browser crash or a machine turning off
// mid-evaluation loses at most a few seconds of work. Deliberately
// fire-and-forget (no toast, no loading state) — a background safety
// net shouldn't interrupt what the teacher's actually doing; a failed
// attempt just gets picked up by the next debounce or timer tick.
let draftSaveTimer = null
async function saveDraft() {
  if (!sheet.value || completed) return
  try {
    await api.post(`/my-pending-courses/papers/${sheet.value.id}/save-draft`, {
      marks_breakdown: { ...marksByNode },
      annotations: JSON.parse(JSON.stringify(annotations)),
      consumed_time: elapsedSeconds.value,
    })
  } catch {
    // Silent by design — see this block's own docblock above.
  }
}
function scheduleDraftSave() {
  clearTimeout(draftSaveTimer)
  draftSaveTimer = setTimeout(saveDraft, 2000)
}
watch(marksByNode, scheduleDraftSave)
watch(annotations, scheduleDraftSave)
// Clears a field's/page's red highlight the moment it's actually fixed,
// rather than making the teacher click Complete again just to see it go
// away.
watch(marksByNode, () => {
  if (!errorNodeIds.value.size) return
  leafNodes.value.forEach((node) => {
    if (hasValue(node.id)) errorNodeIds.value.delete(node.id)
  })
})
watch(annotations, () => {
  if (!errorPages.value.size) return
  pageNumbers.value.forEach((page) => {
    if (getAnnotations(page).length) errorPages.value.delete(page)
  })
})
let periodicDraftSaveTimer = null

function commitAnnotations(page, list, { recordHistory = true } = {}) {
  if (recordHistory) {
    const h = getHistory(page)
    h.undo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
    h.redo = []
  }
  annotations[page] = list
  redrawOverlay(page)
}
function addAnnotation(page, ann) {
  commitAnnotations(page, [...getAnnotations(page), ann])
}
function undo() {
  if (isOvertime.value) return
  const page = pageNumber.value
  const h = getHistory(page)
  if (!h.undo.length) return
  h.redo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
  annotations[page] = h.undo.pop()
  redrawOverlay(page)
}
function redo() {
  if (isOvertime.value) return
  const page = pageNumber.value
  const h = getHistory(page)
  if (!h.redo.length) return
  h.undo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
  annotations[page] = h.redo.pop()
  redrawOverlay(page)
}

// --- coordinate conversion: annotations are stored in PDF space so they
// stay correctly placed across zoom / rotation changes ---
function pixelToPdf(viewport, pos) {
  const [x, y] = viewport.convertToPdfPoint(pos.x, pos.y)
  return [x, y]
}
function pdfToPixel(viewport, pt) {
  const [x, y] = viewport.convertToViewportPoint(pt[0], pt[1])
  return { x, y }
}

async function renderPage(page) {
  const canvas = baseCanvasEls[page]
  if (!canvas) return
  try {
    const viewport = await renderPageToCanvas(pdfDoc.value, page, canvas, {
      scale: scale.value,
      rotation: rotation.value,
    })
    pageViewports[page] = viewport
    const overlay = overlayCanvasEls[page]
    if (overlay) {
      overlay.width = viewport.width
      overlay.height = viewport.height
    }
    redrawOverlay(page)
  } catch (err) {
    console.error('Failed to render PDF page', page, err)
    pdfLoadError.value = err?.message || String(err)
  }
}

async function renderAllPages() {
  if (!pdfDoc.value) return
  for (const page of pageNumbers.value) {
    await renderPage(page)
  }
}

function drawPencilPath(ctx, points, color = 'rgba(220,38,38,0.85)') {
  if (points.length < 2) return
  ctx.strokeStyle = color
  ctx.lineWidth = 2.5
  ctx.lineJoin = 'round'
  ctx.lineCap = 'round'
  ctx.beginPath()
  points.forEach((p, i) => (i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)))
  ctx.stroke()
}
function drawCheck(ctx, pos, viewport) {
  const s = 10 * (viewport?.scale || 1)
  ctx.strokeStyle = '#16a34a'
  ctx.lineWidth = 3 * (viewport?.scale || 1)
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.beginPath()
  ctx.moveTo(pos.x - s, pos.y)
  ctx.lineTo(pos.x - s * 0.25, pos.y + s * 0.7)
  ctx.lineTo(pos.x + s, pos.y - s * 0.8)
  ctx.stroke()
}
function drawCross(ctx, pos, viewport) {
  const s = 9 * (viewport?.scale || 1)
  ctx.strokeStyle = '#dc2626'
  ctx.lineWidth = 3 * (viewport?.scale || 1)
  ctx.lineCap = 'round'
  ctx.beginPath()
  ctx.moveTo(pos.x - s, pos.y - s)
  ctx.lineTo(pos.x + s, pos.y + s)
  ctx.moveTo(pos.x + s, pos.y - s)
  ctx.lineTo(pos.x - s, pos.y + s)
  ctx.stroke()
}
function drawBlankRect(ctx, start, end, color = 'rgba(234,88,12,0.9)') {
  ctx.strokeStyle = color
  ctx.lineWidth = 2
  ctx.setLineDash([6, 4])
  const x = Math.min(start.x, end.x)
  const y = Math.min(start.y, end.y)
  ctx.strokeRect(x, y, Math.abs(end.x - start.x), Math.abs(end.y - start.y))
  ctx.setLineDash([])
}
function drawAnnotation(ctx, ann, viewport) {
  if (ann.type === 'pencil') drawPencilPath(ctx, ann.points.map((pt) => pdfToPixel(viewport, pt)))
  else if (ann.type === 'correct') drawCheck(ctx, pdfToPixel(viewport, ann.point), viewport)
  else if (ann.type === 'wrong') drawCross(ctx, pdfToPixel(viewport, ann.point), viewport)
  else if (ann.type === 'blank') drawBlankRect(ctx, pdfToPixel(viewport, ann.start), pdfToPixel(viewport, ann.end))
}
function redrawOverlay(page, previewPencilPoints = null, previewBlank = null) {
  const canvas = overlayCanvasEls[page]
  const viewport = pageViewports[page]
  if (!canvas || !viewport) return
  const ctx = canvas.getContext('2d')
  ctx.clearRect(0, 0, canvas.width, canvas.height)
  for (const ann of getAnnotations(page)) drawAnnotation(ctx, ann, viewport)
  if (previewPencilPoints) drawPencilPath(ctx, previewPencilPoints)
  if (previewBlank) drawBlankRect(ctx, previewBlank.start, previewBlank.end)
}

// --- pointer interaction ---
let isDrawing = false
let activeDrawPage = null
let pencilPixelPoints = []
let blankDragStart = null
let lastPixelPos = null

function getPixelPos(evt, page) {
  const rect = overlayCanvasEls[page].getBoundingClientRect()
  return { x: evt.clientX - rect.left, y: evt.clientY - rect.top }
}
function distanceToAnnotation(ann, pos, viewport) {
  if (ann.type === 'correct' || ann.type === 'wrong') {
    const p = pdfToPixel(viewport, ann.point)
    return Math.hypot(p.x - pos.x, p.y - pos.y)
  }
  if (ann.type === 'pencil') {
    return Math.min(...ann.points.map((pt) => {
      const p = pdfToPixel(viewport, pt)
      return Math.hypot(p.x - pos.x, p.y - pos.y)
    }))
  }
  if (ann.type === 'blank') {
    const a = pdfToPixel(viewport, ann.start)
    const b = pdfToPixel(viewport, ann.end)
    const minX = Math.min(a.x, b.x), maxX = Math.max(a.x, b.x)
    const minY = Math.min(a.y, b.y), maxY = Math.max(a.y, b.y)
    if (pos.x >= minX && pos.x <= maxX && pos.y >= minY && pos.y <= maxY) return 0
    return Math.hypot(Math.max(minX - pos.x, 0, pos.x - maxX), Math.max(minY - pos.y, 0, pos.y - maxY))
  }
  return Infinity
}
function deleteNearest(pos, page, viewport) {
  const list = getAnnotations(page)
  let bestIdx = -1
  let bestDist = Infinity
  list.forEach((ann, idx) => {
    const dist = distanceToAnnotation(ann, pos, viewport)
    if (dist < bestDist) {
      bestDist = dist
      bestIdx = idx
    }
  })
  if (bestIdx !== -1 && bestDist <= 16) {
    commitAnnotations(page, list.filter((_, i) => i !== bestIdx))
  }
}
function onPointerDown(evt, page) {
  // Belt-and-suspenders alongside the disabled toolbar buttons below —
  // activeTool is already forced back to 'select' the instant time runs
  // out (see the isOvertime watcher above), so this only ever matters for
  // 'select'/'delete' clicks that don't go through the toolbar at all.
  if (isOvertime.value) return
  const viewport = pageViewports[page]
  if (!viewport) return
  const pos = getPixelPos(evt, page)
  lastPixelPos = pos
  activeDrawPage = page
  if (activeTool.value === 'pencil') {
    isDrawing = true
    pencilPixelPoints = [pos]
  } else if (activeTool.value === 'blank') {
    isDrawing = true
    blankDragStart = pos
  } else if (activeTool.value === 'correct' || activeTool.value === 'wrong') {
    addAnnotation(page, { type: activeTool.value, point: pixelToPdf(viewport, pos) })
  } else if (activeTool.value === 'delete') {
    deleteNearest(pos, page, viewport)
  }
}
function onPointerMove(evt, page) {
  if (!isDrawing || activeDrawPage !== page) return
  const pos = getPixelPos(evt, page)
  lastPixelPos = pos
  if (activeTool.value === 'pencil') {
    pencilPixelPoints.push(pos)
    redrawOverlay(page, pencilPixelPoints)
  } else if (activeTool.value === 'blank') {
    redrawOverlay(page, null, { start: blankDragStart, end: pos })
  }
}
function onPointerUp(evt, page) {
  if (!isDrawing || activeDrawPage !== page) return
  isDrawing = false
  const viewport = pageViewports[page]
  if (activeTool.value === 'pencil' && pencilPixelPoints.length > 1) {
    addAnnotation(page, { type: 'pencil', points: pencilPixelPoints.map((p) => pixelToPdf(viewport, p)) })
  } else if (activeTool.value === 'blank' && blankDragStart && lastPixelPos) {
    addAnnotation(page, {
      type: 'blank',
      start: pixelToPdf(viewport, blankDragStart),
      end: pixelToPdf(viewport, lastPixelPos),
    })
  }
  pencilPixelPoints = []
  blankDragStart = null
  activeDrawPage = null
  redrawOverlay(page)
}
function onPointerLeave(evt, page) {
  if (isDrawing && activeDrawPage === page) onPointerUp(evt, page)
}

// --- page nav / zoom / rotate ---
function scrollToPage(page) {
  pageContainerEls[page]?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function prevPage() {
  if (pageNumber.value > 2) scrollToPage(pageNumber.value - 1) // never back to the hidden cover page (1)
}
function nextPage() {
  if (pageNumber.value < (pdfPageCount.value || 1)) scrollToPage(pageNumber.value + 1)
}
function zoomIn() {
  scale.value = Math.min(3, +(scale.value + 0.1).toFixed(2))
}
function zoomOut() {
  scale.value = Math.max(0.4, +(scale.value - 0.1).toFixed(2))
}
function zoomReset() {
  scale.value = 1
}
function zoomFit() {
  const viewport = pageViewports[pageNumber.value] || pageViewports[1]
  if (!viewport || !canvasViewportEl.value) return
  const containerWidth = canvasViewportEl.value.clientWidth - 48
  const nativeWidth = viewport.width / scale.value
  scale.value = Math.max(0.3, Math.min(3, +(containerWidth / nativeWidth).toFixed(2)))
}
function rotateCW() {
  rotation.value = (rotation.value + 90) % 360
}

// --- track which page is in view while scrolling ---
let observer = null
function setupObserver() {
  teardownObserver()
  if (!canvasViewportEl.value) return
  observer = new IntersectionObserver(
    (entries) => {
      let best = null
      for (const entry of entries) {
        if (entry.isIntersecting && (!best || entry.intersectionRatio > best.intersectionRatio)) {
          best = entry
        }
      }
      if (best) {
        const page = Number(best.target.dataset.page)
        if (page) pageNumber.value = page
      }
    },
    { root: canvasViewportEl.value, threshold: [0.15, 0.3, 0.5, 0.7, 0.9] },
  )
  for (const page of pageNumbers.value) {
    const el = pageContainerEls[page]
    if (el) observer.observe(el)
  }
}
function teardownObserver() {
  observer?.disconnect()
  observer = null
}

watch([scale, rotation], renderAllPages)

async function loadCurrentPdf() {
  // Backend sends a relative /storage/… path — resolveStorageUrl() points
  // it at the *backend's* own origin instead of this page's (the frontend
  // dev server otherwise 404s trying to serve it itself). Missing this is
  // exactly why the PDF silently failed to render before.
  const url = resolveStorageUrl(sheet.value?.pdf_url)
  if (!url) {
    loadingPdf.value = false
    return
  }
  loadingPdf.value = true
  pdfLoadError.value = ''
  teardownObserver()
  try {
    pdfDoc.value = await loadPdf(url)
    pdfPageCount.value = pdfDoc.value.numPages
  } catch (err) {
    console.error('Failed to load PDF', url, err)
    pdfLoadError.value = err?.message || String(err)
    loadingPdf.value = false
    return
  }
  loadingPdf.value = false
  await nextTick()
  await renderAllPages()
  setupObserver()
}

// --- complete / close ---
// "Complete" (icon rail) is the only action that actually finalizes an
// evaluation — everything up to that point, however much marking/drawing
// happened, is just a draft (see saveDraft() above).
const completing = ref(false)
async function completeEvaluation() {
  // Every slot bounded by its own node's max is already enforced by each
  // <input>'s own min/max — this just catches an empty/invalid field
  // being silently treated as 0 without the teacher noticing.
  const invalid = leafNodes.value.find((node) => {
    const value = marksByNode[node.id]
    return value !== undefined && value !== '' && Number(value) < 0
  })
  if (invalid) {
    toast.error('Marks can\'t be negative.')
    return
  }

  // Completeness is only enforced while there's still time left to *do*
  // anything about it — every input's already locked once isOvertime is
  // true (see this file's own top docblock), so blocking here too would
  // just strand the teacher with no way forward.
  if (!isOvertime.value) {
    const badNodeIds = collectMarksErrors()
    const badPages = collectEmptyPages()
    if (badNodeIds.size || badPages.length) {
      errorNodeIds.value = badNodeIds
      errorPages.value = new Set(badPages)
      const parts = []
      if (badNodeIds.size) parts.push('fill in marks for every question (highlighted in red)')
      if (badPages.length) parts.push(`add at least one annotation on every page — missing on page ${badPages.map((p) => visiblePageIndex(p)).join(', ')}`)
      toast.error(`Before completing, ${parts.join(' and ')}.`)
      return
    }
  }

  completing.value = true
  try {
    const res = await api.post(`/my-pending-courses/papers/${sheet.value.id}/submit-marks`, {
      marks: totalAwarded.value,
      consumed_time: elapsedSeconds.value,
    })
    toast.success(res.data.message || 'Marks submitted successfully.')
    completed = true
    router.push({ name: 'my-pending-courses' })
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not submit marks.')
  } finally {
    completing.value = false
  }
}

function closeViewer() {
  router.push({ name: 'my-pending-courses' })
}

// Closing the tab/browser or refreshing mid-evaluation — the draft
// autosave already minimizes real data loss (see saveDraft() above), so
// this is mainly a courtesy nudge ("are you sure?"), not itself a save
// point: browsers ignore any custom message and don't reliably wait for
// an async request started here, so there's nothing meaningfully more to
// do inside this handler than ask.
function onBeforeUnload(e) {
  if (completed || !sheet.value) return
  e.preventDefault()
  e.returnValue = ''
}
// A tab being backgrounded (switched away from, phone locked, …) often
// precedes it actually closing, and unlike beforeunload this handler gets
// a normal amount of time to actually run — an extra save point beyond
// the debounce/periodic timer below.
function onVisibilityChange() {
  if (document.visibilityState === 'hidden' && !completed) saveDraft()
}

// In-app navigation (the sidebar isn't shown here, but a browser back
// button, or any RouterLink this page might still reach, counts) — same
// "are you sure?" as onBeforeUnload above, just via this app's own
// confirm dialog instead of the browser's native one, and with a real
// chance to fire one last draft save before actually leaving.
onBeforeRouteLeave(async () => {
  if (completed || !sheet.value) return true // nothing to lose if the sheet never even loaded
  const ok = await confirmDialog({
    title: 'Leave this evaluation?',
    message: "This evaluation isn't complete yet. Your progress is saved as a draft, but you'll need to come back and hit Complete to finish it.",
    confirmText: 'Leave',
    cancelText: 'Stay',
  })
  if (ok) saveDraft()
  return ok
})

onMounted(() => {
  loadSheet()
  window.addEventListener('beforeunload', onBeforeUnload)
  document.addEventListener('visibilitychange', onVisibilityChange)
  periodicDraftSaveTimer = setInterval(saveDraft, 20000)
})
onBeforeUnmount(() => {
  teardownObserver()
  stopTimer()
  clearTimeout(draftSaveTimer)
  clearInterval(periodicDraftSaveTimer)
  window.removeEventListener('beforeunload', onBeforeUnload)
  document.removeEventListener('visibilitychange', onVisibilityChange)
})
</script>

<template>
  <div class="min-h-screen h-screen flex flex-col font-poppins bg-page-bg text-gray-900 antialiased overflow-hidden">
    <p v-if="sheetLoading" class="m-auto text-sm text-muted">Loading&hellip;</p>

    <div v-else-if="sheetLoadError" class="m-auto bg-white rounded-2xl shadow-panel p-10 text-center max-w-md">
      <p class="text-[15px] font-semibold text-gray-900">{{ sheetLoadError }}</p>
      <RouterLink :to="{ name: 'my-pending-courses' }" class="mt-2 inline-block text-[13px] text-brand-blue hover:underline">&larr; Back to My Pending Course</RouterLink>
    </div>

    <template v-else>
      <!-- Slim top bar — this page's own, not the app's persistent header -->
      <header class="shrink-0 h-14 bg-white shadow-[0_2px_8px_rgba(0,0,0,0.06)] flex items-center justify-between gap-3 px-4 sm:px-6">
        <div class="flex items-center gap-3 min-w-0">
          <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="closeViewer">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          </button>
          <div class="min-w-0">
            <p class="text-[14px] font-semibold text-gray-900 truncate">{{ sheet.roll_no || sheet.name || `Sheet #${sheet.id}` }}</p>
            <p class="text-[12px] text-muted truncate">{{ sheet.subject_code }} — {{ sheet.subject_name }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 text-[11px] font-medium">
          <span class="inline-flex items-center gap-1 rounded-full status-gradient-border px-2.5 py-1.5 text-gray-800" title="Time budgeted for this sheet (set when it was assigned)">
            <span class="text-muted">Total</span> {{ totalMinutes != null ? `${totalMinutes}m` : '—' }}
          </span>
          <span
            class="inline-flex items-center gap-1 rounded-full status-gradient-border px-2.5 py-1.5"
            :class="isOvertime ? 'text-brand' : 'text-gray-800'"
            title="Time remaining in the budgeted window"
          >
            <span class="text-muted">{{ isOvertime ? 'Over by' : 'Left' }}</span> {{ remainingDisplay }}
          </span>
        </div>
      </header>

      <div class="flex-1 min-h-0 flex gap-3 p-3">
        <!-- icon rail -->
        <aside class="w-20 shrink-0 flex flex-col gap-2">
          <button
            type="button"
            class="flex flex-col items-center gap-1 rounded-xl bg-white shadow-panel px-1.5 py-2.5 text-[10.5px] font-medium text-gray-700 hover:text-brand-blue transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-gray-700"
            :disabled="!sheet.question_paper?.pdf_url"
            title="Open the source question paper"
            @click="openQuestionPaper"
          >
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
            Question Paper
          </button>
          <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-white shadow-panel px-1.5 py-2.5 text-[10.5px] font-medium text-gray-400 cursor-not-allowed" disabled title="Coming soon">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
            Moderation
          </button>
          <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-white shadow-panel px-1.5 py-2.5 text-[10.5px] font-medium text-gray-400 cursor-not-allowed" disabled title="Coming soon">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></svg>
            Answer Key
          </button>
          <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-white shadow-panel px-1.5 py-2.5 text-[10.5px] font-medium text-gray-400 cursor-not-allowed" disabled title="Coming soon">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
            Problem
          </button>

          <!-- Everything above is autosaved as a draft the whole time (see
               saveDraft()) — this is the one action that actually finishes
               the evaluation. -->
          <button
            type="button"
            class="flex flex-col items-center gap-1 rounded-xl bg-btn-gradient shadow-panel px-1.5 py-2.5 text-[10.5px] font-semibold text-white hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="completing"
            title="Finish and submit this evaluation"
            @click="completeEvaluation"
          >
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
            {{ completing ? 'Saving…' : 'Complete' }}
          </button>
        </aside>

        <!-- question / marks panel -->
        <aside class="w-72 shrink-0 bg-white rounded-2xl shadow-panel p-3.5 overflow-y-auto">
          <template v-if="sheet.question_paper?.groups?.length">
            <EvaluateQuestionNode
              v-for="group in sheet.question_paper.groups"
              :key="group.id"
              :node="group"
              :marks-by-node="marksByNode"
              :error-node-ids="errorNodeIds"
              :locked="isOvertime"
            />
          </template>
          <p v-else class="text-[12px] text-muted mb-3">No question-paper structure is set up for this course yet.</p>

          <div class="mt-3 pt-3 border-t border-soft text-[13px] text-gray-800">
            Total: <strong class="text-brand-blue">{{ totalAwarded }}</strong> / {{ maxTotalMarks ?? '—' }}
          </div>
        </aside>

        <!-- canvas area -->
        <div class="flex-1 min-w-0 flex flex-col gap-3">
          <div class="flex items-center gap-1 flex-wrap rounded-full bg-white shadow-panel px-3 py-1.5">
            <button
              v-for="tool in tools"
              :key="tool.id"
              type="button"
              class="flex flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold tracking-wide transition-colors disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:bg-transparent"
              :class="activeTool === tool.id ? 'bg-brand-blue/10 text-brand-blue' : 'text-gray-600 hover:bg-soft'"
              :title="tool.id !== 'select' && isOvertime ? 'Time is up — no further changes can be made' : tool.label"
              :disabled="tool.id !== 'select' && isOvertime"
              @click="activeTool = tool.id"
            >
              <span class="text-[15px] leading-none">{{ tool.icon }}</span>{{ tool.label }}
            </button>

            <span class="w-px self-stretch bg-input-border mx-1"></span>

            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:bg-transparent" title="Undo" :disabled="!canUndo || isOvertime" @click="undo">↺ UNDO</button>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:bg-transparent" title="Redo" :disabled="!canRedo || isOvertime" @click="redo">↻ REDO</button>

            <span class="w-px self-stretch bg-input-border mx-1"></span>

            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:bg-transparent" title="Previous page" :disabled="pageNumber <= 2" @click="prevPage">‹ PREV</button>
            <span class="text-[11px] text-muted px-1 whitespace-nowrap">{{ visiblePageIndex(pageNumber) }} of {{ pageNumbers.length || '…' }}</span>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:bg-transparent" title="Next page" :disabled="pageNumber >= (pdfPageCount || 1)" @click="nextPage">NEXT ›</button>

            <span class="w-px self-stretch bg-input-border mx-1"></span>

            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft" title="Zoom out" @click="zoomOut">− OUT</button>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft" title="Reset zoom" @click="zoomReset">{{ Math.round(scale * 100) }}%</button>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft" title="Zoom in" @click="zoomIn">+ IN</button>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft" title="Fit to width" @click="zoomFit">⤢ FIT</button>
            <button type="button" class="rounded-lg px-2.5 py-1.5 text-[10px] font-semibold text-gray-600 hover:bg-soft" title="Rotate" @click="rotateCW">⟳ ROTATE</button>

            <div class="ml-auto shrink-0 flex items-center gap-1.5">
              <span
                v-if="isOvertime"
                class="shrink-0 inline-flex items-center gap-1 rounded-full bg-brand/10 text-[10px] font-semibold text-brand px-2.5 py-1"
                title="The evaluation window has ended — marks and annotations are locked. Click Complete to finish."
              >
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
                Time is up — locked
              </span>

              <!-- <span
                class="shrink-0 inline-flex items-center gap-1 rounded-full bg-page-bg text-[10px] font-medium text-muted px-2.5 py-1"
                title="The exam center's cover sheet (student name/roll number) is withheld from this view for blind evaluation."
              >
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8-11-8-11-8z" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                Cover page hidden
              </span> -->
            </div>
          </div>

          <div ref="canvasViewportEl" class="flex-1 overflow-auto rounded-2xl border border-input-border bg-soft p-6 flex flex-col">
            <p v-if="loadingPdf" class="m-auto text-sm text-muted">Loading PDF&hellip;</p>
            <div v-else-if="pdfLoadError" class="m-auto text-center text-sm text-brand">
              ⚠ Could not load this PDF.<br /><span class="text-[12px] opacity-75">{{ pdfLoadError }}</span>
            </div>
            <p v-else-if="!sheet.pdf_url" class="m-auto text-sm text-muted">No PDF is attached to this answer sheet.</p>
            <p v-else-if="!pageNumbers.length" class="m-auto text-sm text-muted text-center">
              This answer sheet only has a cover page, which is withheld from evaluation — there's nothing else to show.
            </p>
            <div v-else class="flex flex-col items-center gap-8">
              <div
                v-for="page in pageNumbers"
                :key="page"
                class="flex flex-col items-center gap-2"
                :data-page="page"
                :ref="(el) => setPageContainerRef(page, el)"
              >
                <span
                  class="text-[11px] px-2.5 py-1 rounded-full shadow-sm transition-colors"
                  :class="errorPages.has(page) ? 'bg-brand/10 text-brand font-semibold' : 'bg-white text-muted'"
                  :title="errorPages.has(page) ? 'Add at least one annotation on this page before completing.' : null"
                >
                  Page {{ visiblePageIndex(page) }} of {{ pageNumbers.length }}{{ errorPages.has(page) ? ' — needs an annotation' : '' }}
                </span>
                <div class="relative inline-block shadow-lg leading-none">
                  <canvas :ref="(el) => setBaseCanvasRef(page, el)" class="block"></canvas>
                  <canvas
                    :ref="(el) => setOverlayCanvasRef(page, el)"
                    class="absolute top-0 left-0"
                    :class="{
                      'cursor-crosshair': activeTool === 'pencil' || activeTool === 'blank',
                      'cursor-pointer': activeTool === 'correct' || activeTool === 'wrong',
                      'cursor-not-allowed': activeTool === 'delete',
                    }"
                    @mousedown="(e) => onPointerDown(e, page)"
                    @mousemove="(e) => onPointerMove(e, page)"
                    @mouseup="(e) => onPointerUp(e, page)"
                    @mouseleave="(e) => onPointerLeave(e, page)"
                  ></canvas>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
