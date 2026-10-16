<script setup>
// "Generate QR & Scan" → Generate QR. Step 1: search the students sitting
// an exam (GET /qr/exam-students → the college main database's
// DataApi/examStudent). Every dropdown's value is the record's Ref Id
// (inserted_id — the main database's own id), never our own id, so only
// synced records are listed — and none the main database has deleted
// (deleted_from_main_db = 1, i.e. the API's delete_status 0).
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import { useToast } from '../composables/useToast'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import GenerateQrCodeModal from '../components/qr/GenerateQrCodeModal.vue'

// --- Dropdown lists (Ref Id → name) ---
const lists = reactive({ examTerms: [], examTypes: [], examSubTypes: [], programs: [], courses: [], departments: [] })
const loadingLists = reactive({ examTerms: true, examTypes: true, examSubTypes: true, programs: true, courses: false, departments: true })

async function loadList(key, endpoint, fields, toName) {
  try {
    const res = await api.get(endpoint, { params: { status: 'all', is_active: 'yes', table_fields: ['inserted_id', 'deleted_from_main_db', ...fields] }, skipLoader: true })
    lists[key] = (res.data.data || [])
      .filter((row) => row.inserted_id !== null && row.inserted_id !== undefined && !row.deleted_from_main_db)
      .map((row) => ({ id: row.inserted_id, name: toName(row) }))
      .sort((a, b) => a.name.localeCompare(b.name))
  } catch {
    // Non-fatal — that dropdown just stays empty.
  } finally {
    loadingLists[key] = false
  }
}

onMounted(() => {
  loadList('examTerms', '/exam-terms', ['name'], (r) => r.name)
  loadList('examTypes', '/exam-types', ['name'], (r) => r.name)
  loadList('programs', '/programs', ['name', 'label'], (r) => (r.label ? `${r.name} (${r.label})` : r.name))
  // "Name (Code)", like every other page's department dropdown.
  loadList('departments', '/departments', ['name', 'code'], (r) => (r.code ? `${r.name} (${r.code})` : r.name))
  loadExamSubTypes()
})

// Exam sub types carry their exam term's / exam type's Ref Id too, so the
// dropdown only lists the ones under the term/type picked above.
async function loadExamSubTypes() {
  try {
    const res = await api.get('/exam-sub-types', { params: { status: 'all', is_active: 'yes' }, skipLoader: true })
    lists.examSubTypes = (res.data.data || [])
      .filter((row) => row.inserted_id !== null && row.inserted_id !== undefined && !row.deleted_from_main_db)
      .map((row) => ({ id: row.inserted_id, name: row.name, termRef: row.exam_term?.inserted_id ?? null, typeRef: row.exam_type?.inserted_id ?? null }))
      .sort((a, b) => a.name.localeCompare(b.name))
  } catch {
    // Non-fatal — the dropdown just stays empty.
  } finally {
    loadingLists.examSubTypes = false
  }
}

// Courses come only after a program is picked — the ones mapped to it.
let coursesRequest = 0
async function onProgramChange() {
  clearFieldError('program_id')
  filters.course_id = ''
  lists.courses = []
  if (!filters.program_id) return
  const request = ++coursesRequest
  loadingLists.courses = true
  try {
    const res = await api.get('/qr/program-courses', { params: { program_id: filters.program_id }, skipLoader: true })
    if (request === coursesRequest) lists.courses = res.data.data || []
  } catch {
    // Non-fatal — the dropdown just stays empty.
  } finally {
    if (request === coursesRequest) loadingLists.courses = false
  }
}

const examSubTypeOptions = computed(() => lists.examSubTypes.filter((o) =>
  (!filters.exam_term_id || String(o.termRef) === String(filters.exam_term_id))
  && (!filters.exam_type_id || String(o.typeRef) === String(filters.exam_type_id))))

// Changing the term/type drops a sub type that isn't under it any more;
// picking a sub type first fills in its term and type.
function onTermOrTypeChange(field) {
  clearFieldError(field)
  if (filters.enroll_session_id && !examSubTypeOptions.value.some((o) => String(o.id) === String(filters.enroll_session_id))) filters.enroll_session_id = ''
}
function onSubTypeChange() {
  clearFieldError('enroll_session_id')
  const subType = lists.examSubTypes.find((o) => String(o.id) === String(filters.enroll_session_id))
  if (!subType) return
  if (subType.termRef && !filters.exam_term_id) { filters.exam_term_id = subType.termRef; clearFieldError('exam_term_id') }
  if (subType.typeRef && !filters.exam_type_id) { filters.exam_type_id = subType.typeRef; clearFieldError('exam_type_id') }
}

const semesterOptions = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, name: `Semester ${i + 1}` }))

