<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useToast } from '../composables/useToast'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import QuestionPaperStructureBuilder from '../components/questionPapers/QuestionPaperStructureBuilder.vue'

const router = useRouter()
const toast = useToast()

// A two-stage wizard, both stages in this one component instance — nothing
// is sent to the backend until the *final* Save Setup in stage 2 submits
// everything (exam details + PDF + structure) together in one request (see
// QuestionPaperStructureBuilder.vue and QuestionPaperController::store()).
// Staying in one component (rather than routing to a second page/id) is
// what lets the chosen PDF File survive the step-1-to-step-2 transition
// without ever touching the server — a route change can't carry a raw File
// through its params, but a local ref living across a `stage` flip can.
const stage = ref('details') // 'details' | 'structure'

// --- Stage 1: exam details + PDF picker ---
const form = reactive({
  exam_year: new Date().getFullYear(),
  course_id: '',
  exam_term_id: '',
  semester: '',
})
const pdfFile = ref(null)
const pdfFileName = ref('')
const preparingPdf = ref(false)

const fieldErrors = reactive({
  exam_year: '',
  course_id: '',
  exam_term_id: '',
  semester: '',
  pdf: '',
})
const FIELD_ORDER = ['exam_year', 'course_id', 'exam_term_id', 'semester', 'pdf']
const fieldRefs = {
  exam_year: ref(null),
  course_id: ref(null),
  exam_term_id: ref(null),
  semester: ref(null),
  pdf: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

const availableCourses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  try {
    const res = await api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    // SearchableSelect just renders each option's own `name` — appending
    // the code here (rather than changing SearchableSelect itself) keeps
    // this specific to this one dropdown.
    availableCourses.value = res.data.data.map((course) => ({
      ...course,
      name: course.code ? `${course.name} (${course.code})` : course.name,
    }))
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load courses.'
  } finally {
    coursesLoading.value = false
  }
}
loadCourses()

const availableExamTerms = ref([])
const examTermsLoading = ref(true)
const examTermsError = ref('')

