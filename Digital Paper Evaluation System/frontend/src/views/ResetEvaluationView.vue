<script setup>
// Sidebar's "Reset Evaluation" — look an answer sheet up by barcode, review
// its evaluation (front page, uploaded file, evaluated file with the
// teacher's annotations, who evaluated it and when), and wipe that
// evaluation so the same teacher can redo it. The Reset button only shows
// while the sheet is inside its own evaluation start/end window (the server
// enforces the same rule — see ResetEvaluationController). Gated by
// 'reset-evaluation' — same UI-only authStore.can() pattern as every other
// page.
import { nextTick, onMounted, ref } from 'vue'
import api, { resolveStorageUrl } from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useExamTypeStore } from '../stores/examType'
import { useToast } from '../composables/useToast'
import { useConfirm } from '../composables/useConfirm'
import { formatDateTime } from '../utils/date'
import { loadPdf, renderPageToCanvas } from '../utils/pdf'
import { buildEvaluatedPdf, saveBlob } from '../utils/evaluatedPdf'
import { useProgramsStore } from '../stores/programs'
import { ordinal } from '../utils/ordinal'
import { typeSuffix } from '../utils/course'

// Program names display as "Name (Label)" — see stores/programs.js.
const programsStore = useProgramsStore()
programsStore.load().catch(() => {})

const authStore = useAuthStore()
const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()
const toast = useToast()
const { confirmDialog } = useConfirm()

const barcode = ref('')
const searching = ref(false)
const searched = ref(false)
const results = ref([])

const previewCanvases = {}
const previewErrors = ref({})
const downloading = ref(null) // `${sheetId}:uploaded` | `${sheetId}:evaluated` | null
const resettingId = ref(null)

function setPreviewCanvas(id, el) {
  if (el) previewCanvases[id] = el
}

async function search() {
  const value = barcode.value.trim()
  if (!value) {
    toast.error('Please enter a barcode to search.')
    return
  }
  searching.value = true
  try {
    const params = { barcode: value }
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/reset-evaluation/search', { params })
    results.value = res.data.data
    searched.value = true
    previewErrors.value = {}
    await nextTick()
    results.value.forEach(renderPreview)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not search for this barcode.')
  } finally {
    searching.value = false
  }
}

async function renderPreview(sheet) {
  const canvas = previewCanvases[sheet.id]
  if (!canvas || !sheet.pdf_url) return
  try {
    const pdf = await loadPdf(resolveStorageUrl(sheet.pdf_url))
    const page = await pdf.getPage(1)
    const baseWidth = page.getViewport({ scale: 1 }).width
    await renderPageToCanvas(pdf, 1, canvas, { scale: 320 / baseWidth })
  } catch {
    previewErrors.value = { ...previewErrors.value, [sheet.id]: true }
  }
}

async function fetchPdfBytes(sheet) {
  const res = await fetch(resolveStorageUrl(sheet.pdf_url))
  if (!res.ok) throw new Error('Could not load the uploaded file.')
  return res.arrayBuffer()
}

function fileBaseName(sheet) {
  return sheet.barcode || sheet.subject_barcode || `answer-sheet-${sheet.id}`
}

async function downloadUploaded(sheet) {
  downloading.value = `${sheet.id}:uploaded`
  try {
    const bytes = await fetchPdfBytes(sheet)
    saveBlob(new Blob([bytes], { type: 'application/pdf' }), `${fileBaseName(sheet)}.pdf`)
  } catch (err) {
    toast.error(err.message || 'Could not download the uploaded file.')
  } finally {
    downloading.value = null
  }
}

// The generated top sheet (same as Report → Answer Book / Top Sheet) for
// the evaluated file's first page. Only exists once final marks are in —
// a sheet still in draft is downloaded without it.
async function fetchTopSheetBytes(sheet) {
  if (sheet.marks === null || sheet.marks === undefined) return null
  // page_numbers=0: no "Page 1 of 1" footer on this cover page.
  const res = await api.get(`/reports/answer-book-top-sheet/${sheet.id}/view`, {
    params: { page_numbers: 0 },
    responseType: 'arraybuffer',
    skipLoader: true,
  })
  return res.data
}

async function downloadEvaluated(sheet) {
  downloading.value = `${sheet.id}:evaluated`
  try {
    const [bytes, coverPdfBytes] = await Promise.all([
      fetchPdfBytes(sheet),
      fetchTopSheetBytes(sheet).catch(() => {
        throw new Error('Could not generate the top sheet for this answer sheet.')
      }),
    ])
    const out = await buildEvaluatedPdf(bytes, {
      annotations: sheet.draft_annotations,
      marks: sheet.marks ?? sheet.draft_marks,
      maxMarks: sheet.max_marks,
      coverPdfBytes,
    })
    saveBlob(new Blob([out], { type: 'application/pdf' }), `${fileBaseName(sheet)}-evaluated.pdf`)
  } catch (err) {
    toast.error(err.message || 'Could not build the evaluated file.')
  } finally {
    downloading.value = null
  }
}

