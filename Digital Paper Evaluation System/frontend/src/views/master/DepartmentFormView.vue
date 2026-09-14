<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const departmentId = computed(() => route.params.id || null)
const isEdit = computed(() => !!departmentId.value)

const form = reactive({
  name: '',
  code: '',
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const fieldErrors = reactive({
  name: '',
  code: '',
})
// Order matters — the first of these (in this order) that has an error is
// the one that gets focused after a failed validate()/submit.
const FIELD_ORDER = ['name', 'code']
const fieldRefs = {
  name: ref(null),
  code: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

// Snapshot Reset restores to — the blank defaults above for create, or
// whatever was actually loaded for edit (captured once loadDepartment()
// finishes below). Not reactive on purpose — it's a fixed target, not
// something that should track the form's own live edits.
let initialForm = { ...form }

async function loadDepartment() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/departments/${departmentId.value}`)
    const department = res.data.data
    form.name = department.name
    form.code = department.code || ''
    initialForm = { ...form }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this department.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, initialForm)
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  if (isEdit.value) loadDepartment()
})

// Applies the backend's per-field validation errors (422 → { errors: {
// field: [messages] } }) the same way client-side validation does — under
// the matching field, red border and all — instead of one generic banner.
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
  formError.value = data?.message || 'Could not save department.'
}

// Replaces the native `required` attribute — styled error + red border
// instead of a browser validation bubble, like the rest of this app's forms.
function validate() {
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
      await api.put(`/departments/${departmentId.value}`, payload)
    } else {
      await api.post('/departments', payload)
    }
    router.push({ name: 'master-departments' })
    toast.success(isEdit.value ? 'Department updated successfully.' : 'Department created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'master-departments' })
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
                <ellipse cx="12" cy="5" rx="9" ry="3" />
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" />
                <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Department' : 'Add Department' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this department's details." : 'Create a new department for the system.' }}
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
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Department Details</h2>
          <p class="text-[13px] text-muted mb-3">Name is required and must be unique. Code is optional.</p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. Computer Science Engineering"
                autofocus
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="code" class="text-[13px] text-label">Code</label>
              <input
                id="code"
                :ref="(el) => (fieldRefs.code.value = el)"
                v-model="form.code"
                type="text"
                placeholder="e.g. CSE"
                @input="clearFieldError('code')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.code ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.code" class="text-[12px] text-brand">{{ fieldErrors.code }}</p>
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
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Department' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
