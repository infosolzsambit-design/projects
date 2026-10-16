<script setup>
// Opened from GenerateQrView.vue's "Generate QR Code" — what the QR codes
// will be made for: the search the list came from (read-only, by name),
// how many students it has, and the fields to fill in — Department, Question
// Paper, Exam Year, Batch and Packet Code, all required (Department / Exam
// Year / Batch pre-filled from the search or the list). Generate posts to
// /qr/generate (QrCodeController::generate()), which saves the packet and
// one answer sheet per student, each with its own QR number.
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '../../utils/api'
import SearchableSelect from '../common/SearchableSelect.vue'

const props = defineProps({
  params: { type: Array, required: true }, // read-only [{ label, value }]
  // The search's own params, as Ref Ids: { exam_term_id, exam_type_id, enroll_session_id, program_id, course_id, semester }
  searchRefs: { type: Object, required: true },
  studentCount: { type: Number, required: true },
  departments: { type: Array, default: () => [] }, // [{ id (Ref Id), name }]
  departmentsLoading: { type: Boolean, default: false },
  initial: { type: Object, default: () => ({}) }, // { department_id, exam_year, batch }
})
const emit = defineEmits(['close', 'generated'])

const form = reactive({
  department_id: props.initial.department_id || '',
  question_paper_id: '',
  exam_year: props.initial.exam_year || '',
  batch: props.initial.batch || '',
  packet_code: '',
})
const FIELDS = ['department_id', 'question_paper_id', 'exam_year', 'batch', 'packet_code']
const fieldErrors = reactive(Object.fromEntries(FIELDS.map((key) => [key, ''])))
const formError = ref('')
const generating = ref(false)

// Question papers: ready ones for this course, semester and exam term,
// tagged with the chosen department — so they reload when it changes.
const questionPapers = ref([])
const papersLoading = ref(false)
let papersRequest = 0
async function loadQuestionPapers() {
  form.question_paper_id = ''
  questionPapers.value = []
  if (!form.department_id) return
  const request = ++papersRequest
  papersLoading.value = true
  try {
    const res = await api.get('/qr/question-papers', {
      params: {
        course_id: props.searchRefs.course_id,
        semester: props.searchRefs.semester,
        exam_term_id: props.searchRefs.exam_term_id,
        department_id: form.department_id,
      },
      skipLoader: true,
    })
    if (request !== papersRequest) return
    questionPapers.value = res.data.data || []
    // Only one to choose from → pick it.
    if (questionPapers.value.length === 1) form.question_paper_id = questionPapers.value[0].id
  } catch {
    // Non-fatal — the dropdown just stays empty.
  } finally {
    if (request === papersRequest) papersLoading.value = false
  }
}
watch(() => form.department_id, () => {
  fieldErrors.department_id = ''
  loadQuestionPapers()
})
onMounted(loadQuestionPapers)

function validate() {
  fieldErrors.department_id = form.department_id ? '' : 'Select a department.'
  fieldErrors.question_paper_id = form.question_paper_id ? '' : form.department_id ? 'Select a question paper.' : 'Select a department first.'
  fieldErrors.exam_year = form.exam_year.trim() ? '' : 'Enter the exam year.'
  fieldErrors.batch = form.batch.trim() ? '' : 'Enter the batch.'
  fieldErrors.packet_code = form.packet_code.trim() ? '' : 'Enter the packet code.'
  return FIELDS.every((key) => !fieldErrors[key])
}

async function generate() {
  formError.value = ''
  if (!validate()) return
  generating.value = true
  try {
    const res = await api.post('/qr/generate', {
      ...props.searchRefs,
      department_id: form.department_id,
      question_paper_id: form.question_paper_id,
      exam_year: form.exam_year.trim(),
      batch: form.batch.trim(),
      packet_code: form.packet_code.trim(),
    })
    emit('generated', { ...res.data.data, message: res.data.message })
  } catch (err) {
    const errors = err.response?.data?.errors
    if (errors) Object.entries(errors).forEach(([key, messages]) => { if (key in fieldErrors) fieldErrors[key] = messages[0] })
    // Errors on the search's own params (not fields here) go in the banner.
    const otherErrors = errors ? Object.entries(errors).filter(([key]) => !(key in fieldErrors)).map(([, messages]) => messages[0]) : []
    formError.value = otherErrors.join(' ') || (errors ? '' : err.response?.data?.message || 'Could not generate the QR codes.')
  } finally {
    generating.value = false
  }
}

function close() {
  if (!generating.value) emit('close')
}

// Esc closes the modal — unless it was meant for an open dropdown inside it
// (SearchableSelect handles that Esc itself and marks it defaultPrevented).
function onKeydown(event) {
  if (event.key === 'Escape' && !event.defaultPrevented) close()
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))

