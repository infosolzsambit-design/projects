<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { resolveStorageUrl } from '../utils/api'
import { useToast } from '../composables/useToast'
import QuestionPaperStructureBuilder from '../components/questionPapers/QuestionPaperStructureBuilder.vue'
import SearchableMultiSelect from '../components/common/SearchableMultiSelect.vue'

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
// Backend re-checks this too (PUT rejects with a 422 once true — see
// QuestionPaperController::hasStartedEvaluation()); this is the same
// check surfaced before the editable builder ever mounts, so a direct
// visit to this URL (bookmark, back button) can't get past the "locked"
// notice the list/view pages already show for this same paper.
const evaluationStarted = ref(false)
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
    evaluationStarted.value = !!data.evaluation_started
    pdfSource.value = resolveStorageUrl(data.pdf_url)
    initialForm.value = {
      exam_year: data.exam_year,
      // Papers set up before Department existed have none yet — required now.
      department_ids: data.department_ids ?? [],
      course_id: data.course_id,
      exam_term_id: data.exam_term_id ?? '',
      semester: data.semester,
      full_marks: data.full_marks ?? '',
      time_allotted: data.time_allotted ?? '',
    }
    initialGroups.value = data.groups ?? []
    lockedDepartmentIds.value = data.department_ids ?? []
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this question paper.'
  } finally {
    loading.value = false
  }
}

onMounted(loadPaper)

// Locked papers (evaluation started) can't change their structure, but the
// Department tag can still be set/changed — it's just a label.
const lockedDepartmentIds = ref([])
const lockedDepartmentError = ref('')
const savingDepartment = ref(false)
const availableDepartments = ref([])
const departmentsLoading = ref(true)
async function loadDepartments() {
  departmentsLoading.value = true
  try {
    const res = await api.get('/departments', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    availableDepartments.value = res.data.data.map((d) => ({ ...d, name: d.code ? `${d.name} (${d.code})` : d.name }))
  } catch {
    availableDepartments.value = []
  } finally {
    departmentsLoading.value = false
  }
}
onMounted(loadDepartments)

async function saveLockedDepartment() {
  lockedDepartmentError.value = ''
  if (!lockedDepartmentIds.value.length) {
    lockedDepartmentError.value = 'Select at least one department.'
    return
  }
  savingDepartment.value = true
  try {
    await api.patch(`/question-papers/${paperId.value}/departments`, { department_ids: lockedDepartmentIds.value })
    toast.success('Departments updated successfully.')
  } catch (err) {
    lockedDepartmentError.value = err.response?.data?.errors?.department_ids?.[0] || err.response?.data?.message || 'Could not update the departments.'
  } finally {
    savingDepartment.value = false
  }
}

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
      <div v-else-if="evaluationStarted" class="bg-white rounded-2xl shadow-panel p-10 text-center">
        <p class="text-[15px] font-semibold text-gray-900">This question paper's structure is locked.</p>
        <p class="mt-1 text-[13px] text-muted">A teacher has already started evaluating an answer sheet mapped to it, so it can no longer be edited.</p>

        <!-- The department tags can still be changed. -->
        <div class="mt-6 mx-auto max-w-[460px] text-left rounded-2xl border border-soft bg-page-bg/60 p-4">
          <label for="locked_department" class="text-[13px] text-label">Departments <span class="text-brand">*</span></label>
          <p class="text-[12px] text-muted mb-2">These can still be set or changed — they don't affect the questions or marks.</p>
          <div class="flex items-start gap-2">
            <div class="flex-1 min-w-0">
              <SearchableMultiSelect
                id="locked_department"
                v-model="lockedDepartmentIds"
                :options="availableDepartments"
                :loading="departmentsLoading"
                :error="!!lockedDepartmentError"
                placeholder="Select one or more departments"
                search-placeholder="Search departments…"
                @change="lockedDepartmentError = ''"
              />
            </div>
            <button
              type="button"
              class="h-10 shrink-0 inline-flex items-center rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60"
              :disabled="savingDepartment"
              @click="saveLockedDepartment"
            >
              {{ savingDepartment ? 'Saving…' : 'Save' }}
            </button>
          </div>
          <p v-if="lockedDepartmentError" class="mt-1 text-[12px] text-brand">{{ lockedDepartmentError }}</p>
        </div>

        <button type="button" class="mt-4 h-9 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="goToList">
          Back to Question Papers
        </button>
      </div>
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
