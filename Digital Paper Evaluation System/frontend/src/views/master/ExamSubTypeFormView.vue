<script setup>
// Clone of ExamTypeFormView.vue for the Exam Sub Type master — plus the
// Exam Term and Exam Type it belongs to (searchable dropdowns, like the
// Department one on ProgramFormView.vue).
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'
import SearchableSelect from '../../components/common/SearchableSelect.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const examSubTypeId = computed(() => route.params.id || null)
const isEdit = computed(() => !!examSubTypeId.value)

const form = reactive({
  exam_term_id: '',
  exam_type_id: '',
  name: '',
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const fieldErrors = reactive({
  exam_term_id: '',
  exam_type_id: '',
  name: '',
})
// Order matters — the first of these (in this order) that has an error is
// the one that gets focused after a failed validate()/submit.
const FIELD_ORDER = ['exam_term_id', 'exam_type_id', 'name']
const fieldRefs = {
  exam_term_id: ref(null),
  exam_type_id: ref(null),
  name: ref(null),
}

// Active exam terms / exam types for the dropdowns.
const examTerms = ref([])
const examTypes = ref([])
const listsLoading = ref(true)
const listsError = ref('')

async function loadLists() {
  listsLoading.value = true
  listsError.value = ''
  try {
    const params = { status: 'all', is_active: 'yes', table_fields: ['name'], sort_by_field: 'name', sort_by: 'asc' }
    const [terms, types] = await Promise.all([api.get('/exam-terms', { params }), api.get('/exam-types', { params })])
    examTerms.value = terms.data.data
    examTypes.value = types.data.data
  } catch (err) {
    listsError.value = err.response?.data?.message || 'Could not load exam terms and exam types.'
  } finally {
    listsLoading.value = false
  }
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus?.()
}

// Snapshot Reset restores to — the blank defaults above for create, or
// whatever was actually loaded for edit.
let initialForm = { ...form }

// The saved term/type may since have been made inactive — keep it in its
// dropdown so the edit form still shows it.
function keepOption(list, id, name) {
  if (id && name && !list.value.some((o) => String(o.id) === String(id))) list.value = [{ id, name }, ...list.value]
}

async function loadExamSubType() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/exam-sub-types/${examSubTypeId.value}`)
    const examSubType = res.data.data
    form.exam_term_id = examSubType.exam_term_id || ''
    form.exam_type_id = examSubType.exam_type_id || ''
    form.name = examSubType.name
    initialForm = { ...form }
    return examSubType
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this exam sub type.'
    return null
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, initialForm)
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(async () => {
  const [examSubType] = await Promise.all([isEdit.value ? loadExamSubType() : null, loadLists()])
  if (examSubType) {
    keepOption(examTerms, examSubType.exam_term_id, examSubType.exam_term)
    keepOption(examTypes, examSubType.exam_type_id, examSubType.exam_type)
  }
})

// Applies the backend's per-field validation errors under the matching field.
function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([field, messages]) => {
      if (field in fieldErrors) fieldErrors[field] = messages[0]
    })
    if (FIELD_ORDER.some((key) => fieldErrors[key])) {
      focusFirstError()
      return
    }
  }
  formError.value = data?.message || 'Could not save exam sub type.'
}

function validate() {
  if (!form.exam_term_id) fieldErrors.exam_term_id = 'Exam term is required.'
  if (!form.exam_type_id) fieldErrors.exam_type_id = 'Exam type is required.'
  if (!form.name.trim()) fieldErrors.name = 'Name is required.'
  return FIELD_ORDER.every((key) => !fieldErrors[key])
}

async function submitForm() {
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))

  if (!validate()) {
    focusFirstError()
    return
  }

  saving.value = true
  try {
    const payload = { ...form }
    if (isEdit.value) {
      await api.put(`/exam-sub-types/${examSubTypeId.value}`, payload)
    } else {
      await api.post('/exam-sub-types', payload)
    }
    router.push({ name: 'master-exam-sub-types' })
    toast.success(isEdit.value ? 'Exam sub type updated successfully.' : 'Exam sub type created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'master-exam-sub-types' })
}
</script>

<template>
  <div>
    <div class="max-w-[1100px] mx-auto">
      <!-- Page header, matching designed_files/form.html's icon-badge + title header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="4" width="18" height="18" rx="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Exam Sub Type' : 'Add Exam Sub Type' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this exam sub type's details." : 'Create a new exam sub type for the system.' }}
            </p>
          </div>
        </div>
        <button
          type="button"
          class="mt-0.5 shrink-0 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="cancel"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          Back
        </button>
      </div>

      <p v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

      <form v-else class="space-y-4" @submit.prevent="submitForm">
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Exam Sub Type Details</h2>
          <p class="text-[13px] text-muted mb-3">All fields are required. The name must be unique within the same exam term and exam type.</p>
          <p v-if="listsError" class="text-[12px] text-brand mb-3">{{ listsError }}</p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="exam_term" class="text-[13px] text-label">Exam Term <span class="text-brand">*</span></label>
              <SearchableSelect
                id="exam_term"
                :ref="(el) => (fieldRefs.exam_term_id.value = el)"
                v-model="form.exam_term_id"
                :options="examTerms"
                :loading="listsLoading"
                :error="!!fieldErrors.exam_term_id"
                placeholder="Select exam term"
                search-placeholder="Search exam terms…"
                @change="clearFieldError('exam_term_id')"
              />
              <p v-if="fieldErrors.exam_term_id" class="text-[12px] text-brand">{{ fieldErrors.exam_term_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="exam_type" class="text-[13px] text-label">Exam Type <span class="text-brand">*</span></label>
              <SearchableSelect
                id="exam_type"
                :ref="(el) => (fieldRefs.exam_type_id.value = el)"
                v-model="form.exam_type_id"
                :options="examTypes"
                :loading="listsLoading"
                :error="!!fieldErrors.exam_type_id"
                placeholder="Select exam type"
                search-placeholder="Search exam types…"
                @change="clearFieldError('exam_type_id')"
              />
              <p v-if="fieldErrors.exam_type_id" class="text-[12px] text-brand">{{ fieldErrors.exam_type_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. Internal 1"
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
          </div>
        </section>

        <p v-if="formError" class="text-[13px] text-brand text-center sm:text-right">{{ formError }}</p>

        <div class="flex flex-wrap gap-3 justify-center sm:justify-end pb-2">
          <button
            type="button"
            class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="saving"
            @click="resetForm"
          >
            Reset
          </button>
          <button
            type="button"
            class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="saving"
            @click="cancel"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="min-w-[160px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Exam Sub Type' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