const inputClass = 'w-full h-10 px-3 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition'
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-lg max-h-[90vh] flex flex-col" role="dialog" aria-labelledby="generate-qr-title">
      <!-- Header -->
      <div class="flex items-start justify-between gap-3 px-6 pt-6 pb-4 border-b border-soft">
        <div class="min-w-0">
          <h2 id="generate-qr-title" class="text-lg font-bold text-black">Generate QR Code</h2>
          <p class="text-[13px] text-muted mt-0.5">QR codes will be generated for the students of this search.</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="generating" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <!-- Body (not overflow-hidden on the card, so the dropdowns can open over the footer) -->
      <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
        <div class="rounded-2xl bg-soft px-4 py-3 flex items-center justify-between gap-3">
          <span class="text-[13px] font-semibold text-gray-700">Total Students</span>
          <span class="text-[22px] font-bold text-brand-blue leading-none">{{ studentCount }}</span>
        </div>

        <dl class="rounded-2xl border border-soft divide-y divide-soft">
          <div v-for="param in params" :key="param.label" class="grid grid-cols-[130px_1fr] gap-3 px-4 py-2 text-[12.5px]">
            <dt class="text-muted">{{ param.label }}</dt>
            <dd class="text-gray-800 font-medium break-words">{{ param.value || '—' }}</dd>
          </div>
        </dl>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="flex flex-col gap-1 sm:col-span-2">
            <label for="qr_modal_department" class="text-[12px] text-label">Department <span class="text-brand">*</span></label>
            <SearchableSelect
              id="qr_modal_department"
              v-model="form.department_id"
              :options="departments"
              :loading="departmentsLoading"
              :error="!!fieldErrors.department_id"
              wrap-options
              placeholder="Select department"
              search-placeholder="Search departments…"
            />
            <p v-if="fieldErrors.department_id" class="text-[11px] text-brand">{{ fieldErrors.department_id }}</p>
          </div>
          <div class="flex flex-col gap-1 sm:col-span-2">
            <label for="qr_modal_question_paper" class="text-[12px] text-label">Question Paper <span class="text-brand">*</span></label>
            <SearchableSelect
              id="qr_modal_question_paper"
              v-model="form.question_paper_id"
              :options="questionPapers"
              :loading="papersLoading"
              :disabled="!form.department_id"
              :error="!!fieldErrors.question_paper_id"
              wrap-options
              :placeholder="!form.department_id ? 'Select a department first' : questionPapers.length || papersLoading ? 'Select question paper' : 'No ready question paper for this course, semester and department'"
              search-placeholder="Search question papers…"
              @change="fieldErrors.question_paper_id = ''"
            />
            <p v-if="fieldErrors.question_paper_id" class="text-[11px] text-brand">{{ fieldErrors.question_paper_id }}</p>
          </div>
          <div class="flex flex-col gap-1">
            <label for="qr_modal_exam_year" class="text-[12px] text-label">Exam Year <span class="text-brand">*</span></label>
            <input
              id="qr_modal_exam_year"
              v-model="form.exam_year"
              type="text"
              maxlength="20"
              placeholder="e.g. 2026-2027"
              :class="[inputClass, fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue']"
              @input="fieldErrors.exam_year = ''"
            />
            <p v-if="fieldErrors.exam_year" class="text-[11px] text-brand">{{ fieldErrors.exam_year }}</p>
          </div>
          <div class="flex flex-col gap-1">
            <label for="qr_modal_batch" class="text-[12px] text-label">Batch <span class="text-brand">*</span></label>
            <input
              id="qr_modal_batch"
              v-model="form.batch"
              type="text"
              inputmode="numeric"
              maxlength="20"
              placeholder="e.g. 2025"
              :class="[inputClass, fieldErrors.batch ? 'border-brand' : 'border-input-border focus:border-brand-blue']"
              @input="fieldErrors.batch = ''"
            />
            <p v-if="fieldErrors.batch" class="text-[11px] text-brand">{{ fieldErrors.batch }}</p>
          </div>
          <div class="flex flex-col gap-1 sm:col-span-2">
            <label for="qr_modal_packet_code" class="text-[12px] text-label">Packet Code <span class="text-brand">*</span></label>
            <input
              id="qr_modal_packet_code"
              v-model="form.packet_code"
              type="text"
              maxlength="255"
              placeholder="e.g. PKT-001"
              :class="[inputClass, fieldErrors.packet_code ? 'border-brand' : 'border-input-border focus:border-brand-blue']"
              @input="fieldErrors.packet_code = ''"
            />
            <p v-if="fieldErrors.packet_code" class="text-[11px] text-brand">{{ fieldErrors.packet_code }}</p>
          </div>
        </div>

        <div v-if="formError" class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-[13px] text-brand">{{ formError }}</div>
      </div>

      <!-- Footer -->
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-soft">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
          :disabled="generating"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="generating"
          @click="generate"
        >
          {{ generating ? 'Generating…' : 'Generate' }}
        </button>
      </div>
    </div>
  </div>
</template>