// --- Search ---
// Same params, same order, as the API (enroll_session_id = exam sub type).
const filters = reactive({ exam_term_id: '', exam_type_id: '', enroll_session_id: '', program_id: '', course_id: '', semester: '', exam_year: '', department_id: '', batch: '' })
const fieldErrors = reactive({ exam_term_id: '', exam_type_id: '', enroll_session_id: '', program_id: '', course_id: '', semester: '' })
const searching = ref(false)
const searchError = ref('')
const results = ref(null) // { items, total, student_count } once searched

// Search within the results (as you type — on keyup): registration no,
// name, course name/code, program, department, semester, batch.
const listSearch = ref('')
const LIST_SEARCH_FIELDS = ['registration_no', 'name', 'course_name', 'course_code', 'program_name', 'department_name', 'semester', 'batch']
const visibleItems = computed(() => {
  const items = results.value?.items || []
  const q = listSearch.value.trim().toLowerCase()
  if (!q) return items
  return items.filter((row) => LIST_SEARCH_FIELDS.some((field) => String(row[field] ?? '').toLowerCase().includes(q)))
})

function clearFieldError(field) {
  if (field in fieldErrors) fieldErrors[field] = ''
}

// The search the current list came from — what the Generate QR Code modal
// shows (snapshotted at search time, so editing the filters afterwards
// doesn't change it): the fixed params by name, plus Department / Exam
// Year / Batch to pre-fill the modal's own fields.
const searched = ref({ params: [], refs: {}, department_id: '', exam_year: '', batch: '' })
function optionName(options, id) {
  return options.find((o) => String(o.id) === String(id))?.name || ''
}
function describeFilters() {
  return {
    params: [
      { label: 'Exam Term', value: optionName(lists.examTerms, filters.exam_term_id) },
      { label: 'Exam Type', value: optionName(lists.examTypes, filters.exam_type_id) },
      { label: 'Exam Sub Type', value: optionName(lists.examSubTypes, filters.enroll_session_id) },
      { label: 'Program', value: optionName(lists.programs, filters.program_id) },
      { label: 'Course', value: optionName(lists.courses, filters.course_id) },
      { label: 'Semester', value: optionName(semesterOptions, filters.semester) },
    ],
    // The fixed params as Ref Ids — what Generate sends back.
    refs: {
      exam_term_id: filters.exam_term_id,
      exam_type_id: filters.exam_type_id,
      enroll_session_id: filters.enroll_session_id,
      program_id: filters.program_id,
      course_id: filters.course_id,
      semester: filters.semester,
    },
    department_id: filters.department_id,
    exam_year: filters.exam_year.trim(),
    batch: filters.batch.trim(),
  }
}

// --- Generate QR Code ---
const showQrModal = ref(false)

// The value every row in the list shares for `field` — '' if they differ
// or any row has none.
function commonValue(field) {
  const values = new Set((results.value?.items || []).map((row) => String(row[field] ?? '').trim()))
  const [only] = values
  return values.size === 1 && only ? only : ''
}

// What the modal's Department / Exam Year / Batch start with: the search's
// own value, else the one the whole list shares (a department only if it's
// one of the dropdown's — synced, not deleted).
const qrModalInitial = computed(() => {
  const listDepartment = commonValue('department_id')
  return {
    department_id: searched.value.department_id
      || (lists.departments.some((o) => String(o.id) === listDepartment) ? listDepartment : ''),
    exam_year: searched.value.exam_year || commonValue('exam_year'),
    batch: searched.value.batch || commonValue('batch'),
  }
})
const toast = useToast()
function onQrGenerated(result) {
  showQrModal.value = false
  const range = result.first_qr ? ` QR ${result.first_qr}–${result.last_qr}.` : ''
  const packet = result.existing_packet ? ` Added to existing packet ${result.packet_code}.` : ` Packet ${result.packet_code}.`
  if (result.created > 0) toast.success(`${result.message}${range}${packet}`)
  else toast.info(result.message)
}

async function runSearch() {
  fieldErrors.exam_term_id = filters.exam_term_id ? '' : 'Select an exam term.'
  fieldErrors.exam_type_id = filters.exam_type_id ? '' : 'Select an exam type.'
  fieldErrors.enroll_session_id = filters.enroll_session_id ? '' : 'Select an exam sub type.'
  fieldErrors.program_id = filters.program_id ? '' : 'Select a program.'
  fieldErrors.course_id = filters.course_id ? '' : filters.program_id ? 'Select a course.' : 'Select a program first.'
  fieldErrors.semester = filters.semester ? '' : 'Select a semester.'
  if (Object.values(fieldErrors).some(Boolean)) return

  searching.value = true
  searchError.value = ''
  try {
    const params = {}
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== null) params[key] = typeof value === 'string' ? value.trim() : value
    })
    const snapshot = describeFilters()
    const res = await api.get('/qr/exam-students', { params, skipLoader: true })
    results.value = res.data.data
    searched.value = snapshot
    listSearch.value = ''
  } catch (err) {
    const errors = err.response?.data?.errors
    if (errors) Object.entries(errors).forEach(([key, msgs]) => { if (key in fieldErrors) fieldErrors[key] = msgs[0] })
    searchError.value = err.response?.data?.message || 'Could not fetch the students.'
    results.value = null
  } finally {
    searching.value = false
  }
}