async function loadExamTerms() {
  examTermsLoading.value = true
  examTermsError.value = ''
  try {
    const res = await api.get('/exam-terms', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTerms.value = res.data.data
  } catch (err) {
    examTermsError.value = err.response?.data?.message || 'Could not load exam terms.'
  } finally {
    examTermsLoading.value = false
  }
}
loadExamTerms()

function onPdfChange(event) {
  const file = event.target.files?.[0] || null
  clearFieldError('pdf')
  pdfFile.value = file
  pdfFileName.value = file?.name || ''
}

function validate() {
  if (!form.exam_year || String(form.exam_year).length !== 4) fieldErrors.exam_year = 'Enter a valid 4-digit exam year.'
  if (!form.course_id) fieldErrors.course_id = 'Course is required.'
  if (!form.exam_term_id) fieldErrors.exam_term_id = 'Exam term is required.'
  if (!form.semester) fieldErrors.semester = 'Semester is required.'
  else if (!Number.isInteger(Number(form.semester)) || Number(form.semester) < 1) fieldErrors.semester = 'Enter a valid semester number.'
  if (!pdfFile.value) fieldErrors.pdf = 'Please choose the question paper PDF to upload.'
  else if (pdfFile.value.type && pdfFile.value.type !== 'application/pdf') fieldErrors.pdf = 'Only PDF files are allowed.'

  return FIELD_ORDER.every((key) => !fieldErrors[key])
}

// --- Transition to stage 2 — purely local, no network call. The PDF's
// bytes are read into memory up front (needed synchronously once the
// builder mounts and calls loadPdf()) rather than lazily, so the builder
// never has to know its pdfSource might still be "loading". ---
const pdfSourceForBuilder = ref(null)

// Stage 2's structureInitialForm is *props for a `defineProps` in a child
// component*, so it must be built fresh right when we hand off to it — a
// `reactive({...form})` declared once at setup time would copy today's
// (still-empty) field values and never see form.course_id/semester change
// after the person actually fills the stage-1 form.
const structureInitialForm = ref(null)

async function proceedToStructure() {
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
  if (!validate()) {
    focusFirstError()
    return
  }

  preparingPdf.value = true
  try {
    pdfSourceForBuilder.value = { data: await pdfFile.value.arrayBuffer() }
    structureInitialForm.value = {
      exam_year: form.exam_year,
      course_id: form.course_id,
      exam_term_id: form.exam_term_id,
      semester: form.semester,
      full_marks: '',
      time_allotted: '',
    }
    stage.value = 'structure'
  } finally {
    preparingPdf.value = false
  }
}

// --- Stage 2: PDF preview + structure, then the one combined create call ---

async function submitFn(payload) {
  const body = new FormData()
  body.append('exam_year', payload.exam_year)
  body.append('course_id', payload.course_id)
  body.append('exam_term_id', payload.exam_term_id)
  body.append('semester', payload.semester)
  if (payload.full_marks !== null) body.append('full_marks', payload.full_marks)
  if (payload.time_allotted !== null) body.append('time_allotted', payload.time_allotted)
  body.append('pdf', pdfFile.value)
  body.append('groups', JSON.stringify(payload.groups))
  return api.post('/question-papers', body)
}

function onSaved() {
  toast.success('Question paper created successfully.')
  router.push({ name: 'question-papers' })
}

function goToList() {
  router.push({ name: 'question-papers' })
}

// The page header's Back button steps back one stage (keeping the chosen
// PDF) rather than abandoning everything — that's what the structure
// builder's own Cancel button (bottom of stage 2's form) is for.
function goBack() {
  if (stage.value === 'structure') {
    stage.value = 'details'
  } else {
    goToList()
  }
}

// Stage 1 is a simple two-field form (kept at its original, narrower
// width); stage 2 needs the wider two-column PDF-preview + builder layout.
const wrapperMaxWidth = computed(() => (stage.value === 'details' ? 'max-w-[900px]' : 'max-w-[1500px]'))
</script>

<template>
  <div>
    <div :class="wrapperMaxWidth" class="mx-auto">
      <!-- Page header, matching designed_files/form.html's icon-badge + title header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="17 8 12 3 7 8" />
                <line x1="12" y1="3" x2="12" y2="15" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Setup Question Paper</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ stage === 'details' ? 'Step 1 of 2 — choose the PDF. You\'ll set up its questions and marks next.' : 'Step 2 of 2 — read the PDF alongside and set up its groups, questions, and marks.' }}
            </p>
          </div>
        </div>
        <button
          type="button"
          class="mt-0.5 shrink-0 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="goBack"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          Back
        </button>
      </div>

      <!-- Stage 1: exam details + PDF picker — nothing is sent anywhere yet -->
      <form v-if="stage === 'details'" class="space-y-4" @submit.prevent="proceedToStructure">
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Exam Details</h2>
          <p class="text-[13px] text-muted mb-3">Which exam is this paper for?</p>

          <div class="grid grid-cols-1 md:grid-cols-[0.8fr_1.8fr_0.8fr_0.8fr] gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="exam_year" class="text-[13px] text-label">Exam Year <span class="text-brand">*</span></label>
              <input
                id="exam_year"
                :ref="(el) => (fieldRefs.exam_year.value = el)"
                v-model="form.exam_year"
                type="number"
                placeholder="e.g. 2025"
                autofocus
                @input="clearFieldError('exam_year')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.exam_year" class="text-[12px] text-brand">{{ fieldErrors.exam_year }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="course" class="text-[13px] text-label">Course <span class="text-brand">*</span></label>
              <SearchableSelect
                id="course"
                :ref="(el) => (fieldRefs.course_id.value = el)"
                v-model="form.course_id"
                :options="availableCourses"
                :loading="coursesLoading"
                :error="!!fieldErrors.course_id"
                placeholder="Select course"
                search-placeholder="Search courses…"
                @change="clearFieldError('course_id')"
              />
              <p v-if="coursesError" class="text-[12px] text-brand">{{ coursesError }}</p>
              <p v-else-if="fieldErrors.course_id" class="text-[12px] text-brand">{{ fieldErrors.course_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="exam_term" class="text-[13px] text-label">Exam Term <span class="text-brand">*</span></label>
              <SearchableSelect
                id="exam_term"
                :ref="(el) => (fieldRefs.exam_term_id.value = el)"
                v-model="form.exam_term_id"
                :options="availableExamTerms"
                :loading="examTermsLoading"
                :error="!!fieldErrors.exam_term_id"
                placeholder="Select exam term"
                search-placeholder="Search exam terms…"
                @change="clearFieldError('exam_term_id')"
              />
              <p v-if="examTermsError" class="text-[12px] text-brand">{{ examTermsError }}</p>
              <p v-else-if="fieldErrors.exam_term_id" class="text-[12px] text-brand">{{ fieldErrors.exam_term_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="semester" class="text-[13px] text-label">Semester <span class="text-brand">*</span></label>
              <input
                id="semester"
                :ref="(el) => (fieldRefs.semester.value = el)"
                v-model="form.semester"
                type="number"
                min="1"
                step="1"
                placeholder="e.g. 5"
                @input="clearFieldError('semester')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.semester ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.semester" class="text-[12px] text-brand">{{ fieldErrors.semester }}</p>
            </div>
          </div>
        </section>

        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Question Paper PDF</h2>
          <p class="text-[13px] text-muted mb-3">PDF only. You'll be able to view it side-by-side while setting up its questions.</p>

          <div class="flex flex-col gap-1.5">
            <label for="pdf" class="text-[13px] text-label">Upload PDF <span class="text-brand">*</span></label>
            <input
              id="pdf"
              :ref="(el) => (fieldRefs.pdf.value = el)"
              type="file"
              accept="application/pdf,.pdf"
              class="form-file w-full text-sm text-gray-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-soft file:text-brand-blue hover:file:bg-brand-blue/10 cursor-pointer"
              @change="onPdfChange"
            />
            <p v-if="pdfFileName" class="text-[12px] text-muted">Selected: {{ pdfFileName }}</p>
            <p v-if="fieldErrors.pdf" class="text-[12px] text-brand">{{ fieldErrors.pdf }}</p>
          </div>
        </section>

        <div class="flex flex-wrap gap-3 justify-center sm:justify-end pb-2">
          <button
            type="button"
            class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="preparingPdf"
            @click="goToList"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="min-w-[160px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="preparingPdf"
          >
            {{ preparingPdf ? 'Preparing…' : 'Setup' }}
          </button>
        </div>
      </form>

      <!-- Stage 2: PDF preview + structure builder — nothing saved until this submits -->
      <QuestionPaperStructureBuilder
        v-else
        :pdf-source="pdfSourceForBuilder"
        :initial-form="structureInitialForm"
        :initial-groups="[]"
        :submit-fn="submitFn"
        submit-label="Save Setup"
        @saved="onSaved"
        @cancel="goToList"
      />
    </div>
  </div>
</template>
