<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../utils/api'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import EsignCropperModal from '../components/common/EsignCropperModal.vue'
import { PASSWORD_RULES } from '../utils/passwordRules'
import { useToast } from '../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const teacherId = computed(() => route.params.id || null)
const isEdit = computed(() => !!teacherId.value)

const form = reactive({
  name: '',
  email: '',
  phone_no: '',
  emp_code: '',
  department_id: '',
  designation: '',
  password: '',
  password_confirmation: '',
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

// Department options for the dropdown below — native <select> sourced from
// the Department master list (designed_files/form.html's "Department
// (Select)" pattern), not the jQuery Select2 the same file demos right next
// to it — this app stays jQuery-free.
const availableDepartments = ref([])
const departmentsLoading = ref(true)
const departmentsError = ref('')

async function loadDepartments() {
  departmentsLoading.value = true
  departmentsError.value = ''
  try {
    const res = await api.get('/departments', {
      params: { status: 'all', is_active: 'yes', table_fields: ['name'] },
    })
    // ?status=all (+ ?table_fields=…) returns a flat array in `data`, not
    // the paginated { items, pagination } shape the default listing uses.
    availableDepartments.value = res.data.data
  } catch (err) {
    departmentsError.value = err.response?.data?.message || 'Could not load departments.'
  } finally {
    departmentsLoading.value = false
  }
}

const fieldErrors = reactive({
  name: '',
  email: '',
  phone_no: '',
  emp_code: '',
  department_id: '',
  designation: '',
  password: '',
  password_confirmation: '',
})
// Order matters — the first of these (in this order) that has an error is
// the one that gets focused after a failed validate()/submit.
const FIELD_ORDER = ['name', 'email', 'phone_no', 'emp_code', 'department_id', 'designation', 'password', 'password_confirmation']
const fieldRefs = {
  name: ref(null),
  email: ref(null),
  phone_no: ref(null),
  emp_code: ref(null),
  department_id: ref(null),
  designation: ref(null),
  password: ref(null),
  password_confirmation: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

const showPassword = ref(false)
const showPasswordConfirm = ref(false)

// Snapshot Reset restores to — the blank defaults above for create, or
// whatever was actually loaded for edit (captured once loadTeacher()
// finishes below). Not reactive on purpose — it's a fixed target, not
// something that should track the form's own live edits.
let initialForm = { ...form }

async function loadTeacher() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/teachers/${teacherId.value}`)
    const teacher = res.data.data
    form.name = teacher.name
    form.email = teacher.email
    form.phone_no = teacher.phone_no || ''
    form.emp_code = teacher.emp_code || ''
    form.department_id = teacher.department_id || ''
    form.designation = teacher.designation || ''
    initialForm = { ...form }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this teacher.'
  } finally {
    loading.value = false
  }
  // Separate from the form load above — TeacherResource only carries a
  // cheap has_esign flag on the teacher itself (see its own docblock), the
  // actual signature image is fetched on demand here, same reasoning as
  // TeachersView.vue's own "view photo" flow for face scans.
  loadEsign()
}

// --- E-Signature — teacher-only, edit mode only (a brand-new teacher has
// no id yet to attach one to). Upload/replace reuses the same
// EsignCropperModal.vue as the Profile page's own self-service version,
// just pointed at this teacher's admin endpoint instead of the logged-in
// user's own. -----------------------------------------------------------
const esign = ref(null)
const esignLoading = ref(false)
const showEsignModal = ref(false)

async function loadEsign() {
  esignLoading.value = true
  try {
    const res = await api.get(`/teachers/${teacherId.value}/esign`)
    esign.value = res.data.data.esign
  } catch {
    // Non-fatal — the preview just stays empty; the rest of the form still works.
  } finally {
    esignLoading.value = false
  }
}

function onEsignSaved(data) {
  esign.value = data.esign
}

function resetForm() {
  Object.assign(form, initialForm)
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  loadDepartments()
  if (isEdit.value) loadTeacher()
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
  formError.value = data?.message || 'Could not save teacher.'
}

// Replaces the native `required` attribute (no browser validation bubbles;
// each field gets its own message + red border instead, like the rest of
// this app's forms) — checked before the request goes out at all.
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Strips anything but digits as the user types — blocks '.', letters, '+',
// etc. right at the source instead of only catching it on submit — and
// caps it at 10 characters so it can't even reach 11.
function sanitizePhone() {
  form.phone_no = form.phone_no.replace(/\D/g, '').slice(0, 10)
}

function validate() {
  if (!form.name.trim()) fieldErrors.name = 'Name is required.'

  if (!form.email.trim()) {
    fieldErrors.email = 'Email is required.'
  } else if (!EMAIL_PATTERN.test(form.email.trim())) {
    fieldErrors.email = 'Enter a valid email address.'
  }

  if (!form.phone_no.trim()) {
    fieldErrors.phone_no = 'Phone number is required.'
  } else if (!/^\d{10}$/.test(form.phone_no)) {
    fieldErrors.phone_no = 'Enter a valid 10-digit phone number.'
  }

  if (!form.emp_code.trim()) fieldErrors.emp_code = 'Employee code is required.'
  if (!form.department_id) fieldErrors.department_id = 'Department is required.'
  if (!form.designation.trim()) fieldErrors.designation = 'Designation is required.'

  // Password is optional in both create and edit — a teacher can be made
  // with no password set at all (see TeacherController::store()) and use
  // "Forgot password?" on first login. If one IS typed here, it has to
  // meet the same rules as everywhere else in the app.
  if (form.password) {
    const unmet = PASSWORD_RULES.filter((rule) => !rule.test(form.password))
    if (unmet.length) fieldErrors.password = `Missing: ${unmet.map((rule) => rule.label.toLowerCase()).join(', ')}.`
  }

  if (form.password && !form.password_confirmation) fieldErrors.password_confirmation = 'Please confirm the password.'
  if (form.password && form.password !== form.password_confirmation) {
    fieldErrors.password_confirmation = 'Passwords do not match.'
  }
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
    if (!payload.password) {
      delete payload.password
      delete payload.password_confirmation
    }

    if (isEdit.value) {
      await api.put(`/teachers/${teacherId.value}`, payload)
    } else {
      await api.post('/teachers', payload)
    }
    router.push({ name: 'teachers' })
    toast.success(isEdit.value ? 'Teacher updated successfully.' : 'Teacher created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'teachers' })
}
</script>

<template>
  <div>
    <!-- Breadcrumb -->
    <!-- <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <RouterLink :to="{ name: 'teachers' }" class="text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">Teacher List</RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">{{ isEdit ? 'Edit Teacher' : 'Add Teacher' }}</span>
    </div> -->

    <div class="max-w-[1100px] mx-auto">
      <!-- Page header, matching designed_files/form.html's icon-badge + title header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M22 10v6M2 10l10-5 10 5-10 5-10-5z" />
                <path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Teacher' : 'Add Teacher' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this teacher's account and profile details." : 'Create a new teacher account.' }}
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
        <!-- Personal Details -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Personal Details</h2>
          <p class="text-[13px] text-muted mb-3">Name, email and phone number for this teacher.</p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="Enter full name"
                autofocus
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="email" class="text-[13px] text-label">Email <span class="text-brand">*</span></label>
              <input
                id="email"
                :ref="(el) => (fieldRefs.email.value = el)"
                v-model="form.email"
                type="email"
                placeholder="name@example.com"
                @input="clearFieldError('email')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.email ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.email" class="text-[12px] text-brand">{{ fieldErrors.email }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="phone" class="text-[13px] text-label">Phone Number <span class="text-brand">*</span></label>
              <input
                id="phone"
                :ref="(el) => (fieldRefs.phone_no.value = el)"
                v-model="form.phone_no"
                type="tel"
                inputmode="numeric"
                maxlength="10"
                placeholder="10-digit mobile number"
                @input="
                  sanitizePhone();
                  clearFieldError('phone_no')
                "
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.phone_no ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.phone_no" class="text-[12px] text-brand">{{ fieldErrors.phone_no }}</p>
            </div>
          </div>
        </section>

        <!-- Role Details -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Role Details</h2>
          <p class="text-[13px] text-muted mb-3">Where this teacher belongs and what they're called.</p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="emp_code" class="text-[13px] text-label">Employee Code <span class="text-brand">*</span></label>
              <input
                id="emp_code"
                :ref="(el) => (fieldRefs.emp_code.value = el)"
                v-model="form.emp_code"
                type="text"
                placeholder="e.g. EMP-0001"
                @input="clearFieldError('emp_code')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.emp_code ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.emp_code" class="text-[12px] text-brand">{{ fieldErrors.emp_code }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="department" class="text-[13px] text-label">Department <span class="text-brand">*</span></label>
              <SearchableSelect
                id="department"
                :ref="(el) => (fieldRefs.department_id.value = el)"
                v-model="form.department_id"
                :options="availableDepartments"
                :loading="departmentsLoading"
                :error="!!fieldErrors.department_id"
                placeholder="Select department"
                search-placeholder="Search departments…"
                @change="clearFieldError('department_id')"
              />
              <p v-if="departmentsError" class="text-[12px] text-brand">{{ departmentsError }}</p>
              <p v-else-if="fieldErrors.department_id" class="text-[12px] text-brand">{{ fieldErrors.department_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="designation" class="text-[13px] text-label">Designation <span class="text-brand">*</span></label>
              <input
                id="designation"
                :ref="(el) => (fieldRefs.designation.value = el)"
                v-model="form.designation"
                type="text"
                placeholder="e.g. Assistant Professor"
                @input="clearFieldError('designation')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.designation ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.designation" class="text-[12px] text-brand">{{ fieldErrors.designation }}</p>
            </div>
          </div>
        </section>

        <!-- Account Security -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1 flex items-center gap-1.5">
            Account Security
            <span class="relative inline-flex group">
              <button
                type="button"
                tabindex="-1"
                class="w-4 h-4 rounded-full border border-gray-300 text-gray-400 hover:text-brand-blue hover:border-brand-blue flex items-center justify-center text-[10px] font-bold leading-none transition-colors"
                aria-label="Password requirements"
              >
                i
              </button>
              <div
                class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-max max-w-[90vw] rounded-xl bg-gray-900 text-white text-[12px] leading-relaxed p-3 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity z-20 shadow-lg"
              >
                <p class="font-semibold mb-1">Password must include:</p>
                <ul class="list-disc list-inside space-y-0.5">
                  <li v-for="rule in PASSWORD_RULES" :key="rule.label" class="whitespace-nowrap">{{ rule.label }}</li>
                </ul>
                <span class="absolute left-1/2 -translate-x-1/2 top-full w-2.5 h-2.5 bg-gray-900 rotate-45 -mt-1"></span>
              </div>
            </span>
          </h2>
          <p class="text-[13px] text-muted mb-3">
            {{
              isEdit
                ? 'Leave blank to keep the current password.'
                : "Optional — leave blank and the teacher can set one later via \"Forgot password?\". If set now, it must meet the rules below."
            }}
          </p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="password" class="text-[13px] text-label">Password</label>
              <div class="relative">
                <input
                  id="password"
                  :ref="(el) => (fieldRefs.password.value = el)"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Enter password"
                  autocomplete="new-password"
                  @input="clearFieldError('password')"
                  class="w-full h-10 pl-3 pr-10 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.password ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <button
                  type="button"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                  aria-label="Toggle password visibility"
                  @click="showPassword = !showPassword"
                >
                  <svg v-if="!showPassword" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                    <line x1="1" y1="1" x2="23" y2="23" />
                  </svg>
                  <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
              <p v-if="fieldErrors.password" class="text-[12px] text-brand">{{ fieldErrors.password }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="password_confirmation" class="text-[13px] text-label">Confirm Password</label>
              <div class="relative">
                <input
                  id="password_confirmation"
                  :ref="(el) => (fieldRefs.password_confirmation.value = el)"
                  v-model="form.password_confirmation"
                  :type="showPasswordConfirm ? 'text' : 'password'"
                  placeholder="Re-enter password"
                  autocomplete="new-password"
                  @input="clearFieldError('password_confirmation')"
                  class="w-full h-10 pl-3 pr-10 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.password_confirmation ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <button
                  type="button"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                  aria-label="Toggle password visibility"
                  @click="showPasswordConfirm = !showPasswordConfirm"
                >
                  <svg v-if="!showPasswordConfirm" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                    <line x1="1" y1="1" x2="23" y2="23" />
                  </svg>
                  <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
              <p v-if="fieldErrors.password_confirmation" class="text-[12px] text-brand">{{ fieldErrors.password_confirmation }}</p>
            </div>
          </div>
        </section>

        <!-- E-Signature — edit mode only (see isEdit); used to sign off on
             evaluated answer sheets, same field ProfileView.vue lets a
             teacher upload for themselves. -->
        <section v-if="isEdit" class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">E-Signature</h2>
          <p class="text-[13px] text-muted mb-3">Used to sign off on this teacher's evaluated answer sheets.</p>
          <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-page-bg px-4 py-3.5">
            <div class="w-[150px] h-[75px] rounded-xl bg-white border border-input-border flex items-center justify-center shrink-0 overflow-hidden">
              <span v-if="esignLoading" class="text-[11px] text-muted">Loading&hellip;</span>
              <img v-else-if="esign" :src="esign" alt="Teacher's e-signature" class="max-w-full max-h-full object-contain" />
              <span v-else class="text-[11px] text-muted text-center px-2">No signature uploaded</span>
            </div>
            <button
              type="button"
              class="px-5 py-2.5 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors"
              @click="showEsignModal = true"
            >
              {{ esign ? 'Update Signature' : 'Upload Signature' }}
            </button>
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
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Teacher' }}
          </button>
        </div>
      </form>
    </div>

    <EsignCropperModal
      v-if="showEsignModal"
      title="Upload Teacher's E-Signature"
      :endpoint="`/teachers/${teacherId}/esign`"
      @saved="onEsignSaved"
      @close="showEsignModal = false"
    />
  </div>
</template>
