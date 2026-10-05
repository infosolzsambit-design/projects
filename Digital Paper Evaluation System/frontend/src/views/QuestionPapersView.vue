<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useConfirm } from '../composables/useConfirm'
import { useToast } from '../composables/useToast'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import ReplaceQuestionPaperPdfModal from '../components/questionPapers/ReplaceQuestionPaperPdfModal.vue'
import { courseLabel, typeSuffix } from '../utils/course'
import { ordinal } from '../utils/ordinal'
import { formatDateTime } from '../utils/date'

const router = useRouter()
const { confirmDialog } = useConfirm()
const toast = useToast()
const authStore = useAuthStore()
const examYearStore = useExamYearStore()

const papers = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'draft' | 'ready'
const departmentFilter = ref('') // '' | department id
const courseFilter = ref('') // '' | course id
const examTermFilter = ref('') // '' | exam term id
const semesterFilter = ref('') // '' | 1-12

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchPapers(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.status = statusFilter.value
    if (departmentFilter.value) params.department_id = departmentFilter.value
    if (courseFilter.value) params.course_id = courseFilter.value
    if (examTermFilter.value) params.exam_term_id = examTermFilter.value
    if (semesterFilter.value) params.semester = semesterFilter.value
    // Only this page forces exam_year — the endpoint itself stays
    // optional-only server-side (it's shared with AssignTeacherView.vue's
    // own cross-year bulk load, which must never be scoped down — see
    // QuestionPaperController::index()'s own comment) so this list is the
    // one actually enforcing "one year at a time" for anyone but a super
    // admin, per stores/examYear.js's own docblock.
    if (!authStore.user?.is_super_admin) params.exam_year = examYearStore.selectedYear

    const res = await api.get('/question-papers', { params })
    papers.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load question papers.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchPapers(1)
}

// Filter panel — same style as the other lists' filters (labelled
// dropdowns + Reset / Search).
const SEMESTER_OPTIONS = Array.from({ length: 12 }, (_, i) => i + 1)
const availableDepartments = ref([])
const availableCourses = ref([])
const availableExamTerms = ref([])
async function loadFilterOptions() {
  try {
    const [departmentsRes, coursesRes, examTermsRes] = await Promise.all([
      api.get('/departments', { params: { status: 'all', table_fields: ['name', 'code'] } }),
      api.get('/courses', { params: { status: 'all', table_fields: ['name', 'code', 'type'] } }),
      api.get('/exam-terms', { params: { status: 'all', table_fields: ['name'] } }),
    ])
    availableDepartments.value = departmentsRes.data.data.map((d) => ({ ...d, name: d.code ? `${d.name} (${d.code})` : d.name }))
    availableCourses.value = coursesRes.data.data.map((c) => ({ ...c, name: courseLabel(c.name, c.code, c.type) }))
    availableExamTerms.value = examTermsRes.data.data
  } catch {
    // Non-fatal — the dropdowns just stay empty; search still works.
  }
}
loadFilterOptions()

const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
function applyFilters() {
  showFilter.value = false
  fetchPapers(1)
}
function resetFilters() {
  departmentFilter.value = ''
  courseFilter.value = ''
  examTermFilter.value = ''
  semesterFilter.value = ''
  statusFilter.value = ''
  showFilter.value = false
  fetchPapers(1)
}
onMounted(() => document.addEventListener('click', closeFilter))

// Departments column — a short summary plus an eye button: hover for a
// quick preview (teleported to <body> so the table can't clip it), click
// for the full list in a modal.
const DEPT_PREVIEW_LIMIT = 8
const deptPreview = ref(null) // { paper, top, bottom, left }
const deptModalPaper = ref(null)

function departmentLabel(d) {
  return d.code ? `${d.name} (${d.code})` : d.name
}
function showDeptPreview(paper, event) {
  const rect = event.currentTarget.getBoundingClientRect()
  const width = 300
  const below = window.innerHeight - rect.bottom > 240
  deptPreview.value = {
    paper,
    left: Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8)),
    top: below ? rect.bottom + 8 : null,
    bottom: below ? null : window.innerHeight - rect.top + 8,
  }
}
function hideDeptPreview() {
  deptPreview.value = null
}
function openDeptModal(paper) {
  deptPreview.value = null
  deptModalPaper.value = paper
}
function onDeptKey(event) {
  if (event.key === 'Escape') deptModalPaper.value = null
}
onMounted(() => document.addEventListener('keydown', onDeptKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onDeptKey))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPapers(page)
}

