<script setup>
// Sidebar's "Generate Marksheet". Search an exam offering (same fields as
// the Teacher Wise report, no Teacher, Course optional) and see per course:
// total / evaluated / pending sheets and whether each sheet's handwritten
// STUDENT'S ID NO. matches its system roll number. The ID is read once per
// sheet in the background on the server (GenerateMarksheetController /
// StudentIdCheckService) — this page only reads stored results, so it stays
// fast with thousands of sheets. Mismatches open a review modal showing the
// cropped ID boxes next to the QR code and roll number. Gated by the
// 'generate-marksheet' permission (show/hide only).
import { computed, onMounted, reactive, ref } from 'vue'
import api, { resolveStorageUrl } from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../composables/useToast'
import { useConfirm } from '../composables/useConfirm'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import Pagination from '../components/common/Pagination.vue'
import { useProgramsStore } from '../stores/programs'
import { courseLabel } from '../utils/course'
import { ordinal } from '../utils/ordinal'

const toast = useToast()
const { confirmDialog } = useConfirm()
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
    fieldRefs[REQUIRED_FILTERS.find((key) => fieldErrors[key])]?.value?.focus()
  }
  return ok
}

// --- Dropdown sources (same endpoints as the report pages) ---
const availablePrograms = ref([])
const availableCourses = ref([])
const availableDepartments = ref([])
const availableExamTerms = ref([])
const availableExamTypes = ref([])
const loadingLists = reactive({ programs: true, courses: true, departments: true, examTerms: true, examTypes: true })

async function loadList(key, url, params, map = (x) => x) {
  loadingLists[key] = true
  try {
    const res = await api.get(url, { params })
    return res.data.data.map(map)
  } catch {
    return []
  } finally {
    loadingLists[key] = false
  }
}

const programsStore = useProgramsStore()
const userReady = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  userReady.value = true
  if (!authStore.can('generate-marksheet')) return
  const common = { status: 'all', is_active: 'yes' }
  // Shown as "Name (Label)"; the filter value stays the plain program name.
  loadingLists.programs = true
  availablePrograms.value = await programsStore.load(true).then(() => programsStore.options()).catch(() => [])
  loadingLists.programs = false
  availableCourses.value = await loadList('courses', '/courses', { ...common, table_fields: ['name', 'code', 'type'] }, (c) => ({
    ...c,
    name: courseLabel(c.name, c.code, c.type),
  }))
  availableDepartments.value = await loadList('departments', '/departments', { ...common, table_fields: ['name', 'code'] }, (d) => ({
    ...d,
    name: d.code ? `${d.name} (${d.code})` : d.name,
  }))
  availableExamTerms.value = await loadList('examTerms', '/exam-terms', { ...common, table_fields: ['name'] })
  availableExamTypes.value = await loadList('examTypes', '/exam-types', { ...common, table_fields: ['name'] })
})

function searchParams() {
  const params = { ...filters }
  if (!params.course_id) delete params.course_id
  if (!params.department_id) delete params.department_id
  return params
}

// --- Summary ---
const searched = ref(false)
const searching = ref(false)
const searchError = ref('')
const rows = ref([])
const lastParams = ref(null)

// Search = check first, then show the table: every not-yet-checked sheet in
// the searched exam is read right away (batch by batch, no queue) while a
// progress bar shows exactly how many are done.
async function runSearch() {
  if (!validateFilters()) return
  searching.value = true
  searchError.value = ''
  searched.value = false
  try {
    lastParams.value = searchParams()
    rowSearch.value = '' // a new search starts with the full list
    await runCheck(lastParams.value)
    await fetchSummary()
    searched.value = true
  } catch (err) {
    searchError.value = err.response?.data?.message || 'Could not load the marksheet summary.'
    searched.value = true
  } finally {
    searching.value = false
  }
}

async function fetchSummary() {
  const res = await api.get('/generate-marksheet', { params: lastParams.value })
  rows.value = res.data.data.rows
  return rows.value
}

