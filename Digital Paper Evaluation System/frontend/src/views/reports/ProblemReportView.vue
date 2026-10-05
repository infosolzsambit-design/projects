<script setup>
// Sidebar's Report → Problem Report — every answer sheet a teacher has
// raised an issue on (open or resolved). Same search fields as
// TeacherWiseEvaluationReportView.vue, except Course is optional here.
// Search hits GET /reports/problem-report; Download (Excel only) hits
// GET .../export with the same filters. Gated by the 'problem-report'
// permission (show/hide only).
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../composables/useToast'
import SearchableSelect from '../../components/common/SearchableSelect.vue'
import { useProgramsStore } from '../../stores/programs'
import { courseLabel, typeSuffix } from '../../utils/course'
import { ordinal } from '../../utils/ordinal'

const toast = useToast()
const authStore = useAuthStore()

const SEMESTER_OPTIONS = Array.from({ length: 12 }, (_, i) => i + 1)
// Same searchable dropdown as the other filters; shown as 1st, 2nd, …
const semesterOptions = SEMESTER_OPTIONS.map((n) => ({ id: n, name: ordinal(n) }))

const filters = reactive({
  program_name: '',
  course_id: '',
  department_id: '', // optional — the packet's department
  exam_term_id: '',
  exam_type_id: '',
  semester: '',
  exam_year: new Date().getFullYear(),
  teacher_id: '',
})
const REQUIRED_FILTERS = ['program_name', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year']
const fieldErrors = reactive({ program_name: '', exam_term_id: '', exam_type_id: '', semester: '', exam_year: '' })
const fieldRefs = {
  program_name: ref(null),
  exam_term_id: ref(null),
  exam_type_id: ref(null),
  semester: ref(null),
  exam_year: ref(null),
}
function clearFieldError(field) {
  fieldErrors[field] = ''
}
function focusFirstError() {
  const field = REQUIRED_FILTERS.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}
function validateFilters() {
  let ok = true
  REQUIRED_FILTERS.forEach((field) => {
    if (!filters[field]) {
      fieldErrors[field] = 'Required.'
      ok = false
    }
  })
  if (!ok) {
    toast.error('Fill in every exam detail before searching.')
    focusFirstError()
  }
  return ok
}

// --- Dropdown sources — same endpoints/shape AssignTeacherView.vue's own
// filter row already uses. ---------------------------------------------
const availablePrograms = ref([])
const programsLoading = ref(true)
const programsStore = useProgramsStore()

// Remarks column — hover a Teacher/Admin button for a quick preview
// (teleported to <body> so the table's scroll box can't clip it), click
// for the full message in a modal.
const remarkPreview = ref(null) // { text, top, left, bottom }
const remarkModal = ref(null) // { title, text, row }

function showRemarkPreview(text, event) {
  if (!text) return
  const rect = event.currentTarget.getBoundingClientRect()
  const width = 320
  const below = window.innerHeight - rect.bottom > 180
  remarkPreview.value = {
    text,
    left: Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8)),
    top: below ? rect.bottom + 8 : null,
    bottom: below ? null : window.innerHeight - rect.top + 8,
  }
}
function hideRemarkPreview() {
  remarkPreview.value = null
}
function openRemark(title, text, row) {
  if (!text) return
  remarkPreview.value = null
  remarkModal.value = { title, text, row }
}
function onRemarkKey(event) {
  if (event.key === 'Escape') remarkModal.value = null
}
onMounted(() => document.addEventListener('keydown', onRemarkKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onRemarkKey))
async function loadPrograms() {
  programsLoading.value = true
  try {
    // Shown as "Name (Label)"; the value stays the plain program name.
    await programsStore.load(true)
    availablePrograms.value = programsStore.options()
  } catch {
    // Non-fatal — the dropdown just stays empty; the page itself doesn't
    // depend on this succeeding to render.
  } finally {
    programsLoading.value = false
  }
}

const availableCourses = ref([])
const coursesLoading = ref(true)
async function loadCourses() {
  coursesLoading.value = true
  try {
    const res = await api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code', 'type'] } })
    availableCourses.value = res.data.data.map((course) => ({
      ...course,
      name: courseLabel(course.name, course.code, course.type),
    }))
  } catch {
    // Same as loadPrograms() above.
  } finally {
    coursesLoading.value = false
  }
}