const deletingId = ref(null)

function goToSetup() {
  router.push({ name: 'question-papers-setup' })
}

function goToConfigure(paper) {
  router.push({ name: 'question-papers-configure', params: { id: paper.id } })
}

function goToView(paper) {
  router.push({ name: 'question-papers-view', params: { id: paper.id } })
}

// "Replace PDF" — deliberately available even when paper.evaluation_started
// has locked "Edit Setup"/"Delete" below (see
// QuestionPaperController::updatePdf()'s own docblock): swapping the file
// alone can't orphan a teacher's in-progress evaluation the way a
// structure edit could, and a bad scan is exactly the kind of thing a
// teacher already mid-evaluation most needs corrected.
const replacingPdfPaper = ref(null)
function openReplacePdf(paper) {
  replacingPdfPaper.value = paper
}
function onPdfReplaced() {
  replacingPdfPaper.value = null
  toast.success('Question paper PDF replaced successfully.')
  fetchPapers(pagination.current_page)
}

async function removePaper(paper) {
  const confirmed = await confirmDialog({
    title: 'Delete Question Paper',
    message: `Delete this ${paper.course_name ? courseLabel(paper.course_name, paper.course_code, paper.course_type) : 'question paper'} (${paper.exam_year}, Sem ${paper.semester})? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = paper.id
  try {
    await api.delete(`/question-papers/${paper.id}`)
    if (papers.value.length === 1 && pagination.current_page > 1) {
      await fetchPapers(pagination.current_page - 1)
    } else {
      await fetchPapers(pagination.current_page)
    }
    toast.success('Question paper deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete this question paper.'
  } finally {
    deletingId.value = null
  }
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('question-paper-list')) fetchPapers(1)
})

// Switching the header's Exam Year picker while already on this page
// re-runs the list against the new year, same as changing search/status.
watch(
  () => examYearStore.selectedYear,
  () => {
    if (permissionChecked.value && authStore.can('question-paper-list')) fetchPapers(1)
  },
)
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('question-paper-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb + page action -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700">Question Paper List</span>
      </div>
      <div v-if="authStore.can('question-paper-setup')" class="flex items-center gap-2">
        <!-- No "Add Question Paper" here on purpose — a question paper isn't
             a simple record you fill in from scratch, it's a PDF you upload
             and then transcribe the structure of. "Setup" makes that two-step
             nature explicit instead of implying a single quick-add form. -->
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToSetup"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          Setup Question Paper
        </button>
      </div>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar, matching designed_files/subject-list.html's
         colored info bar directly above its table — search/filter live here
         on the right, same as the reference's "Available : 37" pill. -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="8" y1="13" x2="16" y2="13" />
            <line x1="8" y1="17" x2="16" y2="17" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Question Papers</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search…"
          class="w-full sm:w-56 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          @keyup.enter="runSearch"
        />
        <button
          type="button"
          class="h-10 w-10 shrink-0 rounded-lg bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors"
          aria-label="Search"
          @click="runSearch"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        </button>
        <div class="relative">
          <button
            type="button"
            class="h-10 shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-white text-[13px] font-medium px-3.5 transition-colors"
            :aria-expanded="showFilter"
            @click="toggleFilter"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" /></svg>
            Filter
          </button>
          <div
            v-show="showFilter"
            class="absolute right-0 top-full mt-2 z-40 w-[min(300px,calc(100vw-2rem))] bg-white rounded-2xl shadow-panel border border-soft p-4 text-left"
            role="dialog"
            aria-label="Filter question papers"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Department</label>
                <div class="relative">
                  <select v-model="departmentFilter" class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer">
                    <option value="">All Departments</option>
                    <option v-for="d in availableDepartments" :key="d.id" :value="d.id">{{ d.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Course</label>
                <div class="relative">
                  <select v-model="courseFilter" class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer">
                    <option value="">All Courses</option>
                    <option v-for="c in availableCourses" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Exam Term</label>
                <div class="relative">
                  <select v-model="examTermFilter" class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer">
                    <option value="">All Exam Terms</option>
                    <option v-for="t in availableExamTerms" :key="t.id" :value="t.id">{{ t.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Semester</label>
                <div class="relative">
                  <select v-model="semesterFilter" class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer">
                    <option value="">All Semesters</option>
                    <option v-for="n in SEMESTER_OPTIONS" :key="n" :value="n">{{ ordinal(n) }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Status</label>
                <div class="relative">
                  <select v-model="statusFilter" class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer">
                    <option value="">All Status</option>
                    <option value="ready">Ready</option>
                    <option value="draft">Draft</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex items-center justify-end gap-2 pt-1">
                <button type="button" class="min-w-[88px] h-9 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="resetFilters">Reset</button>
                <button type="button" class="min-w-[88px] h-9 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-all" @click="applyFilters">Search</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Exam Year</th>
              <th class="px-4 py-1.5 font-medium">Departments</th>
              <th class="px-4 py-1.5 font-medium">Course</th>
              <th class="px-4 py-1.5 font-medium">Exam Term</th>
              <th class="px-4 py-1.5 font-medium" title="Semester">Sem</th>
              <th class="px-4 py-1.5 font-medium">Full Marks</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Uploaded By / At</th>
              <th class="px-4 py-1.5 font-medium">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('question-paper-edit') || authStore.can('question-paper-delete') || authStore.can('question-paper-view')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!papers.length">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">No question papers found.</td>
            </tr>
            <tr
              v-for="(paper, index) in papers"
              v-else
              :key="paper.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1 font-semibold">{{ paper.exam_year }}</td>
              <!-- Just a View button — hover previews the departments, click opens them in a modal. -->
              <td class="px-4 py-1">
                <button
                  v-if="paper.departments?.length"
                  type="button"
                  class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg bg-soft text-brand-blue text-[12px] font-medium hover:bg-brand-blue/10 transition-colors whitespace-nowrap"
                  :aria-label="`View ${paper.departments.length} department${paper.departments.length === 1 ? '' : 's'}`"
                  @mouseenter="showDeptPreview(paper, $event)"
                  @mouseleave="hideDeptPreview"
                  @focus="showDeptPreview(paper, $event)"
                  @blur="hideDeptPreview"
                  @click.stop="openDeptModal(paper)"
                >
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                  View
                  <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-brand-blue text-white text-[10px] font-semibold">{{ paper.departments.length }}</span>
                </button>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="px-4 py-1">
                {{ paper.course_name || '—' }}
                <span v-if="paper.course_code" class="text-muted">({{ paper.course_code }})</span><span v-if="paper.course_type" class="text-muted">{{ typeSuffix(paper.course_type) }}</span>
              </td>
              <td class="px-4 py-1">{{ paper.exam_term_name || '—' }}</td>
              <td class="px-4 py-1">{{ ordinal(paper.semester) }}</td>
              <td class="px-4 py-1">{{ paper.full_marks ?? '—' }}</td>
              <!-- Who uploaded it, with when below. -->
              <td class="px-4 py-1.5 whitespace-nowrap">
                <p class="leading-snug">{{ paper.created_by_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ formatDateTime(paper.created_at) }}</p>
              </td>
              <td class="px-4 py-1">
                <div class="flex items-center gap-1.5">
                  <span
                    class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold"
                    :class="paper.status === 'ready' ? 'bg-success/10 text-success' : 'bg-badge/15 text-badge'"
                  >
                    {{ paper.status === 'ready' ? 'Ready' : 'Draft' }}
                  </span>
                  <span
                    v-if="paper.evaluation_started"
                    class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-gray-600 px-2 py-1 text-[10px] font-semibold"
                    title="A teacher has already started evaluating a sheet mapped to this paper — the structure is locked."
                  >
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                    Locked
                  </span>
                </div>
              </td>
              <td v-if="authStore.can('question-paper-edit') || authStore.can('question-paper-delete') || authStore.can('question-paper-view')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu width="w-44">
                  <button
                    v-if="paper.status === 'ready' || authStore.can('question-paper-view')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="goToView(paper)"
                  >
                    View
                  </button>
                  <button
                    v-if="authStore.can('question-paper-edit')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-gray-700"
                    :disabled="paper.evaluation_started"
                    :title="paper.evaluation_started ? 'Locked — a teacher has already started evaluating a sheet mapped to this paper.' : ''"
                    @click="goToConfigure(paper)"
                  >
                    {{ paper.status === 'ready' ? 'Edit Setup' : 'Continue Setup' }}
                  </button>
                  <button
                    v-if="authStore.can('question-paper-edit') && paper.status === 'ready'"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    title="Swap in a new scan without changing marks or the question structure."
                    @click="openReplacePdf(paper)"
                  >
                    Replace PDF
                  </button>
                  <button
                    v-if="authStore.can('question-paper-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-gray-700"
                    :disabled="deletingId === paper.id || paper.evaluation_started"
                    :title="paper.evaluation_started ? 'Locked — a teacher has already started evaluating a sheet mapped to this paper.' : ''"
                    @click="removePaper(paper)"
                  >
                    {{ deletingId === paper.id ? 'Deleting…' : 'Delete' }}
                  </button>
                </RowActionMenu>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Pagination -->
    <Pagination
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      :total="pagination.total"
      @change="goToPage"
    />

    <ReplaceQuestionPaperPdfModal
      v-if="replacingPdfPaper"
      :paper="replacingPdfPaper"
      @close="replacingPdfPaper = null"
      @updated="onPdfReplaced"
    />

    <Teleport to="body">
      <!-- Departments hover preview -->
      <div
        v-if="deptPreview"
        class="fixed z-[150] w-[300px] rounded-xl bg-white shadow-panel border border-soft p-3 pointer-events-none"
        :style="{ left: deptPreview.left + 'px', top: deptPreview.top != null ? deptPreview.top + 'px' : 'auto', bottom: deptPreview.bottom != null ? deptPreview.bottom + 'px' : 'auto' }"
        role="tooltip"
      >
        <p class="text-[11px] font-semibold text-muted uppercase tracking-wide mb-1.5">
          {{ deptPreview.paper.departments.length }} department{{ deptPreview.paper.departments.length === 1 ? '' : 's' }}
        </p>
        <ul class="space-y-1">
          <li v-for="d in deptPreview.paper.departments.slice(0, DEPT_PREVIEW_LIMIT)" :key="d.id" class="text-[12px] text-gray-800 leading-snug">{{ departmentLabel(d) }}</li>
        </ul>
        <p class="mt-2 pt-2 border-t border-soft text-[11px] text-brand-blue">
          <template v-if="deptPreview.paper.departments.length > DEPT_PREVIEW_LIMIT">+{{ deptPreview.paper.departments.length - DEPT_PREVIEW_LIMIT }} more · </template>Click to view all
        </p>
      </div>

      <!-- Departments modal -->
      <div v-if="deptModalPaper" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="deptModalPaper = null">
        <div class="w-full max-w-[520px] max-h-[80vh] flex flex-col rounded-[24px] bg-white shadow-panel overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="paper-departments-title">
          <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-soft">
            <div class="min-w-0">
              <h2 id="paper-departments-title" class="text-[18px] font-semibold text-gray-900">Departments</h2>
              <p class="text-[12px] text-muted mt-0.5 truncate">
                {{ deptModalPaper.course_name ? courseLabel(deptModalPaper.course_name, deptModalPaper.course_code, deptModalPaper.course_type) : 'Question paper' }}
                · {{ deptModalPaper.exam_year }} · Sem {{ deptModalPaper.semester }}
              </p>
            </div>
            <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="deptModalPaper = null">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>
          <div class="flex-1 overflow-y-auto px-5 py-3">
            <table class="w-full text-left border-separate border-spacing-0">
              <thead class="sticky top-0">
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-3 py-2 rounded-tl-xl w-10">#</th>
                  <th class="px-3 py-2">Department</th>
                  <th class="px-3 py-2 rounded-tr-xl">Code</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(d, i) in deptModalPaper.departments" :key="d.id" class="text-[12.5px] text-gray-800 even:bg-gray-50">
                  <td class="px-3 py-2 text-muted border-b border-gray-100">{{ i + 1 }}</td>
                  <td class="px-3 py-2 font-medium border-b border-gray-100">{{ d.name }}</td>
                  <td class="px-3 py-2 border-b border-gray-100">{{ d.code || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