// --- Roll-number check progress ---
const BATCH_SIZE = 25
const progress = reactive({ active: false, label: '', total: 0, checked: 0, initialChecked: 0, startedAt: 0, cancelled: false })
const progressPercent = computed(() => (progress.total ? Math.round((progress.checked / progress.total) * 100) : 0))
const progressEta = computed(() => {
  const done = progress.checked - progress.initialChecked
  const left = progress.total - progress.checked
  if (done <= 0 || left <= 0) return ''
  const seconds = Math.round(((Date.now() - progress.startedAt) / 1000 / done) * left)
  return seconds < 60 ? `about ${Math.max(seconds, 1)} sec left` : `about ${Math.round(seconds / 60)} min left`
})

async function runCheck(params, label = 'Checking roll numbers') {
  Object.assign(progress, { active: true, label, total: 0, checked: 0, initialChecked: 0, startedAt: Date.now(), cancelled: false })
  let first = true
  try {
    for (;;) {
      const res = await api.post('/generate-marksheet/check', { limit: BATCH_SIZE }, { params, skipLoader: true })
      const data = res.data.data
      progress.total = data.total
      progress.checked = data.checked
      if (first) {
        progress.initialChecked = data.checked - data.processed
        first = false
      }
      if (data.remaining === 0 || data.processed === 0 || progress.cancelled) break
    }
  } finally {
    progress.active = false
  }
}

function cancelCheck() {
  progress.cancelled = true
}

// Re-read one course's sheets (manually confirmed sheets are kept as they are).
const recheckingCourseId = ref(null)
async function recheck(row) {
  const ok = await confirmDialog({
    title: 'Recheck Roll Numbers?',
    message: `Read the student ID on every answer sheet of ${courseLabel(row.course_name, row.course_code, row.course_type)} again and compare it with the roll number. Sheets you've already confirmed manually are not changed.`,
    confirmText: 'Recheck',
    danger: false,
  })
  if (!ok) return
  recheckingCourseId.value = row.course_id
  try {
    const params = { ...lastParams.value, course_id: row.course_id }
    await api.post('/generate-marksheet/recheck', null, { params, skipLoader: true })
    await runCheck(params, `Rechecking ${courseLabel(row.course_name, row.course_code, row.course_type)}`)
    await fetchSummary()
    toast.success(`Roll numbers rechecked for ${courseLabel(row.course_name, row.course_code, row.course_type)}.`)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not recheck this course.')
  } finally {
    recheckingCourseId.value = null
  }
}

async function resumeCheck() {
  try {
    await runCheck(lastParams.value)
    await fetchSummary()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not finish the roll number check.')
  }
}
// Instant search over the result rows (course, code, type, department,
// exam term / type) — filters as you type, no server call.
const rowSearch = ref('')
const visibleRows = computed(() => {
  const q = rowSearch.value.trim().toLowerCase()
  if (!q) return rows.value
  return rows.value.filter((row) =>
    [courseLabel(row.course_name, row.course_code, row.course_type), ...(row.department_names || []), row.exam_term_name, row.exam_type_name]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(q)),
  )
})

const notCheckedTotal = computed(() => rows.value.reduce((sum, r) => sum + r.not_checked_count, 0))

// --- Marksheet download ---
// Only offered once every sheet is evaluated and every roll number matches
// (the server enforces the same rule).
function marksheetReady(row) {
  return row.total_count > 0 && row.evaluated_count === row.total_count && row.matched_count === row.total_count
}
function notReadyReason(row) {
  const reasons = []
  if (row.evaluated_count < row.total_count) reasons.push(`${row.total_count - row.evaluated_count} not evaluated`)
  if (row.matched_count < row.total_count) reasons.push(`${row.total_count - row.matched_count} roll number(s) not matched`)
  return reasons.join(', ')
}