const availableDepartments = ref([])
const departmentsLoading = ref(true)
async function loadDepartments() {
  departmentsLoading.value = true
  try {
    const res = await api.get('/departments', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    availableDepartments.value = res.data.data.map((d) => ({ ...d, name: d.code ? `${d.name} (${d.code})` : d.name }))
  } catch {
    // Same as the other dropdown loaders — leave the list empty.
  } finally {
    departmentsLoading.value = false
  }
}

const availableExamTerms = ref([])
const examTermsLoading = ref(true)
async function loadExamTerms() {
  examTermsLoading.value = true
  try {
    const res = await api.get('/exam-terms', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTerms.value = res.data.data
  } catch {
    // Same as loadPrograms() above.
  } finally {
    examTermsLoading.value = false
  }
}

const availableExamTypes = ref([])
const examTypesLoading = ref(true)
async function loadExamTypes() {
  examTypesLoading.value = true
  try {
    const res = await api.get('/exam-types', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTypes.value = res.data.data
  } catch {
    // Same as loadPrograms() above.
  } finally {
    examTypesLoading.value = false
  }
}

// Optional — not required to search, so no error state of its own.
const availableTeachers = ref([])
const teachersLoading = ref(true)
async function loadTeachers() {
  teachersLoading.value = true
  try {
    const res = await api.get('/teachers', { params: { per_page: 200, is_active: 'yes' } })
    availableTeachers.value = res.data.data.items.map((t) => ({
      id: t.id,
      name: t.emp_code ? `${t.name} (${t.emp_code})` : t.name,
    }))
  } catch {
    // Same as loadPrograms() above.
  } finally {
    teachersLoading.value = false
  }
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (!authStore.can('problem-report')) return
  loadPrograms()
  loadCourses()
  loadDepartments()
  loadExamTerms()
  loadExamTypes()
  loadTeachers()
})

// --- Search ---------------------------------------------------------------
const searched = ref(false)
const searching = ref(false)
const searchError = ref('')
const rows = ref([])

function exportParams() {
  const params = { ...filters }
  if (!params.teacher_id) delete params.teacher_id
  if (!params.course_id) delete params.course_id
  if (!params.department_id) delete params.department_id
  return params
}

async function runSearch() {
  if (!validateFilters()) return
  searching.value = true
  searchError.value = ''
  try {
    const res = await api.get('/reports/problem-report', { params: exportParams() })
    rows.value = res.data.data.rows
    searched.value = true
  } catch (err) {
    searchError.value = err.response?.data?.message || 'Could not load this report.'
  } finally {
    searching.value = false
  }
}

// --- Download ---------------------------------------------------------
// A plain <a href> can't carry the Bearer token this API needs, so the
// file is fetched the same way any other authenticated call is (through
// api, blob-typed) and then handed to the browser as a client-side
// download — the standard approach for an authenticated SPA download.
const downloading = ref(false)

async function downloadReport() {
  if (!validateFilters()) return
  downloading.value = true
  try {
    const res = await api.get('/reports/problem-report/export', {
      params: exportParams(),
      responseType: 'blob',
    })
    const blob = new Blob([res.data], { type: res.headers['content-type'] })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'problem_report.xls'
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    let message = 'Could not download this report.'
    if (err.response?.data instanceof Blob) {
      try {
        message = JSON.parse(await err.response.data.text())?.message || message
      } catch {
        // The blob wasn't JSON (a genuine file, or an unrelated failure) — keep the default message.
      }
    } else {
      message = err.response?.data?.message || message
    }
    toast.error(message)
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('problem-report')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Problem Report</span>
    </div>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex items-center gap-3">
      <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 3v18h18" />
          <path d="M18 17V9" />
          <path d="M13 17V5" />
          <path d="M8 17v-3" />
        </svg>
      </span>
      <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Problem Report</h2>
    </section>

    <!-- Search -->
    <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4 mb-4">
      <div class="flex items-center gap-2 mb-2.5">
        <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900">Select Exam Details</h3>
      </div>

      <!-- Row 1: Program · Course · Exam Type · Exam Term (same layout as
           the other search panels — Program and Course wider). -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5">
        <div class="flex flex-col gap-1">
          <label for="pr_program" class="text-[12px] text-label">Program <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pr_program"
            :ref="(el) => (fieldRefs.program_name.value = el)"
            v-model="filters.program_name"
            :options="availablePrograms"
            :loading="programsLoading"
            :error="!!fieldErrors.program_name"
            placeholder="Select program"
            search-placeholder="Search programs…"
            @change="clearFieldError('program_name')"
          />
          <p v-if="fieldErrors.program_name" class="text-[11px] text-brand">{{ fieldErrors.program_name }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_course" class="text-[12px] text-label">Course <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="pr_course"
            v-model="filters.course_id"
            :options="availableCourses"
            :loading="coursesLoading"
            placeholder="All courses"
            search-placeholder="Search courses…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_exam_type" class="text-[12px] text-label">Exam Type <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pr_exam_type"
            :ref="(el) => (fieldRefs.exam_type_id.value = el)"
            v-model="filters.exam_type_id"
            :options="availableExamTypes"
            :loading="examTypesLoading"
            :error="!!fieldErrors.exam_type_id"
            placeholder="Select"
            search-placeholder="Search exam types…"
            @change="clearFieldError('exam_type_id')"
          />
          <p v-if="fieldErrors.exam_type_id" class="text-[11px] text-brand">{{ fieldErrors.exam_type_id }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_exam_term" class="text-[12px] text-label">Exam Term <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pr_exam_term"
            :ref="(el) => (fieldRefs.exam_term_id.value = el)"
            v-model="filters.exam_term_id"
            :options="availableExamTerms"
            :loading="examTermsLoading"
            :error="!!fieldErrors.exam_term_id"
            placeholder="Select"
            search-placeholder="Search exam terms…"
            @change="clearFieldError('exam_term_id')"
          />
          <p v-if="fieldErrors.exam_term_id" class="text-[11px] text-brand">{{ fieldErrors.exam_term_id }}</p>
        </div>
      </div>

      <!-- Row 2: Department · Semester · Exam Year · Teacher · Search -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.4fr_auto] gap-2.5 mt-2.5">
        <div class="flex flex-col gap-1 min-w-0">
          <label for="pr_department" class="text-[12px] text-label">Department <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="pr_department"
            v-model="filters.department_id"
            :options="availableDepartments"
            :loading="departmentsLoading"
            placeholder="All departments"
            search-placeholder="Search departments…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_semester" class="text-[12px] text-label">Semester <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pr_semester"
            :ref="(el) => (fieldRefs.semester.value = el)"
            v-model="filters.semester"
            :options="semesterOptions"
            :error="!!fieldErrors.semester"
            placeholder="Select"
            search-placeholder="Search semesters…"
            @change="clearFieldError('semester')"
          />
          <p v-if="fieldErrors.semester" class="text-[11px] text-brand">{{ fieldErrors.semester }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_exam_year" class="text-[12px] text-label">Exam Year <span class="text-brand">*</span></label>
          <input
            id="pr_exam_year"
            :ref="(el) => (fieldRefs.exam_year.value = el)"
            v-model="filters.exam_year"
            type="number"
            class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
            :class="fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
            @input="clearFieldError('exam_year')"
          />
          <p v-if="fieldErrors.exam_year" class="text-[11px] text-brand">{{ fieldErrors.exam_year }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="pr_teacher" class="text-[12px] text-label">Teacher <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="pr_teacher"
            v-model="filters.teacher_id"
            :options="availableTeachers"
            :loading="teachersLoading"
            placeholder="All teachers"
            search-placeholder="Search teachers…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label class="text-[12px] text-label invisible" aria-hidden="true">Search</label>
          <button
            type="button"
            class="h-10 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-6 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed self-start"
            :disabled="searching"
            @click="runSearch"
          >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
            {{ searching ? 'Searching…' : 'Search' }}
          </button>
        </div>
      </div>
    </section>

    <!-- Results -->
    <section v-if="searched" class="bg-white rounded-2xl shadow-panel p-3 sm:p-4">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 mb-3">
        <p class="text-[13px] text-gray-700">
          {{ rows.length }} result{{ rows.length === 1 ? '' : 's' }} found.
        </p>
        <button
          type="button"
          class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="downloading || !rows.length"
          @click="downloadReport"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
          {{ downloading ? 'Downloading…' : 'Download Excel' }}
        </button>
      </div>

      <p v-if="searchError" class="text-[13px] text-brand text-center py-8">{{ searchError }}</p>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[1100px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium whitespace-nowrap">
              <th class="px-2.5 py-2.5 rounded-tl-xl">Teacher</th>
              <th class="px-2.5 py-2.5">Program / Course</th>
              <th class="px-2.5 py-2.5 text-center" title="Semester">Sem</th>
              <th class="px-2.5 py-2.5">Exam Term / Type</th>
              <th class="px-2.5 py-2.5">Paper QR Code</th>
              <th class="px-2.5 py-2.5">Issue / Status</th>
              <th class="px-2.5 py-2.5">Remarks</th>
              <th class="px-2.5 py-2.5">Issue Time</th>
              <th class="px-2.5 py-2.5 rounded-tr-xl">Solved By / Date</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="!rows.length">
              <td colspan="9" class="px-3 py-8 text-center text-sm text-muted">No problems found for this search.</td>
            </tr>
            <tr v-for="(row, i) in rows" v-else :key="i" class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top">
              <!-- Teacher name with the Emp Code below it. -->
              <td class="px-2.5 py-2.5 whitespace-nowrap">
                <p class="font-medium leading-snug">{{ row.teacher_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.emp_code || '—' }}</p>
              </td>
              <!-- Program, with the course (code – type) below it. -->
              <td class="px-2.5 py-2.5">
                <p class="leading-snug">{{ programsStore.display(row.program_name) || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.course_name ? courseLabel(row.course_name, row.course_code, row.course_type) : '—' }}</p>
              </td>
              <td class="px-2.5 py-2.5 text-center">{{ ordinal(row.semester) }}</td>
              <!-- Exam Term with the Exam Type below it. -->
              <td class="px-2.5 py-2.5 whitespace-nowrap">
                <p class="leading-snug">{{ row.exam_term_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.exam_type_name || '—' }}</p>
              </td>
              <td class="px-2.5 py-2.5 font-semibold">{{ row.qr_code || '—' }}</td>
              <!-- Issue type with its status below it. -->
              <td class="px-2.5 py-2.5 whitespace-nowrap">
                <p class="leading-snug">{{ row.issue_type || '—' }}</p>
                <span
                  class="mt-1 inline-flex items-center rounded-full px-2 py-px text-[11px] font-semibold"
                  :class="row.status === 'Resolved' ? 'bg-success/10 text-success' : 'bg-brand/10 text-brand'"
                >{{ row.status }}</span>
              </td>
              <!-- Teacher / Admin remarks: hover to preview, click to open. -->
              <td class="px-2.5 py-2.5">
                <div class="flex flex-col items-start gap-1">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 h-6 px-2 rounded-md text-[11.5px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="row.teacher_remarks ? 'text-brand-blue bg-soft hover:bg-brand-blue/10' : 'text-muted bg-gray-100'"
                    :disabled="!row.teacher_remarks"
                    :title="row.teacher_remarks ? '' : 'No teacher remarks'"
                    @mouseenter="showRemarkPreview(row.teacher_remarks, $event)"
                    @mouseleave="hideRemarkPreview"
                    @focus="showRemarkPreview(row.teacher_remarks, $event)"
                    @blur="hideRemarkPreview"
                    @click="openRemark('Teacher Remarks', row.teacher_remarks, row)"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                    Teacher
                  </button>
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 h-6 px-2 rounded-md text-[11.5px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="row.admin_remarks ? 'text-brand-blue bg-soft hover:bg-brand-blue/10' : 'text-muted bg-gray-100'"
                    :disabled="!row.admin_remarks"
                    :title="row.admin_remarks ? '' : 'No admin remarks'"
                    @mouseenter="showRemarkPreview(row.admin_remarks, $event)"
                    @mouseleave="hideRemarkPreview"
                    @focus="showRemarkPreview(row.admin_remarks, $event)"
                    @blur="hideRemarkPreview"
                    @click="openRemark('Admin Remarks', row.admin_remarks, row)"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                    Admin
                  </button>
                </div>
              </td>
              <td class="px-2.5 py-2.5 whitespace-nowrap">{{ row.issue_raised_at || '—' }}</td>
              <!-- Solved by, with the solved date below it. -->
              <td class="px-2.5 py-2.5 whitespace-nowrap">
                <p class="leading-snug">{{ row.solved_by || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.solved_at || '—' }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <Teleport to="body">
      <!-- Remarks hover preview -->
      <div
        v-if="remarkPreview"
        class="fixed z-[150] w-[320px] rounded-xl bg-white shadow-panel border border-soft p-3 pointer-events-none"
        :style="{ left: remarkPreview.left + 'px', top: remarkPreview.top != null ? remarkPreview.top + 'px' : 'auto', bottom: remarkPreview.bottom != null ? remarkPreview.bottom + 'px' : 'auto' }"
        role="tooltip"
      >
        <p class="text-[12.5px] text-gray-800 leading-relaxed whitespace-pre-line line-clamp-6">{{ remarkPreview.text }}</p>
        <p class="mt-2 pt-2 border-t border-soft text-[11px] text-brand-blue">Click to view in full</p>
      </div>

      <!-- Remarks modal -->
      <div v-if="remarkModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="remarkModal = null">
        <div class="w-full max-w-[520px] max-h-[80vh] flex flex-col rounded-[24px] bg-white shadow-panel overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="remark-title">
          <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-soft">
            <div class="min-w-0">
              <h2 id="remark-title" class="text-[18px] font-semibold text-gray-900">{{ remarkModal.title }}</h2>
              <p class="text-[12px] text-muted mt-0.5 truncate">
                {{ remarkModal.row.teacher_name }}<template v-if="remarkModal.row.qr_code"> · QR {{ remarkModal.row.qr_code }}</template><template v-if="remarkModal.row.issue_type"> · {{ remarkModal.row.issue_type }}</template>
              </p>
            </div>
            <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="remarkModal = null">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>
          <div class="flex-1 overflow-y-auto px-5 py-4">
            <p class="text-[13.5px] text-gray-800 leading-relaxed whitespace-pre-line break-words">{{ remarkModal.text }}</p>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
