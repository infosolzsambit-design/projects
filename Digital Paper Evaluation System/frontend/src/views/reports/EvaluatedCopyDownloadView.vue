<script setup>
// Sidebar's Report → Evaluated Copy Download — every evaluated copy of a
// search in one ZIP. Same search fields as the other reports. Each copy is
// built in the browser exactly like Reset Evaluation's "Download evaluated"
// (utils/evaluatedPdf.js: the uploaded scan + the teacher's annotations +
// the marks stamp, with the top sheet in front), then zipped (utils/zip.js).
// See EvaluatedCopyDownloadController on the backend. Gated by the
// 'evaluated-copy-download' permission (show/hide only).
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api, { resolveStorageUrl } from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../composables/useToast'
import SearchableSelect from '../../components/common/SearchableSelect.vue'
import { useProgramsStore } from '../../stores/programs'
import { useProgramCourses } from '../../composables/useProgramCourses'
import { useExamSubTypes } from '../../composables/useExamSubTypes'
import { courseLabel } from '../../utils/course'
import { ordinal } from '../../utils/ordinal'
import { buildEvaluatedPdf, saveBlob } from '../../utils/evaluatedPdf'
import { ZipWriter } from '../../utils/zip'

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
  exam_sub_type_id: '', // optional — the packet's exam sub type (Generate QR)
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
  if (!authStore.can('evaluated-copy-download')) return
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
const summary = ref({ evaluated: 0, not_evaluated: 0, absent: 0, no_file: 0 })
const downloadable = computed(() => rows.value.filter((row) => row.has_file))

function searchParams() {
  const params = { ...filters }
  if (!params.teacher_id) delete params.teacher_id
  if (!params.department_id) delete params.department_id
  if (!params.exam_sub_type_id) delete params.exam_sub_type_id
  return params
}

async function runSearch() {
  if (!validateFilters()) return
  searching.value = true
  searchError.value = ''
  try {
    const res = await api.get('/reports/evaluated-copies', { params: searchParams() })
    rows.value = res.data.data.rows
    summary.value = res.data.data.summary
    searched.value = true
  } catch (err) {
    searchError.value = err.response?.data?.message || 'Could not load the evaluated copies.'
  } finally {
    searching.value = false
  }
}

// --- ZIP download ----------------------------------------------------------
// Copies are built a few at a time (CONCURRENCY), each from three fetches:
// its data (annotations + marks), the uploaded scan, and — when "include
// top sheet" is on — the generated top sheet. A copy that fails is skipped
// and listed in _skipped.txt inside the ZIP rather than failing the lot.
const CONCURRENCY = 3
const includeTopSheet = ref(true)
const downloading = ref(false)
const progress = reactive({ done: 0, total: 0, failed: 0 })
let cancelled = false

function safe(part) {
  return String(part ?? '').trim().replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ')
}
function copyFileName(row) {
  const id = safe(row.script_code) || `answer-sheet-${row.id}`
  const reg = safe(row.registration_no || row.roll_no)
  return reg ? `${id}_${reg}.pdf` : `${id}.pdf`
}
function zipFileName() {
  const course = availableCourses.value.find((c) => String(c.id) === String(filters.course_id))
  const parts = ['evaluated-copies', filters.program_name, course?.code || course?.name, `sem-${filters.semester}`, filters.exam_year]
  return `${parts.filter(Boolean).map(safe).join('_')}.zip`
}

async function buildCopy(row) {
  const { data } = await api.get(`/reports/evaluated-copies/${row.id}`, { skipLoader: true })
  const sheet = data.data
  const [scan, cover] = await Promise.all([
    fetch(resolveStorageUrl(sheet.pdf_url)).then((res) => {
      if (!res.ok) throw new Error('uploaded file not found')
      return res.arrayBuffer()
    }),
    includeTopSheet.value
      ? api.get(`/reports/answer-book-top-sheet/${row.id}/view`, { params: { page_numbers: 0 }, responseType: 'arraybuffer', skipLoader: true })
        .then((res) => res.data)
        .catch(() => {
          throw new Error('top sheet could not be generated')
        })
      : Promise.resolve(null),
  ])
  return buildEvaluatedPdf(scan, {
    annotations: sheet.draft_annotations,
    marks: sheet.marks,
    maxMarks: sheet.max_marks,
    coverPdfBytes: cover,
  })
}

