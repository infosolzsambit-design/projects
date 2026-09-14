<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { resolveStorageUrl } from '../utils/api'
import { useToast } from '../composables/useToast'
import QuestionPaperStructureBuilder from '../components/questionPapers/QuestionPaperStructureBuilder.vue'

// Editing an *existing* saved paper's structure later (the "Edit Setup"
// list action) — fetch it by id, then hand it to the shared builder (see
// that component's own docblock) with a PUT as the submit function. All the
// actual PDF-reading/structure-editing logic lives there; this view is just
// the page chrome + data-loading around it.
const route = useRoute()
const router = useRouter()
const toast = useToast()

const paperId = computed(() => route.params.id)

const loading = ref(true)
const loadError = ref('')
const paperStatus = ref('draft')
const pdfSource = ref('')
const initialForm = ref(null)
const initialGroups = ref([])

async function loadPaper() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/question-papers/${paperId.value}`)
    const data = res.data.data
    paperStatus.value = data.status
    pdfSource.value = resolveStorageUrl(data.pdf_url)
    initialForm.value = {
      exam_year: data.exam_year,
      course_id: data.course_id,
      exam_term_id: data.exam_term_id ?? '',
      semester: data.semester,
      full_marks: data.full_marks ?? '',
      time_allotted: data.time_allotted ?? '',
    }
    initialGroups.value = data.groups ?? []
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this question paper.'
  } finally {
    loading.value = false
  }
}

onMounted(loadPaper)

async function submitFn(payload) {
  return api.put(`/question-papers/${paperId.value}`, payload)
}

function goToList() {
  router.push({ name: 'question-papers' })
}

function onSaved() {
  toast.success('Question paper setup saved successfully.')
  goToList()
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
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="8" y1="13" x2="16" y2="13" />
                <line x1="8" y1="17" x2="16" y2="17" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight flex items-center gap-2">
              Configure Question Paper
              <span
                class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold align-middle"
                :class="paperStatus === 'ready' ? 'bg-success/10 text-success' : 'bg-badge/15 text-badge'"
              >
                {{ paperStatus === 'ready' ? 'Ready' : 'Draft' }}
              </span>
            </h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">Read the PDF alongside and update its groups, questions, and marks.</p>
          </div>
        </div>
        <button
          type="button"
          class="mt-0.5 shrink-0 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="goToList"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          Back
        </button>
      </div>

      <div v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</div>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>
      <QuestionPaperStructureBuilder
        v-else
        :pdf-source="pdfSource"
        :initial-form="initialForm"
        :initial-groups="initialGroups"
        :submit-fn="submitFn"
        submit-label="Save Setup"
        @saved="onSaved"
        @cancel="goToList"
      />
    </div>
  </div>
</template>