async function resetEvaluation(sheet) {
  const ok = await confirmDialog({
    title: 'Reset Evaluation?',
    message: `This clears the marks, annotations and consumed time for ${fileBaseName(sheet)}. The teacher will need to evaluate it again. This cannot be undone.`,
    confirmText: 'Reset',
  })
  if (!ok) return

  resettingId.value = sheet.id
  try {
    const res = await api.post(`/reset-evaluation/${sheet.id}/reset`)
    const index = results.value.findIndex((s) => s.id === sheet.id)
    if (index !== -1) results.value[index] = res.data.data
    toast.success(res.data.message || 'Evaluation reset successfully.')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not reset this evaluation.')
  } finally {
    resettingId.value = null
  }
}

function formatConsumed(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h) return `${h} hr ${m} min ${s} sec`
  if (m) return `${m} min ${s} sec`
  return `${s} sec`
}

function marksText(sheet) {
  const raw = sheet.marks ?? sheet.draft_marks
  if (raw === null || raw === undefined) return '—'
  const marks = Number(raw)
  const label = sheet.marks === null || sheet.marks === undefined ? ' (draft)' : ''
  return sheet.max_marks != null ? `${marks} / ${sheet.max_marks}${label}` : `${marks}${label}`
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('reset-evaluation')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Page header -->
    <div class="flex items-start gap-3 mb-5">
      <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
        <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
          <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <polyline points="1 4 1 10 7 10" />
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
          </svg>
        </span>
      </span>
      <div>
        <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Reset Evaluation</h1>
        <p class="mt-1 text-[13px] sm:text-sm text-muted">Find an answer sheet by barcode and reset its evaluation.</p>
      </div>
    </div>

    <!-- Search -->
    <form class="bg-white rounded-2xl shadow-panel p-4 sm:p-5 mb-5 flex flex-col sm:flex-row sm:items-end gap-3" @submit.prevent="search">
      <div class="flex-1 flex flex-col gap-1">
        <label for="reset-barcode" class="text-[13px] text-label">Barcode</label>
        <input
          id="reset-barcode"
          v-model="barcode"
          type="text"
          placeholder="Enter or scan the answer sheet barcode"
          class="w-full h-10 px-3.5 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
        />
      </div>
      <button
        type="submit"
        :disabled="searching"
        class="h-10 min-w-[120px] inline-flex items-center justify-center gap-2 rounded-full bg-btn-gradient text-white text-[13px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        {{ searching ? 'Searching…' : 'Search' }}
      </button>
    </form>

    <p v-if="searched && !results.length" class="bg-white rounded-2xl shadow-panel p-8 text-center text-sm text-muted">
      No answer sheet found for this barcode.
    </p>

    <!-- Results -->
    <section
      v-for="sheet in results"
      :key="sheet.id"
      class="bg-white rounded-2xl shadow-panel overflow-hidden mb-5"
    >
      <div class="bg-subject-header px-4 sm:px-5 py-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-white text-[15px] font-medium">Barcode: {{ sheet.barcode || sheet.subject_barcode || '—' }}</h2>
        <span
          class="inline-flex items-center rounded-full bg-white/15 text-white text-[12px] px-3 py-1"
        >
          {{ sheet.marks !== null && sheet.marks !== undefined ? 'Evaluated' : sheet.has_evaluation ? 'In Draft' : 'Not Evaluated' }}
        </span>
      </div>

      <div class="p-4 sm:p-5 flex flex-col lg:flex-row gap-5">
        <!-- Front page -->
        <div class="shrink-0 lg:w-[320px]">
          <p class="text-[13px] text-label mb-2">Answer Sheet Front Page</p>
          <div class="rounded-xl border border-input-border bg-page-bg overflow-hidden flex items-center justify-center min-h-[200px]">
            <p v-if="!sheet.pdf_url" class="text-[13px] text-muted py-10">No file uploaded.</p>
            <p v-else-if="previewErrors[sheet.id]" class="text-[13px] text-brand py-10">Could not load preview.</p>
            <canvas v-else :ref="(el) => setPreviewCanvas(sheet.id, el)" class="block max-w-full h-auto"></canvas>
          </div>
        </div>

        <!-- Details -->
        <div class="flex-1 min-w-0 flex flex-col gap-5">
          <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-5 gap-y-3 text-[13px]">
            <div><dt class="text-label">Barcode</dt><dd class="font-semibold text-gray-900">{{ sheet.barcode || sheet.subject_barcode || '—' }}</dd></div>
            <div><dt class="text-label">Program</dt><dd class="font-semibold text-gray-900">{{ programsStore.display(sheet.program_name) || '—' }}</dd></div>
            <div><dt class="text-label">Department</dt><dd class="font-semibold text-gray-900">{{ sheet.department_name || '—' }}</dd></div>
            <div>
              <dt class="text-label">Course</dt>
              <dd class="font-semibold text-gray-900">{{ sheet.course_name || '—' }}<span v-if="sheet.course_code" class="text-muted font-normal"> ({{ sheet.course_code }})</span><span v-if="sheet.course_type" class="text-muted font-normal">{{ typeSuffix(sheet.course_type) }}</span></dd>
            </div>
            <div><dt class="text-label">Examination</dt><dd class="font-semibold text-gray-900">{{ sheet.exam_type_name || '—' }}</dd></div>
            <div><dt class="text-label">Semester</dt><dd class="font-semibold text-gray-900">{{ ordinal(sheet.semester) }}</dd></div>
            <div><dt class="text-label">Year</dt><dd class="font-semibold text-gray-900">{{ sheet.exam_year ?? '—' }}</dd></div>
          </dl>

          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              :disabled="!sheet.pdf_url || downloading === `${sheet.id}:uploaded`"
              class="h-9 inline-flex items-center gap-2 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              @click="downloadUploaded(sheet)"
            >
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
              {{ downloading === `${sheet.id}:uploaded` ? 'Downloading…' : 'Download Uploaded File' }}
            </button>
            <button
              type="button"
              :disabled="!sheet.pdf_url || !sheet.has_evaluation || downloading === `${sheet.id}:evaluated`"
              :title="!sheet.has_evaluation ? 'This answer sheet has not been evaluated yet' : undefined"
              class="h-9 inline-flex items-center gap-2 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              @click="downloadEvaluated(sheet)"
            >
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><path d="M9 15l2 2 4-4" /></svg>
              {{ downloading === `${sheet.id}:evaluated` ? 'Preparing…' : 'Download Evaluated File' }}
            </button>
          </div>

          <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-5 gap-y-3 text-[13px] rounded-xl bg-page-bg p-4">
            <div>
              <dt class="text-label">Evaluated By</dt>
              <dd class="font-semibold text-gray-900">
                {{ sheet.evaluated_by?.name || '—' }}<span v-if="sheet.evaluated_by?.emp_code" class="text-muted font-normal"> ({{ sheet.evaluated_by.emp_code }})</span>
              </dd>
              <!-- The teacher's contact details: email, with the phone below. -->
              <template v-if="sheet.evaluated_by">
                <dd class="mt-1 flex items-center gap-1.5 text-[12px] text-gray-700 leading-snug min-w-0" title="Email"><svg class="w-3 h-3 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2" /><polyline points="22 6 12 13 2 6" /></svg><span class="truncate">{{ sheet.evaluated_by.email || '—' }}</span></dd>
                <dd class="mt-0.5 flex items-center gap-1.5 text-[12px] text-gray-700 leading-snug" title="Phone"><svg class="w-3 h-3 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" /></svg><span>{{ sheet.evaluated_by.phone_no || '—' }}</span></dd>
              </template>
            </div>
            <div><dt class="text-label">Start Time</dt><dd class="font-semibold text-gray-900">{{ formatDateTime(sheet.evaluation_start_date) }}</dd></div>
            <div><dt class="text-label">End Time</dt><dd class="font-semibold text-gray-900">{{ formatDateTime(sheet.evaluation_end_date) }}</dd></div>
            <div><dt class="text-label">Consumed Time</dt><dd class="font-semibold text-gray-900">{{ formatConsumed(sheet.consumed_time) }}</dd></div>
            <div><dt class="text-label">Marks</dt><dd class="font-semibold text-gray-900">{{ marksText(sheet) }}</dd></div>
            <div><dt class="text-label">Evaluated On</dt><dd class="font-semibold text-gray-900">{{ formatDateTime(sheet.evaluated_at) }}</dd></div>
          </dl>

          <div v-if="sheet.can_reset" class="flex justify-end">
            <button
              type="button"
              :disabled="!sheet.has_evaluation || resettingId === sheet.id"
              :title="!sheet.has_evaluation ? 'Nothing to reset — this answer sheet has not been evaluated yet' : undefined"
              class="h-10 min-w-[160px] inline-flex items-center justify-center gap-2 rounded-full bg-brand text-white text-[13px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
              @click="resetEvaluation(sheet)"
            >
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" /></svg>
              {{ resettingId === sheet.id ? 'Resetting…' : 'Reset Evaluation' }}
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
