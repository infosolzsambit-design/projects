<script setup>
import { ref, shallowRef, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usePapersStore } from '../stores/papers'
import { useTeacherStore } from '../stores/teacher'
import { loadPdf, renderPageToCanvas } from '../utils/pdf'
import { questionScheme, maxTotalMarks } from '../data/questionScheme'
import FaceScanModal from '../components/FaceScanModal.vue'

// Teacher-facing marking screen. Must never read papersStore.studentMap —
// the paper is identified only by its QR / Serial number (paper.id).
const route = useRoute()
const router = useRouter()
const papersStore = usePapersStore()
const teacherStore = useTeacherStore()

const paper = computed(() => papersStore.paperById(route.params.id))
const pageNumbers = computed(() => Array.from({ length: paper.value?.pageCount || 0 }, (_, i) => i + 1))

const tools = [
  { id: 'select', label: 'SELECT', icon: '🖱' },
  { id: 'pencil', label: 'PENCIL', icon: '✏' },
  { id: 'wrong', label: 'WRONG', icon: '✗' },
  { id: 'correct', label: 'CORRECT', icon: '✓' },
  { id: 'blank', label: 'BLANK', icon: '▭' },
  { id: 'delete', label: 'DELETE', icon: '🗑' },
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
const pageNumber = ref(1) // the page currently in view while scrolling
const scale = ref(1)
const rotation = ref(0)
const activeTool = ref('select')
const saved = ref(false)
const loadError = ref('')

const totalAwarded = computed(() =>
  Object.values(paper.value?.marks || {}).reduce((sum, v) => sum + (Number(v) || 0), 0),
)

// --- checking timer ---
const checkingStarted = ref(false)
const elapsedSeconds = ref(0)
let timerInterval = null
let checkingStartTimestamp = null

// --- face-scan gate: teacher must match their registered profile before
// they can start checking, and again before they can finish. ---
const faceScanPurpose = ref(null) // null | 'start' | 'finish'

function startTimer() {
  checkingStartTimestamp = Date.now()
  elapsedSeconds.value = 0
  timerInterval = setInterval(() => {
    elapsedSeconds.value = Math.floor((Date.now() - checkingStartTimestamp) / 1000)
  }, 1000)
}

function stopTimer() {
  if (timerInterval) clearInterval(timerInterval)
  timerInterval = null
}

const elapsedDisplay = computed(() => {
  const total = elapsedSeconds.value
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
})

function requestStartChecking() {
  faceScanPurpose.value = 'start'
}

function requestFinishChecking() {
  faceScanPurpose.value = 'finish'
}

function onFaceMatched() {
  const purpose = faceScanPurpose.value
  faceScanPurpose.value = null
  if (purpose === 'start') startChecking()
  else if (purpose === 'finish') finishChecking()
}

function startChecking() {
  checkingStarted.value = true
  papersStore.startChecking(paper.value.id)
  startTimer()
}

function finishChecking() {
  stopTimer()
  papersStore.finishChecking(paper.value.id, elapsedSeconds.value)
  submitEvaluation()
}

// Per-page undo/redo history, kept local to this screen (not persisted).
const history = reactive({})
function getHistory(page) {
  if (!history[page]) history[page] = { undo: [], redo: [] }
  return history[page]
}
const canUndo = computed(() => (history[pageNumber.value]?.undo.length || 0) > 0)
const canRedo = computed(() => (history[pageNumber.value]?.redo.length || 0) > 0)

function getAnnotations(page) {
  return paper.value.annotations[page] || []
}

function commitAnnotations(page, list, { recordHistory = true } = {}) {
  if (recordHistory) {
    const h = getHistory(page)
    h.undo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
    h.redo = []
  }
  papersStore.setAnnotations(paper.value.id, page, list)
  redrawOverlay(page)
}

function addAnnotation(page, ann) {
  commitAnnotations(page, [...getAnnotations(page), ann])
}

function undo() {
  const page = pageNumber.value
  const h = getHistory(page)
  if (!h.undo.length) return
  h.redo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
  papersStore.setAnnotations(paper.value.id, page, h.undo.pop())
  redrawOverlay(page)
}

function redo() {
  const page = pageNumber.value
  const h = getHistory(page)
  if (!h.redo.length) return
  h.undo.push(JSON.parse(JSON.stringify(getAnnotations(page))))
  papersStore.setAnnotations(paper.value.id, page, h.redo.pop())
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
    loadError.value = err?.message || String(err)
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
  if (pageNumber.value > 1) scrollToPage(pageNumber.value - 1)
}
function nextPage() {
  if (pageNumber.value < (paper.value.pageCount || 1)) scrollToPage(pageNumber.value + 1)
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

// --- save / submit / close ---
function saveEvaluation() {
  saved.value = true
  setTimeout(() => (saved.value = false), 2000)
}
function submitEvaluation() {
  papersStore.setStatus(paper.value.id, 'Reviewed')
  router.push('/review')
}

function closeViewer() {
  router.push('/review')
}

watch([scale, rotation], renderAllPages)

async function loadCurrentPaper() {
  if (!paper.value) return
  loadingPdf.value = true
  loadError.value = ''
  teardownObserver()
  try {
    pdfDoc.value = await loadPdf(paper.value.fileUrl)
    if (!paper.value.pageCount) papersStore.setPageCount(paper.value.id, pdfDoc.value.numPages)
  } catch (err) {
    console.error('Failed to load PDF', paper.value.fileName, err)
    loadError.value = err?.message || String(err)
    loadingPdf.value = false
    return
  }
  loadingPdf.value = false
  await nextTick()
  await renderAllPages()
  setupObserver()
}

onMounted(loadCurrentPaper)
onBeforeUnmount(() => {
  teardownObserver()
  stopTimer()
})

watch(
  () => route.params.id,
  () => {
    pageNumber.value = 1
    scale.value = 1
    rotation.value = 0
    checkingStarted.value = false
    faceScanPurpose.value = null
    stopTimer()
    elapsedSeconds.value = 0
    loadCurrentPaper()
  },
)
</script>

<template>
  <section v-if="paper" class="marking-screen">
    <aside class="icon-rail">
      <button class="rail-btn" title="Not available in this demo" disabled>
        <span class="rail-icon">📄</span><span>Question Paper</span>
      </button>
      <button class="rail-btn" title="Not available in this demo" disabled>
        <span class="rail-icon">ℹ️</span><span>Moderation</span>
      </button>
      <button class="rail-btn" title="Not available in this demo" disabled>
        <span class="rail-icon">✅</span><span>Answer Key</span>
      </button>
      <button class="rail-btn" title="Not available in this demo" disabled>
        <span class="rail-icon">⚠️</span><span>Problem</span>
      </button>
      <div class="rail-spacer"></div>
      <div class="timer-badge" title="Time spent checking this paper">⏱ {{ elapsedDisplay }}</div>
      <button class="rail-btn accent" title="Save" @click="saveEvaluation">
        <span class="rail-icon">💾</span><span>Save</span>
      </button>
      <button class="rail-btn accent submit" title="Submit" @click="requestFinishChecking">
        <span class="rail-icon">🔒</span><span>Submit</span>
      </button>
      <button class="rail-btn danger" title="Close" @click="closeViewer">
        <span class="rail-icon">✕</span><span>Close</span>
      </button>
    </aside>

    <aside class="question-panel">
      <div class="paper-id">QR / Serial: <code>{{ paper.id }}</code></div>

      <div v-for="group in questionScheme" :key="group.title" class="question-group">
        <div class="group-header">Max Attempt Question: {{ group.maxAttempt }}</div>
        <div v-for="q in group.questions" :key="q.key" class="question-row">
          <span class="q-label">{{ q.label }}</span>
          <input v-model="paper.marks[q.key]" type="number" class="q-input" :min="0" :max="q.max" />
          <span class="q-max">/{{ q.max.toFixed(1) }}</span>
          <input type="checkbox" class="q-check" :checked="!!paper.marks[q.key]" disabled />
        </div>
      </div>

      <div class="total-row">Total: <strong>{{ totalAwarded }}</strong> / {{ maxTotalMarks }}</div>
    </aside>

    <div class="canvas-area">
      <div class="toolbar">
        <button
          v-for="tool in tools"
          :key="tool.id"
          class="tool-btn"
          :class="{ active: activeTool === tool.id }"
          :title="tool.label"
          @click="activeTool = tool.id"
        >
          <span class="tool-icon">{{ tool.icon }}</span>
          <span class="tool-label">{{ tool.label }}</span>
        </button>

        <span class="toolbar-divider"></span>

        <button class="tool-btn" title="Undo" :disabled="!canUndo" @click="undo">
          <span class="tool-icon">↺</span><span class="tool-label">UNDO</span>
        </button>
        <button class="tool-btn" title="Redo" :disabled="!canRedo" @click="redo">
          <span class="tool-icon">↻</span><span class="tool-label">REDO</span>
        </button>

        <span class="toolbar-divider"></span>

        <button class="tool-btn" title="Previous page" :disabled="pageNumber <= 1" @click="prevPage">
          <span class="tool-icon">‹</span><span class="tool-label">PREV</span>
        </button>
        <span class="page-indicator">{{ pageNumber }} of {{ paper.pageCount ?? '…' }}</span>
        <button
          class="tool-btn"
          title="Next page"
          :disabled="pageNumber >= (paper.pageCount || 1)"
          @click="nextPage"
        >
          <span class="tool-icon">›</span><span class="tool-label">NEXT</span>
        </button>

        <span class="toolbar-divider"></span>

        <button class="tool-btn" title="Zoom out" @click="zoomOut">
          <span class="tool-icon">−</span><span class="tool-label">OUT</span>
        </button>
        <button class="tool-btn" title="Reset zoom" @click="zoomReset">
          <span class="tool-label">{{ Math.round(scale * 100) }}%</span>
        </button>
        <button class="tool-btn" title="Zoom in" @click="zoomIn">
          <span class="tool-icon">+</span><span class="tool-label">IN</span>
        </button>
        <button class="tool-btn" title="Fit to width" @click="zoomFit">
          <span class="tool-icon">⤢</span><span class="tool-label">FIT</span>
        </button>
        <button class="tool-btn" title="Rotate" @click="rotateCW">
          <span class="tool-icon">⟳</span><span class="tool-label">ROTATE</span>
        </button>
      </div>

      <div class="canvas-viewport" ref="canvasViewportEl">
        <p v-if="loadingPdf" class="loading-msg">Loading PDF&hellip;</p>
        <p v-else-if="loadError" class="error-msg">
          ⚠ Could not load this PDF.<br /><span class="error-detail">{{ loadError }}</span>
        </p>
        <div v-else class="pages-list">
          <div
            v-for="page in pageNumbers"
            :key="page"
            class="page-block"
            :data-page="page"
            :ref="(el) => setPageContainerRef(page, el)"
          >
            <div class="page-number-badge">Page {{ page }} of {{ paper.pageCount }}</div>
            <div class="canvas-stack">
              <canvas :ref="(el) => setBaseCanvasRef(page, el)" class="base-canvas"></canvas>
              <canvas
                :ref="(el) => setOverlayCanvasRef(page, el)"
                class="overlay-canvas"
                :class="`tool-${activeTool}`"
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

    <div v-if="!checkingStarted" class="start-gate-overlay">
      <div class="start-gate-card">
        <h1>Paper {{ paper.id }}</h1>
        <template v-if="teacherStore.isRegistered">
          <p>Verify your face to start checking this paper. This starts the checking timer.</p>
          <button class="cta" @click="requestStartChecking">Start Checking</button>
        </template>
        <template v-else>
          <p class="warn-text">
            You need to register your face before you can check papers.
          </p>
          <RouterLink to="/teacher/register" class="cta">Register Now</RouterLink>
        </template>
        <RouterLink to="/review" class="back-link">&larr; Back to list</RouterLink>
      </div>
    </div>

    <FaceScanModal
      v-if="faceScanPurpose"
      :title="faceScanPurpose === 'start' ? 'Verify Your Face to Start Checking' : 'Verify Your Face to Finish Checking'"
      @matched="onFaceMatched"
      @cancel="faceScanPurpose = null"
    />

    <p v-if="saved" class="saved-toast">✅ Saved (dummy &mdash; in-memory only, not sent to a server).</p>
  </section>

  <section v-else class="not-found">
    <p>Paper not found.</p>
    <RouterLink to="/review">&larr; Back to list</RouterLink>
  </section>
</template>

<style scoped>
.marking-screen {
  display: flex;
  gap: 1rem;
  align-items: stretch;
  height: 78vh;
  position: relative;
}

/* checking gate — overlays the marking UI so the PDF can load/render
   underneath while it's showing, instead of the canvases only existing
   in the DOM after this is dismissed (which caused a render race). */
.start-gate-overlay {
  position: absolute;
  inset: 0;
  background: var(--color-background);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 20;
}

.start-gate-card {
  text-align: center;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 10px;
}

.start-gate-card h1 {
  color: var(--color-heading);
  font-size: 1.3rem;
}

.start-gate-card p {
  color: var(--color-text);
  opacity: 0.8;
}

.start-gate-card .warn-text {
  color: #e57373;
  opacity: 1;
}

.start-gate-card .cta {
  padding: 0.6rem 1.5rem;
  border: none;
  border-radius: 6px;
  background: hsla(160, 100%, 37%, 1);
  color: white;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
}

.start-gate-card .back-link {
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.7;
  text-decoration: none;
}

.timer-badge {
  text-align: center;
  font-size: 0.8rem;
  font-weight: 600;
  padding: 0.4rem 0.3rem;
  border-radius: 8px;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  color: var(--color-heading);
}

/* icon rail */
.icon-rail {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.4rem;
  width: 90px;
  flex-shrink: 0;
}

.rail-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  padding: 0.6rem 0.3rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 0.7rem;
  cursor: pointer;
  text-align: center;
}

.rail-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.rail-icon {
  font-size: 1.2rem;
}

.rail-spacer {
  flex: 1;
}

.rail-btn.accent {
  background: hsla(160, 100%, 37%, 0.12);
  border-color: hsla(160, 100%, 37%, 0.4);
  color: hsla(160, 100%, 30%, 1);
}

.rail-btn.submit {
  background: hsla(160, 100%, 37%, 1);
  border-color: hsla(160, 100%, 37%, 1);
  color: white;
}

.rail-btn.danger {
  background: hsla(0, 70%, 50%, 0.1);
  border-color: hsla(0, 70%, 50%, 0.4);
  color: hsl(0, 70%, 45%);
}

/* question panel */
.question-panel {
  width: 260px;
  flex-shrink: 0;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 0.75rem;
}

.paper-id {
  font-size: 0.85rem;
  padding-bottom: 0.6rem;
  margin-bottom: 0.6rem;
  border-bottom: 1px solid var(--color-border);
}

.paper-id code {
  background: var(--color-background-soft);
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
}

.question-group {
  margin-bottom: 0.75rem;
}

.group-header {
  background: var(--color-background-soft);
  font-size: 0.78rem;
  font-weight: 600;
  padding: 0.35rem 0.5rem;
  border-radius: 4px;
  margin-bottom: 0.3rem;
  color: var(--color-heading);
}

.question-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.25rem 0.3rem;
  font-size: 0.85rem;
}