async function downloadZip() {
  const list = downloadable.value
  if (!list.length) return
  cancelled = false
  downloading.value = true
  Object.assign(progress, { done: 0, total: list.length, failed: 0 })
  const zip = new ZipWriter()
  const skipped = []
  let next = 0

  async function worker() {
    while (!cancelled && next < list.length) {
      const row = list[next++]
      try {
        zip.add(copyFileName(row), await buildCopy(row))
      } catch (err) {
        skipped.push(`${row.script_code || row.id}  ${row.registration_no || row.roll_no || ''}  — ${err.response?.data?.message || err.message || 'failed'}`)
        progress.failed++
      }
      progress.done++
    }
  }

  try {
    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, list.length) }, worker))
    if (cancelled) {
      toast.error('Download cancelled.')
      return
    }
    if (skipped.length) zip.add('_skipped.txt', `These copies could not be added:\r\n\r\n${skipped.join('\r\n')}\r\n`)
    if (progress.failed === list.length) {
      toast.error('None of the copies could be built — see the browser console for details.')
      console.error('Evaluated copy download — skipped:', skipped)
      return
    }
    saveBlob(zip.toBlob(), zipFileName())
    const added = list.length - progress.failed
    if (progress.failed) toast.error(`${added} cop${added === 1 ? 'y' : 'ies'} downloaded, ${progress.failed} skipped — see _skipped.txt in the ZIP.`)
    else toast.success(`${added} evaluated cop${added === 1 ? 'y' : 'ies'} downloaded.`)
  } finally {
    downloading.value = false
  }
}

function cancelDownload() {
  cancelled = true
}

// Leaving the page mid-download stops it.
onBeforeUnmount(cancelDownload)
const progressPct = computed(() => (progress.total ? Math.round((progress.done / progress.total) * 100) : 0))

