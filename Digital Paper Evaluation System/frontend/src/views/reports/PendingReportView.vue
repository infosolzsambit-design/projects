<script setup>
// Sidebar's Report → Pending Report — which teacher still has how many
// answer sheets left to evaluate. Same search fields as the Teacher Wise
// Report (TeacherWiseEvaluationReportView.vue); one row per teacher with
// anything pending (see PendingReportController). Download as Excel or
// PDF. Gated by the 'pending-report' permission (show/hide only).
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../composables/useToast'
import SearchableSelect from '../../components/common/SearchableSelect.vue'
import { useProgramsStore } from '../../stores/programs'
import { courseLabel } from '../../utils/course'
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
const REQUIRED_FILTERS = ['program_name', 'course_id', 'exam_term_id', 'exam_type_id', 'semester', 'exam_year']
const fieldErrors = reactive({ program_name: '', course_id: '', exam_term_id: '', exam_type_id: '', semester: '', exam_year: '' })
const fieldRefs = {
  program_name: ref(null),
  course_id: ref(null),
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
    // Same as loadPrograms() above.
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
  if (!authStore.can('pending-report')) return
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
// Shared-pool sheets of this search not started by anyone yet — {pending, teachers}.
const pool = ref({ pending: 0, teachers: [] })

function exportParams() {
  const params = { ...filters }
  if (!params.teacher_id) delete params.teacher_id
  if (!params.department_id) delete params.department_id
  return params
}

async function runSearch() {
  if (!validateFilters()) return
  searching.value = true
  searchError.value = ''
  try {
    const res = await api.get('/reports/pending-report', { params: exportParams() })
    rows.value = res.data.data.rows
    pool.value = res.data.data.pool ?? { pending: 0, teachers: [] }
    searched.value = true
  } catch (err) {
    searchError.value = err.response?.data?.message || 'Could not load this report.'
  } finally {
    searching.value = false
  }
}

const totalPending = computed(() => rows.value.reduce((sum, row) => sum + row.pending, 0))

// --- Download ---------------------------------------------------------
// A plain <a href> can't carry the Bearer token this API needs, so the
// file is fetched the same way any other authenticated call is (through
// api, blob-typed) and then handed to the browser as a client-side
// download — the standard approach for an authenticated SPA download.
const downloadFormat = ref('excel')
const downloading = ref(false)

async function downloadReport() {
  if (!validateFilters()) return
  downloading.value = true
  try {
    const res = await api.get('/reports/pending-report/export', {
      params: { ...exportParams(), format: downloadFormat.value },
      responseType: 'blob',
    })
    const blob = new Blob([res.data], { type: res.headers['content-type'] })
    const url = window.URL.createObjectURL(blob)
    const extension = downloadFormat.value === 'pdf' ? 'pdf' : 'xls'
    const link = document.createElement('a')
    link.href = url
    link.download = `pending_report.${extension}`
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
  <div v-else-if="!authStore.can('pending-report')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
      <span class="text-[13px] sm:text-sm text-gray-700">Pending Report</span>
    </div>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex items-center gap-3">
      <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="9" />
          <polyline points="12 7 12 12 15 14" />
        </svg>
      </span>
      <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Pending Report</h2>
    </section>

    <!-- Search -->
    <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4 mb-4">
      <div class="flex items-center gap-2 mb-2.5">
        <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900">Select Exam Details</h3>
      </div>

      <!-- Row 1: Program · Course · Exam Type · Exam Term (same layout as
           Assign Teacher / Generate Marksheet — Program and Course wider). -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5">
        <div class="flex flex-col gap-1">
          <label for="pdr_program" class="text-[12px] text-label">Program <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pdr_program"
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
          <label for="pdr_course" class="text-[12px] text-label">Course <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pdr_course"
            :ref="(el) => (fieldRefs.course_id.value = el)"
            v-model="filters.course_id"
            :options="availableCourses"
            :loading="coursesLoading"
            :error="!!fieldErrors.course_id"
            placeholder="Select course"
            search-placeholder="Search courses…"
            @change="clearFieldError('course_id')"
          />
          <p v-if="fieldErrors.course_id" class="text-[11px] text-brand">{{ fieldErrors.course_id }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="pdr_exam_type" class="text-[12px] text-label">Exam Type <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pdr_exam_type"
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
          <label for="pdr_exam_term" class="text-[12px] text-label">Exam Term <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pdr_exam_term"
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
          <label for="pdr_department" class="text-[12px] text-label">Department <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="pdr_department"
            v-model="filters.department_id"
            :options="availableDepartments"
            :loading="departmentsLoading"
            placeholder="All departments"
            search-placeholder="Search departments…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="pdr_semester" class="text-[12px] text-label">Semester <span class="text-brand">*</span></label>
          <SearchableSelect
            id="pdr_semester"
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
          <label for="pdr_exam_year" class="text-[12px] text-label">Exam Year <span class="text-brand">*</span></label>
          <input
            id="pdr_exam_year"
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
          <label for="pdr_teacher" class="text-[12px] text-label">Teacher <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="pdr_teacher"
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
          {{ rows.length }} teacher{{ rows.length === 1 ? '' : 's' }} with
          <span class="font-semibold text-brand">{{ totalPending }}</span> pending answer sheet{{ totalPending === 1 ? '' : 's' }}<template v-if="pool.pending">, plus
          <span class="font-semibold text-cyan-700">{{ pool.pending }}</span> in a shared pool</template>.
        </p>
        <div class="flex items-center gap-2">
          <div class="relative">
            <select
              v-model="downloadFormat"
              class="appearance-none h-9 pl-3 pr-8 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
            >
              <option value="excel">Excel</option>
              <option value="pdf">PDF</option>
            </select>
            <svg class="absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
          </div>
          <button
            type="button"
            class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="downloading || (!rows.length && !pool.pending)"
            @click="downloadReport"
          >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
            {{ downloading ? 'Downloading…' : 'Download' }}
          </button>
        </div>
      </div>

      <p v-if="searchError" class="text-[13px] text-brand text-center py-8">{{ searchError }}</p>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium whitespace-nowrap">
              <th class="px-2 py-2.5 rounded-tl-xl text-center w-px whitespace-nowrap">#</th>
              <th class="px-2 py-2.5">Teacher</th>
              <th class="px-2 py-2.5">Contact</th>
              <th class="px-2 py-2.5">Department</th>
              <th class="px-2 py-2.5">Allotted / Evaluated</th>
              <th class="px-2 py-2.5">Pending / Problem</th>
              <th class="px-2 py-2.5 rounded-tr-xl">Evaluation Window</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <!-- Shared-pool sheets nobody has started yet — not any one teacher's. -->
            <tr v-if="pool.pending" class="bg-cyan-50 text-[12.5px] text-cyan-900 border-b border-cyan-100">
              <td colspan="7" class="px-3 py-2.5">
                <span class="font-semibold">Shared pool — not started yet:</span>
                {{ pool.pending }} answer sheet{{ pool.pending === 1 ? '' : 's' }}, shared by {{ pool.teachers.join(', ') }}.
                <span class="text-cyan-700">The first teacher to start a sheet gets it.</span>
              </td>
            </tr>
            <tr v-if="!rows.length && !pool.pending">
              <td colspan="7" class="px-3 py-8 text-center text-sm text-muted">No teacher has pending answer sheets for this search.</td>
            </tr>
            <tr v-for="(row, i) in rows" :key="i" class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0">
              <td class="px-2 py-2.5 text-center text-muted">{{ i + 1 }}</td>
              <!-- Teacher name, with the emp code below it. -->
              <td class="px-2 py-2.5 whitespace-nowrap">
                <p class="font-medium leading-snug">{{ row.teacher_name }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.emp_code || '—' }}</p>
              </td>
              <!-- Contact: email, with the phone number below. -->
              <td class="px-2 py-2.5">
                <p class="flex items-center gap-1.5 text-[12px] leading-snug min-w-0" title="Email"><svg class="w-3 h-3 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2" /><polyline points="22 6 12 13 2 6" /></svg><span class="truncate">{{ row.email || '—' }}</span></p>
                <p class="mt-0.5 flex items-center gap-1.5 text-[12px] leading-snug" title="Phone"><svg class="w-3 h-3 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" /></svg><span>{{ row.mobile_no || '—' }}</span></p>
              </td>
              <!-- The packets' own department(s), one per line. -->
              <td class="px-2 py-2.5">
                <p v-for="dept in row.department_names?.length ? row.department_names : ['—']" :key="dept" class="leading-snug">{{ dept }}</p>
              </td>
              <td class="px-2 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Allotted</span><span class="text-muted">:</span><span class="font-semibold text-gray-900">{{ row.allotted }}</span>
                  <span class="text-muted">Evaluated</span><span class="text-muted">:</span><span class="font-semibold text-success">{{ row.evaluated }}</span>
                </div>
              </td>
              <td class="px-2 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Pending</span><span class="text-muted">:</span><span class="font-semibold text-brand">{{ row.pending }}</span>
                  <span class="text-muted">Problem</span><span class="text-muted">:</span><span class="font-semibold text-amber-600">{{ row.problem }}</span>
                </div>
              </td>
              <!-- End date in red with an "Overdue" tag once the window has
                   closed and sheets are still pending. -->
              <td class="px-2 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Start</span><span class="text-muted">:</span><span class="text-gray-800">{{ row.evaluation_start_date || '—' }}</span>
                  <span class="text-muted">End</span><span class="text-muted">:</span>
                  <span :class="row.overdue ? 'text-brand font-semibold' : 'text-gray-800'">
                    {{ row.evaluation_end_date || '—' }}
                    <span v-if="row.overdue" class="ml-1 inline-flex items-center rounded-full bg-brand/10 text-brand px-1.5 py-px text-[10px] font-semibold">Overdue</span>
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
