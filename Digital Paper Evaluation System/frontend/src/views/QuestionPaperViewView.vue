<script setup>
// Read-only "View" for a question paper (the list's action-menu "View"
// item) — the PDF alongside its saved structure, with no inputs, no drag
// handles, and no save button, so someone can look at a finished setup
// without any risk of nudging it. QuestionPaperConfigureView.vue is the
// editable counterpart; this deliberately doesn't reuse it (or
// QuestionPaperStructureBuilder.vue) since making that component
// conditionally read-only would mean threading a `readonly` flag through
// every input/button in it — a dedicated pair of small components
// (QuestionNodeViewer.vue here for the tree) stays far simpler than that.
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { resolveStorageUrl } from '../utils/api'
import { loadPdf, renderPageToCanvas } from '../utils/pdf'
import QuestionNodeViewer from '../components/questionPapers/QuestionNodeViewer.vue'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const paperId = route.params.id

const loading = ref(true)
const loadError = ref('')
const paper = ref(null)

const pdfLoading = ref(true)
const pdfError = ref('')
const pageCount = ref(0)
const canvasEls = ref({})
function setCanvasEl(page, el) {
  if (el) canvasEls.value[page] = el
}

async function loadPage() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/question-papers/${paperId}`)
    paper.value = { ...res.data.data, pdf_url: resolveStorageUrl(res.data.data.pdf_url) }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this question paper.'
  } finally {
    loading.value = false
  }
}

async function loadPdfPreview() {
  if (!paper.value?.pdf_url) {
    pdfLoading.value = false
    return
  }
  try {
    const pdfDoc = await loadPdf(paper.value.pdf_url)
    pageCount.value = pdfDoc.numPages
    pdfLoading.value = false
    await new Promise((resolve) => requestAnimationFrame(resolve)) // let the v-for canvases mount
    for (let page = 1; page <= pdfDoc.numPages; page++) {
      const canvas = canvasEls.value[page]
      if (canvas) await renderPageToCanvas(pdfDoc, page, canvas, { scale: 1.3 })
    }
  } catch (err) {
    pdfError.value = err.message || 'Could not load this PDF.'
    pdfLoading.value = false
  }
}

onMounted(async () => {
  await loadPage()
  if (paper.value) await loadPdfPreview()
})

function goToList() {
  router.push({ name: 'question-papers' })
}
function goToConfigure() {
  router.push({ name: 'question-papers-configure', params: { id: paperId } })
}
</script>

<template>
  <div>
    <div class="max-w-[1500px] mx-auto">
      <!-- Page header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight flex items-center gap-2">
              View Question Paper
              <span
                v-if="paper"
                class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold align-middle"
                :class="paper.status === 'ready' ? 'bg-success/10 text-success' : 'bg-badge/15 text-badge'"
              >
                {{ paper.status === 'ready' ? 'Ready' : 'Draft' }}
              </span>
              <span
                v-if="paper?.evaluation_started"
                class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-gray-600 px-2.5 py-1 text-[10px] font-semibold align-middle"
                title="A teacher has already started evaluating a sheet mapped to this paper — the structure is locked."
              >
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                Locked
              </span>
            </h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ paper?.evaluation_started ? 'Read-only — locked because a teacher has already started evaluating a mapped sheet.' : 'Read-only — use Edit Setup to make changes.' }}
            </p>
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button
            v-if="authStore.can('question-paper-edit')"
            type="button"
            class="mt-0.5 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:border-input-border disabled:hover:text-gray-700"
            :disabled="paper?.evaluation_started"
            :title="paper?.evaluation_started ? 'Locked — a teacher has already started evaluating a sheet mapped to this paper.' : ''"
            @click="goToConfigure"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" /></svg>
            Edit Setup
          </button>
          <button
            type="button"
            class="mt-0.5 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
            @click="goToList"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
            Back
          </button>
        </div>
      </div>

      <div v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</div>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

      <template v-else>
        <!-- Paper details strip -->
        <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-3.5 mb-4 grid grid-cols-2 sm:grid-cols-5 gap-3 text-[13px]">
          <div>
            <p class="text-muted text-[11px] mb-0.5">Exam Year</p>
            <p class="font-semibold text-gray-800">{{ paper.exam_year }}</p>
          </div>
          <div>
            <p class="text-muted text-[11px] mb-0.5">Course</p>
            <p class="font-semibold text-gray-800">{{ paper.course_name || '—' }}<span v-if="paper.course_code" class="text-muted font-normal"> ({{ paper.course_code }})</span></p>
          </div>
          <div>
            <p class="text-muted text-[11px] mb-0.5">Semester</p>
            <p class="font-semibold text-gray-800">{{ paper.semester }}</p>
          </div>
          <div>
            <p class="text-muted text-[11px] mb-0.5">Full Marks</p>
            <p class="font-semibold text-gray-800">{{ paper.full_marks ?? '—' }}</p>
          </div>
          <div>
            <p class="text-muted text-[11px] mb-0.5">Time Allotted</p>
            <p class="font-semibold text-gray-800">{{ paper.time_allotted ?? '—' }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-4 items-start">
          <!-- PDF preview -->
          <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5 lg:sticky lg:top-6 lg:max-h-[calc(100vh-110px)] overflow-y-auto">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-3">Question Paper PDF</h2>
            <p v-if="pdfLoading" class="text-sm text-muted text-center py-8">Loading PDF&hellip;</p>
            <p v-else-if="pdfError" class="text-sm text-brand text-center py-8">{{ pdfError }}</p>
            <div v-else class="flex flex-col gap-3">
              <div v-for="page in pageCount" :key="page" class="rounded-2xl border border-input-border overflow-hidden bg-page-bg">
                <canvas :ref="(el) => setCanvasEl(page, el)" class="w-full h-auto block"></canvas>
              </div>
            </div>
          </section>

          <!-- Structure -->
          <div class="flex flex-col gap-4">
            <QuestionNodeViewer v-for="group in paper.groups" :key="group.id" :node="group" :depth="0" />
            <p v-if="!paper.groups?.length" class="text-center text-sm text-muted py-10 bg-white rounded-2xl shadow-panel">No structure has been set up for this paper yet.</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