function resetSearch() {
  Object.keys(filters).forEach((key) => (filters[key] = ''))
  lists.courses = []
  listSearch.value = ''
  Object.keys(fieldErrors).forEach((key) => (fieldErrors[key] = ''))
  searchError.value = ''
  results.value = null
}
</script>

<template>
  <div>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-2.5 flex items-center gap-3 min-h-[44px]">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Generate QR &amp; Scan</span>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Generate QR</span>
    </div>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-2 mb-1.5 flex items-center gap-2.5">
      <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3" />
        </svg>
      </span>
      <h2 class="text-white text-[15px] font-medium truncate">Generate QR</h2>
    </section>

    <!-- Search -->
    <section class="bg-white rounded-[20px] shadow-card px-3 sm:px-4 py-2.5 sm:py-3 mb-4">
      <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900 mb-1.5">Select Exam Details</h3>

      <!-- Exam Term · Exam Type · Exam Sub Type · Program · Course · Semester · Exam Year · Department · Batch · Search -->
      <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-5 gap-x-2.5 gap-y-1.5">
        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_exam_term" class="text-[11px] text-label">Exam Term <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_exam_term"
            v-model="filters.exam_term_id"
            :options="lists.examTerms"
            :loading="loadingLists.examTerms"
            :error="!!fieldErrors.exam_term_id"
            placeholder="Select"
            search-placeholder="Search exam terms…"
            @change="onTermOrTypeChange('exam_term_id')"
          />
          <p v-if="fieldErrors.exam_term_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.exam_term_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_exam_type" class="text-[11px] text-label">Exam Type <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_exam_type"
            v-model="filters.exam_type_id"
            :options="lists.examTypes"
            :loading="loadingLists.examTypes"
            :error="!!fieldErrors.exam_type_id"
            placeholder="Select"
            search-placeholder="Search exam types…"
            @change="onTermOrTypeChange('exam_type_id')"
          />
          <p v-if="fieldErrors.exam_type_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.exam_type_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_exam_sub_type" class="text-[11px] text-label">Exam Sub Type <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_exam_sub_type"
            v-model="filters.enroll_session_id"
            :options="examSubTypeOptions"
            :loading="loadingLists.examSubTypes"
            :error="!!fieldErrors.enroll_session_id"
            placeholder="Select"
            search-placeholder="Search exam sub types…"
            @change="onSubTypeChange"
          />
          <p v-if="fieldErrors.enroll_session_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.enroll_session_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_program" class="text-[11px] text-label">Program <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_program"
            v-model="filters.program_id"
            :options="lists.programs"
            :loading="loadingLists.programs"
            :error="!!fieldErrors.program_id"
            placeholder="Select"
            search-placeholder="Search programs…"
            @change="onProgramChange"
          />
          <p v-if="fieldErrors.program_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.program_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_course" class="text-[11px] text-label">Course <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_course"
            v-model="filters.course_id"
            :options="lists.courses"
            :loading="loadingLists.courses"
            :disabled="!filters.program_id"
            :error="!!fieldErrors.course_id"
            :placeholder="filters.program_id ? (lists.courses.length || loadingLists.courses ? 'Select' : 'No courses mapped to this program') : 'Select a program first'"
            search-placeholder="Search courses…"
            @change="clearFieldError('course_id')"
          />
          <p v-if="fieldErrors.course_id" class="text-[10.5px] text-brand leading-tight">{{ fieldErrors.course_id }}</p>
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_semester" class="text-[11px] text-label">Semester <span class="text-brand">*</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_semester"
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
          <label for="qr_exam_year" class="text-[11px] text-label">Exam Year <span class="text-muted">(optional)</span></label>
          <input
            id="qr_exam_year"
            v-model="filters.exam_year"
            type="text"
            maxlength="20"
            placeholder="e.g. 2026-2027"
            class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[11px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            @keyup.enter="runSearch"
          />
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_department" class="text-[11px] text-label">Department <span class="text-muted">(optional)</span></label>
          <SearchableSelect
            dense
            wrap-options
            id="qr_department"
            v-model="filters.department_id"
            :options="lists.departments"
            :loading="loadingLists.departments"
            placeholder="All departments"
            search-placeholder="Search departments…"
          />
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label for="qr_batch" class="text-[11px] text-label">Batch <span class="text-muted">(optional)</span></label>
          <input
            id="qr_batch"
            v-model="filters.batch"
            type="text"
            inputmode="numeric"
            maxlength="20"
            placeholder="e.g. 2025"
            class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[11px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            @keyup.enter="runSearch"
          />
        </div>

        <div class="flex flex-col gap-0.5 min-w-0">
          <label class="text-[11px] text-label invisible" aria-hidden="true">Search</label>
          <div class="flex gap-2">
            <button
              type="button"
              class="h-8 flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-btn-gradient text-white text-[12px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="searching"
              @click="runSearch"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
              {{ searching ? 'Searching…' : 'Search' }}
            </button>
            <button
              type="button"
              class="h-8 inline-flex items-center justify-center rounded-xl border border-input-border bg-white text-[12px] font-semibold text-gray-700 px-3.5 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="searching"
              @click="resetSearch"
            >
              Reset
            </button>
          </div>
        </div>
      </div>
    </section>

    <div v-if="searchError" class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-[13px] text-brand mb-4">{{ searchError }}</div>

    <!-- Results -->
    <section v-if="results" class="bg-white rounded-[20px] shadow-card overflow-hidden mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3 border-b border-soft">
        <h3 class="text-[14px] sm:text-[15px] font-semibold text-gray-900">Students</h3>
        <div class="flex flex-wrap items-center gap-2 text-[12px]">
          <span class="rounded-full bg-soft text-brand-blue px-3 py-1 font-semibold whitespace-nowrap">{{ results.student_count }} student{{ results.student_count === 1 ? '' : 's' }}</span>
          <div class="relative w-full sm:w-64">
            <svg class="w-3.5 h-3.5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
            <input
              :value="listSearch"
              type="text"
              placeholder="Search name, registration no, course…"
              class="w-full h-8 pl-8 pr-3 rounded-xl bg-input-bg text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
              @keyup="listSearch = $event.target.value"
            />
          </div>
          <span v-if="listSearch.trim()" class="rounded-full bg-page-bg text-gray-600 px-3 py-1 font-semibold whitespace-nowrap">Showing {{ visibleItems.length }} of {{ results.total }}</span>
          <button
            v-if="results.student_count > 0"
            type="button"
            class="h-8 inline-flex items-center gap-1.5 rounded-xl bg-btn-gradient text-white text-[12px] font-semibold px-4 hover:opacity-90 transition-opacity whitespace-nowrap disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="searching"
            @click="showQrModal = true"
          >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3" /></svg>
            Generate QR Code
          </button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium w-px whitespace-nowrap">#</th>
              <th class="px-4 py-1.5 font-medium">Student</th>
              <th class="px-4 py-1.5 font-medium">Course</th>
              <th class="px-4 py-1.5 font-medium">Program</th>
              <th class="px-4 py-1.5 font-medium">Department</th>
              <th class="px-4 py-1.5 font-medium">Semester</th>
              <th class="px-4 py-1.5 font-medium">Batch</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="!results.items.length">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No students found for this search.</td>
            </tr>
            <tr v-else-if="!visibleItems.length">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No students match "{{ listSearch.trim() }}".</td>
            </tr>
            <tr
              v-for="(row, index) in visibleItems"
              :key="`${row.registration_no}-${row.course_code}-${index}`"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap">
                  # {{ index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1.5">
                <p class="font-semibold leading-snug">{{ row.name || '—' }}</p>
                <p class="text-[11px] text-muted leading-snug">{{ row.registration_no || '—' }}</p>
              </td>
              <td class="px-4 py-1.5">
                <p class="leading-snug">{{ row.course_name || '—' }}</p>
                <p v-if="row.course_code" class="text-[11px] text-muted leading-snug">{{ row.course_code }}</p>
              </td>
              <td class="px-4 py-1">{{ row.program_name || '—' }}</td>
              <td class="px-4 py-1">{{ row.department_name || '—' }}</td>
              <td class="px-4 py-1">{{ row.semester || '—' }}</td>
              <td class="px-4 py-1">{{ row.batch || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <GenerateQrCodeModal
      v-if="showQrModal && results"
      :params="searched.params"
      :student-count="results.student_count"
      :departments="lists.departments"
      :departments-loading="loadingLists.departments"
      :initial="qrModalInitial"
      :search-refs="searched.refs"
      @close="showQrModal = false"
      @generated="onQrGenerated"
    />
  </div>
</template>