// Course dropdown follows Program — its mapped courses, or every course if it has none.
const courseOptions = useProgramCourses(filters, availableCourses)
const { options: examSubTypeOptions, loading: examSubTypesLoading } = useExamSubTypes(filters)
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('evaluated-copy-download')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-2.5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Evaluated Copy</span>
    </div>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-2 mb-1.5 flex items-center gap-3">
      <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
      </span>
      <h2 class="text-white text-[15px] font-medium truncate">Evaluated Copy</h2>
    </section>

    <!-- Search -->
    <section class="bg-white rounded-[20px] shadow-card px-3 sm:px-4 py-2.5 sm:py-3 mb-4">
      <div class="flex items-center gap-2 mb-1.5">
        <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900">Select Exam Details</h3>
      </div>

      <!-- Row 1: Program · Course · Exam Type · Exam Term · Exam Sub Type (same layout as
           Assign Teacher / Generate Marksheet — Program and Course wider). -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr_1fr] gap-x-2.5 gap-y-1.5">
        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_program" class="text-[11px] text-label">Program <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            id="ecd_program"
            :ref="(el) => (fieldRefs.program_name.value = el)"
            v-model="filters.program_name"
            :options="availablePrograms"
            :loading="programsLoading"
            :error="!!fieldErrors.program_name"
            placeholder="Select program"
            search-placeholder="Search programs…"
            @change="clearFieldError('program_name')"
          />
          <p v-if="fieldErrors.program_name" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.program_name }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_course" class="text-[11px] text-label">Course <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            id="ecd_course"
            :ref="(el) => (fieldRefs.course_id.value = el)"
            v-model="filters.course_id"
            :options="courseOptions"
            :loading="coursesLoading"
            :error="!!fieldErrors.course_id"
            placeholder="Select course"
            search-placeholder="Search courses…"
            @change="clearFieldError('course_id')"
          />
          <p v-if="fieldErrors.course_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.course_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_exam_type" class="text-[11px] text-label">Exam Type <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            id="ecd_exam_type"
            :ref="(el) => (fieldRefs.exam_type_id.value = el)"
            v-model="filters.exam_type_id"
            :options="availableExamTypes"
            :loading="examTypesLoading"
            :error="!!fieldErrors.exam_type_id"
            placeholder="Select"
            search-placeholder="Search exam types…"
            @change="clearFieldError('exam_type_id')"
          />
          <p v-if="fieldErrors.exam_type_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.exam_type_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_exam_term" class="text-[11px] text-label">Exam Term <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            id="ecd_exam_term"
            :ref="(el) => (fieldRefs.exam_term_id.value = el)"
            v-model="filters.exam_term_id"
            :options="availableExamTerms"
            :loading="examTermsLoading"
            :error="!!fieldErrors.exam_term_id"
            placeholder="Select"
            search-placeholder="Search exam terms…"
            @change="clearFieldError('exam_term_id')"
          />
          <p v-if="fieldErrors.exam_term_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.exam_term_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_exam_sub_type" class="text-[11px] text-label">Exam Sub Type <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            dense
            id="ecd_exam_sub_type"
            v-model="filters.exam_sub_type_id"
            :options="examSubTypeOptions"
            :loading="examSubTypesLoading"
            placeholder="All sub types"
            search-placeholder="Search exam sub types…"
          />
        </div>
      </div>

      <!-- Row 2: Department · Semester · Exam Year · Teacher · Search -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.4fr_auto] gap-x-2.5 gap-y-1.5 mt-1.5">
        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_department" class="text-[11px] text-label">Department <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            dense
            id="ecd_department"
            v-model="filters.department_id"
            :options="availableDepartments"
            :loading="departmentsLoading"
            placeholder="All departments"
            search-placeholder="Search departments…"
          />
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_semester" class="text-[11px] text-label">Semester <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            id="ecd_semester"
            :ref="(el) => (fieldRefs.semester.value = el)"
            v-model="filters.semester"
            :options="semesterOptions"
            :error="!!fieldErrors.semester"
            placeholder="Select"
            search-placeholder="Search semesters…"
            @change="clearFieldError('semester')"
          />
          <p v-if="fieldErrors.semester" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.semester }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_exam_year" class="text-[11px] text-label">Exam Year <span class="text-brand">*</span></label>
          <input
            id="ecd_exam_year"
            :ref="(el) => (fieldRefs.exam_year.value = el)"
            v-model="filters.exam_year"
            type="number"
            class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[11px] text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
            :class="fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
            @input="clearFieldError('exam_year')"
          />
          <p v-if="fieldErrors.exam_year" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.exam_year }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="ecd_teacher" class="text-[11px] text-label">Teacher <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            dense
            id="ecd_teacher"
            v-model="filters.teacher_id"
            :options="availableTeachers"
            :loading="teachersLoading"
            placeholder="All teachers"
            search-placeholder="Search teachers…"
          />
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label class="text-[11px] text-label invisible" aria-hidden="true">Search</label>
          <button
            type="button"
            class="h-8 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[12px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed self-start"
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
      <p v-if="searchError" class="text-[13px] text-brand text-center py-8">{{ searchError }}</p>

      <template v-else>
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2.5 mb-3">
          <div class="text-[13px] text-gray-700">
            <p>
              <span class="font-semibold text-success">{{ downloadable.length }}</span> evaluated cop{{ downloadable.length === 1 ? 'y' : 'ies' }} ready to download.
            </p>
            <p class="text-[12px] text-muted mt-0.5">
              Not included:
              {{ summary.not_evaluated }} not evaluated yet · {{ summary.absent }} absent<template v-if="summary.no_file"> · {{ summary.no_file }} evaluated but with no uploaded file</template>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-1.5 text-[12.5px] text-gray-700 cursor-pointer select-none">
              <input v-model="includeTopSheet" type="checkbox" class="w-3.5 h-3.5 cursor-pointer" :disabled="downloading" />
              Include top sheet as first page
            </label>
            <button
              v-if="!downloading"
              type="button"
              class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="!downloadable.length"
              @click="downloadZip"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
              Download ZIP
            </button>
            <button
              v-else
              type="button"
              class="h-9 inline-flex items-center gap-1.5 rounded-lg border border-brand text-brand text-[12.5px] font-semibold px-4 hover:bg-brand/5 transition-colors"
              @click="cancelDownload"
            >
              Cancel
            </button>
          </div>
        </div>

        <!-- Progress while the copies are being built -->
        <div v-if="downloading" class="mb-3 rounded-xl bg-input-bg px-3 py-2.5">
          <div class="flex items-center justify-between text-[12px] text-gray-700 mb-1.5">
            <span>Preparing evaluated copies… keep this page open.</span>
            <span class="font-semibold">{{ progress.done }} / {{ progress.total }}<template v-if="progress.failed"> · <span class="text-brand">{{ progress.failed }} skipped</span></template></span>
          </div>
          <div class="h-2 rounded-full bg-white overflow-hidden">
            <div class="h-full rounded-full bg-brand-blue transition-all" :style="{ width: progressPct + '%' }"></div>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[820px] text-left">
            <thead>
              <tr class="bg-subject-header text-white text-[12px] font-medium whitespace-nowrap">
                <th class="px-2 py-2.5 rounded-tl-xl text-center w-px whitespace-nowrap">#</th>
                <th class="px-2 py-2.5">QR Code</th>
                <th class="px-2 py-2.5">Student</th>
                <th class="px-2 py-2.5">Evaluated By</th>
                <th class="px-2 py-2.5 text-center">Marks</th>
                <th class="px-2 py-2.5 rounded-tr-xl">Evaluated At</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="!rows.length">
                <td colspan="6" class="px-3 py-8 text-center text-sm text-muted">No evaluated copies for this search.</td>
              </tr>
              <tr v-for="(row, i) in rows" :key="row.id" class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0" :class="row.has_file ? '' : 'opacity-60'">
                <td class="px-2 py-2 text-center text-muted">{{ i + 1 }}</td>
                <td class="px-2 py-2 whitespace-nowrap font-medium">
                  {{ row.script_code || '—' }}
                  <span v-if="!row.has_file" class="ml-1 inline-flex items-center rounded-full bg-brand/10 text-brand px-1.5 py-px text-[10px] font-semibold">No file</span>
                </td>
                <td class="px-2 py-2">
                  <p class="font-medium leading-snug">{{ row.name || '—' }}</p>
                  <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.registration_no || row.roll_no || '—' }}</p>
                </td>
                <td class="px-2 py-2 whitespace-nowrap">
                  <p class="leading-snug">{{ row.teacher_name || '—' }}</p>
                  <p v-if="row.teacher_emp_code" class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.teacher_emp_code }}</p>
                </td>
                <td class="px-2 py-2 text-center whitespace-nowrap font-semibold">{{ row.marks }}<span v-if="row.full_marks" class="font-normal text-muted"> / {{ row.full_marks }}</span></td>
                <td class="px-2 py-2 whitespace-nowrap">{{ row.evaluated_at || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>
  </div>
</template>