.q-label {
  width: 46px;
  color: hsla(160, 100%, 30%, 1);
  font-weight: 600;
}

.q-input {
  width: 44px;
  padding: 0.15rem 0.3rem;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

.q-max {
  opacity: 0.6;
  font-size: 0.78rem;
  width: 32px;
}

.q-check {
  margin-left: auto;
}

.total-row {
  margin-top: 0.75rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border);
  font-size: 0.9rem;
}

/* canvas area */
.canvas-area {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-wrap: wrap;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 0.4rem 0.75rem;
  background: var(--color-background-soft);
}

.tool-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
  border: none;
  background: transparent;
  color: var(--color-text);
  padding: 0.3rem 0.5rem;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.65rem;
  line-height: 1;
}

.tool-btn:hover:not(:disabled) {
  background: var(--color-background-mute);
}

.tool-btn.active {
  background: hsla(160, 100%, 37%, 0.18);
  color: hsla(160, 100%, 28%, 1);
}

.tool-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.tool-icon {
  font-size: 1rem;
}

.tool-label {
  font-weight: 600;
  letter-spacing: 0.02em;
}

.toolbar-divider {
  width: 1px;
  align-self: stretch;
  background: var(--color-border);
  margin: 0 0.25rem;
}

.page-indicator {
  font-size: 0.8rem;
  padding: 0 0.4rem;
  white-space: nowrap;
}

