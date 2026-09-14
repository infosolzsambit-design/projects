<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import SearchableSelect from '../../components/common/SearchableSelect.vue'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const programId = computed(() => route.params.id || null)
const isEdit = computed(() => !!programId.value)

const form = reactive({
  name: '',
  department_id: '',
  code: '',
  course_ids: [],
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

// Department options for the dropdown below — same searchable, jQuery-free
// Select2 equivalent used on the Teacher form (see SearchableSelect.vue),
// sourced from the Department master list.
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
    availableDepartments.value = res.data.data
  } catch (err) {
    departmentsError.value = err.response?.data?.message || 'Could not load departments.'
  } finally {
    departmentsLoading.value = false
  }
}

const availableCourses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')

// Live search over the course checkboxes — doesn't filter the list (every
// course stays visible/selectable), it just highlights the matching text
// in each name/code as the user types.
const courseSearch = ref('')

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function escapeRegExp(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

// Escapes the text first, then wraps whatever matches the (also escaped)
// search query in <mark> — keeps this safe against course names/codes that
// might contain HTML-special characters while still highlighting correctly.
function highlightMatch(text) {
  const safe = escapeHtml(text || '')
  const query = courseSearch.value.trim()
  if (!query) return safe
  const pattern = escapeRegExp(escapeHtml(query))
  return safe.replace(new RegExp(pattern, 'gi'), (match) => `<mark class="bg-yellow-300">${match}</mark>`)
}

function courseLabelHtml(course) {
  const name = highlightMatch(course.name)
  const code = course.code ? ` <span class="text-muted">(${highlightMatch(course.code)})</span>` : ''
  return name + code
}

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  try {
    const res = await api.get('/courses', {
      params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] },
    })
    // ?status=all (+ ?table_fields=…) returns a flat array in `data`, not
    // the paginated { items, pagination } shape the default listing uses —
    // see CourseController::index()'s "all" branch.
    availableCourses.value = res.data.data
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load courses.'
  } finally {
    coursesLoading.value = false
  }
}

const fieldErrors = reactive({
  name: '',
  department_id: '',
  code: '',
  course_ids: '',
})
// Order matters — the first of these (in this order) that has an error is
// the one that gets focused after a failed validate()/submit.
const FIELD_ORDER = ['name', 'department_id', 'code', 'course_ids']
const fieldRefs = {
  name: ref(null),
  department_id: ref(null),
  code: ref(null),
  course_ids: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

// Snapshot Reset restores to — the blank defaults above for create, or
// whatever was actually loaded for edit (captured once loadProgram()
// finishes below). Not reactive on purpose — it's a fixed target, not
// something that should track the form's own live edits.
let initialForm = { ...form, course_ids: [] }

async function loadProgram() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/programs/${programId.value}`)
    const program = res.data.data
    form.name = program.name
    form.department_id = program.department_id || ''
    form.code = program.code || ''
    form.course_ids = (program.courses || []).map((c) => c.id)
    initialForm = { ...form, course_ids: [...form.course_ids] }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this program.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, initialForm)
  form.course_ids = [...initialForm.course_ids]
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  loadDepartments()
  loadCourses()
  if (isEdit.value) loadProgram()
})

// Applies the backend's per-field validation errors (422 → { errors: {
// field: [messages] } }) the same way client-side validation does — under
// the matching field, red border and all — instead of one generic banner.
function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([field, messages]) => {
      const key = field.startsWith('course_ids') ? 'course_ids' : field
      if (key in fieldErrors) fieldErrors[key] = messages[0]
    })
    if (FIELD_ORDER.some((key) => fieldErrors[key])) {
      focusFirstError()
      return
    }
  }
  formError.value = data?.message || 'Could not save program.'
}

// Replaces the native `required` attribute — styled error + red border
// instead of a browser validation bubble, like the rest of this app's forms.
function validate() {
  if (!form.name.trim()) fieldErrors.name = 'Name is required.'
  if (!form.department_id) fieldErrors.department_id = 'Department is required.'
  if (!form.code.trim()) fieldErrors.code = 'Code is required.'
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
      await api.put(`/programs/${programId.value}`, payload)
    } else {
      await api.post('/programs', payload)
    }
    router.push({ name: 'master-programs' })
    toast.success(isEdit.value ? 'Program updated successfully.' : 'Program created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'master-programs' })
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
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Program' : 'Add Program' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this program's details." : 'Create a new program for the system.' }}
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
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Program Details</h2>
          <p class="text-[13px] text-muted mb-3">Name, Department and Code are required. Code must be unique.</p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. B.Tech Computer Science"
                autofocus
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
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
              <label for="code" class="text-[13px] text-label">Code <span class="text-brand">*</span></label>
              <input
                id="code"
                :ref="(el) => (fieldRefs.code.value = el)"
                v-model="form.code"
                type="text"
                placeholder="e.g. BT-CSE"
                @input="clearFieldError('code')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.code ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.code" class="text-[12px] text-brand">{{ fieldErrors.code }}</p>
            </div>
          </div>
        </section>

        <!-- Course multi-select — native checkbox-fieldset pattern from
             designed_files/form.html's Selection Controls section (lines
             ~271-287), used instead of a jQuery/Select2 multiselect per the
             app's jQuery-free decision. -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900">Courses</h2>
            <input
              v-model="courseSearch"
              type="text"
              placeholder="Search courses…"
              class="w-full sm:w-60 h-9 px-3 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            />
          </div>
          <p class="text-[13px] text-muted mb-3">Optionally map one or more courses to this program.</p>

          <p v-if="coursesLoading" class="text-sm text-muted py-4">Loading courses&hellip;</p>
          <p v-else-if="coursesError" class="text-sm text-brand py-4">{{ coursesError }}</p>
          <p v-else-if="!availableCourses.length" class="text-sm text-muted py-4">No active courses available yet.</p>

          <fieldset
            v-else
            :ref="(el) => (fieldRefs.course_ids.value = el)"
            tabindex="-1"
            class="rounded-2xl border p-3"
            :class="fieldErrors.course_ids ? 'border-brand' : 'border-input-border bg-page-bg/60'"
          >
            <legend class="px-2 text-[13px] font-medium text-label">Available Courses</legend>
            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              <label v-for="course in availableCourses" :key="course.id" class="form-check">
                <input
                  type="checkbox"
                  class="form-checkbox"
                  :value="course.id"
                  v-model="form.course_ids"
                  @change="clearFieldError('course_ids')"
                />
                <span v-html="courseLabelHtml(course)"></span>
              </label>
            </div>
          </fieldset>
          <p v-if="fieldErrors.course_ids" class="text-[12px] text-brand mt-2">{{ fieldErrors.course_ids }}</p>
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
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Program' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