const downloadingMarksheet = ref(null) // `${course_id}:${format}` while downloading
async function downloadMarksheet(row, format) {
  downloadingMarksheet.value = `${row.course_id}:${format}`
  try {
    const res = await api.get('/generate-marksheet/export', {
      params: { ...lastParams.value, course_id: row.course_id, format },
      responseType: 'blob',
    })
    const blob = new Blob([res.data], { type: res.headers['content-type'] })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `marksheet_${(row.course_code || 'course').replace(/[^A-Za-z0-9]+/g, '_').toLowerCase()}.${format === 'pdf' ? 'pdf' : 'xls'}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    let message = 'Could not generate the marksheet.'
    if (err.response?.data instanceof Blob) {
      try {
        message = JSON.parse(await err.response.data.text())?.message || message
      } catch {
        // Not JSON — keep the default message.
      }
    }
    toast.error(message)
  } finally {
    downloadingMarksheet.value = null
  }
}

// --- Mismatch review modal ---
const reviewRow = ref(null)
const reviewItems = ref([])
const reviewLoading = ref(false)
const reviewError = ref('')
const reviewPagination = reactive({ current_page: 1, last_page: 1, per_page: 20, total: 0 })

// Selected sheet ids — kept across pages of the modal; the header checkbox
// only (un)selects the rows on the current page.
const selectedIds = ref(new Set())
const confirming = ref(false)
const allOnPageSelected = computed(
  () => reviewItems.value.length > 0 && reviewItems.value.every((item) => selectedIds.value.has(item.id)),
)
const someOnPageSelected = computed(
  () => !allOnPageSelected.value && reviewItems.value.some((item) => selectedIds.value.has(item.id)),
)
function toggleRow(id) {
  const next = new Set(selectedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedIds.value = next
}
function togglePage() {
  const next = new Set(selectedIds.value)
  const selectAll = !allOnPageSelected.value
  reviewItems.value.forEach((item) => (selectAll ? next.add(item.id) : next.delete(item.id)))
  selectedIds.value = next
}
function clearSelection() {
  selectedIds.value = new Set()
}

function openReview(row) {
  reviewRow.value = row
  clearSelection()
  loadReview(1)
}
function closeReview() {
  reviewRow.value = null
  reviewItems.value = []
  clearSelection()
}

async function confirmSelectedMatch() {
  const count = selectedIds.value.size
  if (!count) return
  const ok = await confirmDialog({
    title: 'Confirm Roll Numbers Match?',
    message: `This marks ${count} answer sheet${count === 1 ? '' : 's'} as matched: the roll number written by the student is the same as the system roll number.`,
    confirmText: 'Confirm',
    danger: false,
  })
  if (!ok) return

  confirming.value = true
  try {
    const res = await api.post('/generate-marksheet/confirm-match', { answer_sheet_ids: [...selectedIds.value] })
    toast.success(res.data.message)
    clearSelection()
    const latest = await fetchSummary()
    reviewRow.value = latest.find((r) => r.course_id === reviewRow.value.course_id) || reviewRow.value
    if (!reviewRow.value.mismatched_count) {
      closeReview()
      return
    }
    const page = Math.min(reviewPagination.current_page, Math.max(1, Math.ceil(reviewRow.value.mismatched_count / reviewPagination.per_page)))
    loadReview(page)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not update these answer sheets.')
  } finally {
    confirming.value = false
  }
}

// Read digits split so the ones that differ from the system roll number can
// be highlighted.
function readDigits(item) {
  const roll = String(item.roll_no || '').replace(/\D/g, '')
  return [...String(item.student_id_read || '')].map((ch, i) => ({ ch, diff: ch !== roll[i] }))
}

async function loadReview(page) {
  reviewLoading.value = true
  reviewError.value = ''
  try {
    const res = await api.get('/generate-marksheet/mismatches', {
      params: { ...lastParams.value, course_id: reviewRow.value.course_id, page, per_page: reviewPagination.per_page },
    })
    reviewItems.value = res.data.data.items
    Object.assign(reviewPagination, res.data.data.pagination)
  } catch (err) {
    reviewError.value = err.response?.data?.message || 'Could not load the mismatched sheets.'
  } finally {
    reviewLoading.value = false
  }
}

function openAnswerSheet(item) {
  if (item.pdf_url) window.open(resolveStorageUrl(item.pdf_url), '_blank', 'noopener')
}

function statusLabel(status) {
  if (status === 'unreadable') return 'Could not read'
  if (status === 'error') return 'Check failed'
  return null
}
</script>

<template>
  <p v-if="!userReady" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('generate-marksheet')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
      <span class="text-[13px] sm:text-sm text-gray-700">Generate Marksheet</span>
    </div>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex items-center gap-3">
      <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
          <line x1="8" y1="13" x2="16" y2="13" />
          <line x1="8" y1="17" x2="13" y2="17" />
        </svg>
      </span>
      <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Generate Marksheet</h2>
    </section>

    <!-- Search -->
    <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4 mb-4">
      <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900 mb-2.5">Select Exam Details</h3>

      <!-- Row 1: Program · Course · Exam Type · Exam Term (same layout as
           Assign Teacher — Program and Course wider). -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5">
        <div class="flex flex-col gap-1">
          <label for="gm_program" class="text-[12px] text-label">Program <span class="text-brand">*</span></label>
          <SearchableSelect
            id="gm_program"
            :ref="(el) => (fieldRefs.program_name.value = el)"
            v-model="filters.program_name"
            :options="availablePrograms"
            :loading="loadingLists.programs"
            :error="!!fieldErrors.program_name"
            placeholder="Select program"
            search-placeholder="Search programs…"
            @change="clearFieldError('program_name')"
          />
          <p v-if="fieldErrors.program_name" class="text-[11px] text-brand">{{ fieldErrors.program_name }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="gm_course" class="text-[12px] text-label">Course <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="gm_course"
            v-model="filters.course_id"
            :options="availableCourses"
            :loading="loadingLists.courses"
            placeholder="All courses"
            search-placeholder="Search courses…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="gm_exam_type" class="text-[12px] text-label">Exam Type <span class="text-brand">*</span></label>
          <SearchableSelect
            id="gm_exam_type"
            :ref="(el) => (fieldRefs.exam_type_id.value = el)"
            v-model="filters.exam_type_id"
            :options="availableExamTypes"
            :loading="loadingLists.examTypes"
            :error="!!fieldErrors.exam_type_id"
            placeholder="Select"
            search-placeholder="Search exam types…"
            @change="clearFieldError('exam_type_id')"
          />
          <p v-if="fieldErrors.exam_type_id" class="text-[11px] text-brand">{{ fieldErrors.exam_type_id }}</p>
        </div>

        <div class="flex flex-col gap-1">
          <label for="gm_exam_term" class="text-[12px] text-label">Exam Term <span class="text-brand">*</span></label>
          <SearchableSelect
            id="gm_exam_term"
            :ref="(el) => (fieldRefs.exam_term_id.value = el)"
            v-model="filters.exam_term_id"
            :options="availableExamTerms"
            :loading="loadingLists.examTerms"
            :error="!!fieldErrors.exam_term_id"
            placeholder="Select"
            search-placeholder="Search exam terms…"
            @change="clearFieldError('exam_term_id')"
          />
          <p v-if="fieldErrors.exam_term_id" class="text-[11px] text-brand">{{ fieldErrors.exam_term_id }}</p>
        </div>
      </div>

      <!-- Row 2: Department · Semester · Exam Year · Search -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5 mt-2.5">
        <div class="flex flex-col gap-1">
          <label for="gm_department" class="text-[12px] text-label">Department <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            id="gm_department"
            v-model="filters.department_id"
            :options="availableDepartments"
            :loading="loadingLists.departments"
            placeholder="All departments"
            search-placeholder="Search departments…"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="gm_semester" class="text-[12px] text-label">Semester <span class="text-brand">*</span></label>
          <SearchableSelect
            id="gm_semester"
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
          <label for="gm_exam_year" class="text-[12px] text-label">Exam Year <span class="text-brand">*</span></label>
          <input
            id="gm_exam_year"
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

    <!-- Roll-number check progress -->
    <section v-if="progress.active" class="bg-white rounded-2xl shadow-panel p-5 sm:p-6 mb-4" aria-live="polite">
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-10 h-10 rounded-xl bg-brand-blue/10 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-brand-blue animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 12a9 9 0 1 1-6.22-8.56" stroke-linecap="round" /></svg>
          </span>
          <div class="min-w-0">
            <h3 class="text-[15px] font-semibold text-gray-900 truncate">{{ progress.label }}&hellip;</h3>
            <p class="text-[12.5px] text-muted mt-0.5">Reading the student ID written on each answer sheet and comparing it with the roll number.</p>
          </div>
        </div>
        <button
          type="button"
          class="h-8 shrink-0 px-4 rounded-full border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 hover:border-brand hover:text-brand transition-colors disabled:opacity-50"
          :disabled="progress.cancelled"
          @click="cancelCheck"
        >
          {{ progress.cancelled ? 'Stopping…' : 'Cancel' }}
        </button>
      </div>

      <div class="mt-5">
        <div class="flex items-end justify-between mb-2">
          <p class="text-[13px] text-gray-700">
            <span class="text-[20px] font-bold text-gray-900 tabular-nums">{{ progress.checked }}</span>
            <span class="text-muted"> / {{ progress.total }} answer sheets checked</span>
          </p>
          <p class="text-[13px] font-semibold text-brand-blue tabular-nums">{{ progressPercent }}%</p>
        </div>
        <div class="h-2.5 w-full rounded-full bg-page-bg overflow-hidden" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
          <div class="h-full rounded-full bg-btn-gradient transition-[width] duration-500 ease-out" :style="{ width: `${progressPercent}%` }"></div>
        </div>
        <p class="mt-2 text-[12px] text-muted h-4">{{ progress.total ? progressEta : 'Preparing…' }}</p>
      </div>
    </section>

    <!-- Results -->
    <section v-if="searched && !progress.active" class="bg-white rounded-2xl shadow-panel p-3 sm:p-4">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 mb-3">
        <p class="text-[13px] text-gray-700">
          <template v-if="rowSearch.trim()">{{ visibleRows.length }} of {{ rows.length }} course{{ rows.length === 1 ? '' : 's' }} shown.</template>
          <template v-else>{{ rows.length }} course{{ rows.length === 1 ? '' : 's' }} found.</template>
        </p>
        <div class="relative w-full sm:w-72">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
          <input
            v-model="rowSearch"
            type="search"
            placeholder="Search course, department…"
            aria-label="Search results"
            class="w-full h-9 pl-8 pr-3 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
          />
        </div>
      </div>

      <div v-if="notCheckedTotal" class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-[12.5px] text-amber-800">
        <span><span class="font-semibold">{{ notCheckedTotal }}</span> answer sheet{{ notCheckedTotal === 1 ? ' has' : 's have' }} not been checked yet, so the roll number counts are incomplete.</span>
        <button type="button" class="h-7 px-3 rounded-lg bg-white border border-amber-300 font-semibold hover:bg-amber-100 transition-colors" @click="resumeCheck">Check now</button>
      </div>

      <p v-if="searchError" class="text-[13px] text-brand text-center py-8">{{ searchError }}</p>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[960px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-3 py-2.5 rounded-tl-xl">Course</th>
              <th class="px-3 py-2.5">Exam Term / Type</th>
              <th class="px-3 py-2.5 text-center">Sem.</th>
              <th class="px-3 py-2.5">Papers</th>
              <th class="px-3 py-2.5">Pending / Problem</th>
              <th class="px-3 py-2.5">Roll No.</th>
              <th class="px-3 py-2.5 text-center">Action</th>
              <th class="px-3 py-2.5 text-center rounded-tr-xl">Generate Marksheet</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="!visibleRows.length">
              <td colspan="8" class="px-3 py-8 text-center text-sm text-muted">
                {{ rows.length ? 'No courses match your search.' : 'No answer sheets found for this search.' }}
              </td>
            </tr>
            <tr v-for="row in visibleRows" v-else :key="row.course_id" class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0">
              <!-- Course (name, code, type) on one line; its packets'
                   department(s) below. -->
              <td class="px-3 py-2.5">
                <p class="font-medium leading-snug whitespace-nowrap">{{ row.course_name ? courseLabel(row.course_name, row.course_code, row.course_type) : '—' }}</p>
                <p v-for="dept in row.department_names?.length ? row.department_names : ['—']" :key="dept" class="mt-0.5 text-[11px] text-muted leading-snug">{{ dept }}</p>
              </td>
              <!-- Exam Term with the Exam Type below it. -->
              <td class="px-3 py-2.5 whitespace-nowrap">
                <p class="leading-snug">{{ row.exam_term_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.exam_type_name || '—' }}</p>
              </td>
              <td class="px-3 py-2.5 text-center">{{ ordinal(row.semester) }}</td>
              <!-- Label : value pairs, labels and values lined up. -->
              <td class="px-3 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Total</span><span class="text-muted">:</span><span class="font-semibold text-gray-900">{{ row.total_count }}</span>
                  <span class="text-muted">Evaluated</span><span class="text-muted">:</span><span class="font-semibold text-success">{{ row.evaluated_count }}</span>
                </div>
              </td>
              <td class="px-3 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Pending</span><span class="text-muted">:</span><span class="font-semibold text-brand">{{ row.pending_count }}</span>
                  <span class="text-muted">Problem</span><span class="text-muted">:</span><span class="font-semibold text-amber-600">{{ row.problem_count ?? 0 }}</span>
                </div>
              </td>
              <!-- Roll No. Matched / Mismatched, same label : value style;
                   View (or All Clear) sits beside the mismatched count. -->
              <td class="px-3 py-2.5">
                <div class="inline-grid grid-cols-[auto_auto_auto_auto] items-center gap-x-1.5 gap-y-0.5 text-[12px] leading-snug whitespace-nowrap">
                  <span class="text-muted">Matched</span><span class="text-muted">:</span><span class="font-semibold text-success">{{ row.matched_count }}</span><span></span>
                  <span class="text-muted">Mismatched</span><span class="text-muted">:</span>
                  <span class="font-semibold" :class="row.mismatched_count ? 'text-brand' : 'text-gray-800'">{{ row.mismatched_count }}</span>
                  <span class="pl-1">
                  <button
                    v-if="row.mismatched_count"
                    type="button"
                    class="h-6 inline-flex items-center gap-1 rounded-lg status-gradient-border px-2 text-[11.5px] font-medium text-gray-800 hover:text-brand-blue transition-colors"
                    @click="openReview(row)"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                    View
                  </button>
                  <span v-else-if="!row.not_checked_count" class="text-[11.5px] font-medium text-success">All Clear</span>
                  </span>
                </div>
              </td>
              <td class="px-3 py-2.5 text-center">
                <button
                  type="button"
                  class="h-7 inline-flex items-center gap-1.5 rounded-lg status-gradient-border px-2.5 text-[12px] font-medium text-gray-800 hover:text-brand-blue transition-colors disabled:opacity-50"
                  :disabled="recheckingCourseId !== null"
                  @click="recheck(row)"
                >
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10" /><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" /></svg>
                  Recheck
                </button>
              </td>
              <td class="px-3 py-2.5 text-center">
                <div v-if="marksheetReady(row)" class="inline-flex items-center gap-1.5">
                  <button
                    type="button"
                    class="h-7 inline-flex items-center gap-1 rounded-lg bg-success/10 border border-success/30 px-2.5 text-[12px] font-semibold text-success hover:bg-success/20 transition-colors disabled:opacity-50"
                    :disabled="downloadingMarksheet !== null"
                    @click="downloadMarksheet(row, 'excel')"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                    {{ downloadingMarksheet === `${row.course_id}:excel` ? '…' : 'Excel' }}
                  </button>
                  <button
                    type="button"
                    class="h-7 inline-flex items-center gap-1 rounded-lg bg-brand/10 border border-brand/30 px-2.5 text-[12px] font-semibold text-brand hover:bg-brand/20 transition-colors disabled:opacity-50"
                    :disabled="downloadingMarksheet !== null"
                    @click="downloadMarksheet(row, 'pdf')"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                    {{ downloadingMarksheet === `${row.course_id}:pdf` ? '…' : 'PDF' }}
                  </button>
                </div>
                <span v-else class="text-[11.5px] text-muted" :title="notReadyReason(row)">Not ready</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Mismatch review modal -->
    <div v-if="reviewRow" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="closeReview">
      <div class="bg-white rounded-2xl shadow-card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-soft">
          <div class="min-w-0">
            <h2 class="text-[15px] font-semibold text-gray-900 truncate">
              Roll Number Mismatch
              <span class="text-muted font-normal">· {{ courseLabel(reviewRow.course_name, reviewRow.course_code, reviewRow.course_type) }}</span>
            </h2>
            <p class="text-[12px] text-muted mt-0.5">Compare the ID written on each answer sheet with the system roll number.</p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span class="inline-flex items-center rounded-full bg-brand/10 text-brand text-[12px] font-semibold px-2.5 py-1">
              {{ reviewPagination.total }} mismatch{{ reviewPagination.total === 1 ? '' : 'es' }}
            </span>
            <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="closeReview">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>
        </div>

        <!-- Table -->
        <div class="flex-1 overflow-y-auto">
          <p v-if="reviewError" class="text-[13px] text-brand text-center py-8">{{ reviewError }}</p>
          <table v-else class="w-full text-left">
            <thead class="sticky top-0 z-10">
              <tr class="bg-subject-header text-white text-[11.5px] font-medium">
                <th class="pl-5 pr-2 py-2 w-10">
                  <input
                    type="checkbox"
                    class="w-4 h-4 accent-brand-blue cursor-pointer align-middle"
                    :checked="allOnPageSelected"
                    :indeterminate="someOnPageSelected"
                    :disabled="!reviewItems.length"
                    aria-label="Select all on this page"
                    @change="togglePage"
                  />
                </th>
                <th class="px-2 py-2">QR Code</th>
                <th class="px-2 py-2">System Roll No.</th>
                <th class="px-2 py-2">Written on Answer Sheet</th>
                <th class="pl-2 pr-5 py-2 text-center w-16">Action</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="reviewLoading">
                <td colspan="5" class="px-5 py-8 text-center text-sm text-muted">Loading&hellip;</td>
              </tr>
              <tr
                v-for="item in reviewItems"
                v-else
                :key="item.id"
                class="text-[12.5px] text-gray-800 border-b border-gray-100 last:border-b-0 cursor-pointer transition-colors"
                :class="selectedIds.has(item.id) ? 'bg-brand-blue/[0.06]' : 'hover:bg-gray-50'"
                @click="toggleRow(item.id)"
              >
                <td class="pl-5 pr-2 py-1.5" @click.stop>
                  <input
                    type="checkbox"
                    class="w-4 h-4 accent-brand-blue cursor-pointer align-middle"
                    :checked="selectedIds.has(item.id)"
                    :aria-label="`Select ${item.qr_code}`"
                    @change="toggleRow(item.id)"
                  />
                </td>
                <td class="px-2 py-1.5 font-semibold whitespace-nowrap">{{ item.qr_code || '—' }}</td>
                <td class="px-2 py-1.5 font-semibold whitespace-nowrap tracking-wide">{{ item.roll_no || '—' }}</td>
                <td class="px-2 py-1.5">
                  <div class="flex items-center gap-3">
                    <img
                      v-if="item.crop_url"
                      :src="resolveStorageUrl(item.crop_url)"
                      alt="Student ID as written on the answer sheet"
                      loading="lazy"
                      class="block h-8 w-auto max-w-[260px] rounded border border-input-border bg-white shrink-0"
                    />
                    <span v-if="item.student_id_read" class="font-semibold tracking-wide whitespace-nowrap" :title="'Read by the system'">
                      <span v-for="(d, i) in readDigits(item)" :key="i" :class="d.diff ? 'text-brand' : 'text-gray-500'">{{ d.ch }}</span>
                    </span>
                    <span v-else class="text-[11.5px] text-amber-600 font-medium whitespace-nowrap">{{ statusLabel(item.status) }}</span>
                  </div>
                </td>
                <td class="pl-2 pr-5 py-1.5 text-center relative" @click.stop>
                  <RowActionMenu width="w-44">
                    <button
                      type="button"
                      class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors disabled:opacity-50"
                      :disabled="!item.pdf_url"
                      @click="openAnswerSheet(item)"
                    >
                      View Answer Sheet
                    </button>
                  </RowActionMenu>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer -->
        <div class="border-t border-soft bg-gray-50/70 px-5 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="flex items-center gap-3 text-[12.5px]">
            <span class="text-gray-700"><span class="font-semibold text-gray-900">{{ selectedIds.size }}</span> selected</span>
            <button v-if="selectedIds.size" type="button" class="text-brand-blue hover:underline" @click="clearSelection">Clear</button>
            <Pagination
              v-if="reviewPagination.last_page > 1"
              :current-page="reviewPagination.current_page"
              :last-page="reviewPagination.last_page"
              :total="reviewPagination.total"
              @change="loadReview"
            />
          </div>
          <button
            type="button"
            class="h-9 inline-flex items-center justify-center gap-2 rounded-full bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="!selectedIds.size || confirming"
            @click="confirmSelectedMatch"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
            {{ confirming ? 'Saving…' : 'Written & System Roll No. are Same' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