.canvas-viewport {
  flex: 1;
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-mute);
  padding: 1.5rem;
  min-height: 500px;
  display: flex;
  flex-direction: column;
}

.loading-msg,
.error-msg {
  margin: auto;
}

.error-msg {
  color: #d33;
  text-align: center;
}

.error-detail {
  font-size: 0.8rem;
  opacity: 0.75;
}

.pages-list {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2rem;
}

.page-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
}

.page-number-badge {
  font-size: 0.75rem;
  opacity: 0.65;
  background: var(--color-background-soft);
  padding: 0.15rem 0.6rem;
  border-radius: 999px;
}

.canvas-stack {
  position: relative;
  display: inline-block;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
  line-height: 0;
}

.base-canvas {
  display: block;
}

.overlay-canvas {
  position: absolute;
  top: 0;
  left: 0;
}

.overlay-canvas.tool-select {
  cursor: default;
}
.overlay-canvas.tool-pencil,
.overlay-canvas.tool-blank {
  cursor: crosshair;
}
.overlay-canvas.tool-correct,
.overlay-canvas.tool-wrong {
  cursor: pointer;
}
.overlay-canvas.tool-delete {
  cursor: not-allowed;
}

.saved-toast {
  position: absolute;
  bottom: 1rem;
  right: 1rem;
  background: hsla(160, 100%, 37%, 1);
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 6px;
  font-size: 0.85rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.not-found {
  text-align: center;
}
</style>
