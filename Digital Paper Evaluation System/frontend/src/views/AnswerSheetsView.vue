<script setup>
// List page for uploaded answer-sheet batches ("packets" — see
// QuestionAnswerSheetMappingController) — mirrors QuestionPapersView.vue's
// shell (breadcrumb, colored section header, table, pagination) now that a
// real list endpoint exists. The "View Answer Sheets" row action opens
// AnswerSheetRowsModal.vue, which paginates that one packet's own rows
// separately — a packet can hold thousands of them, so nothing here ever
// asks for "all of them" at once.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import AnswerSheetRowsModal from '../components/answerSheets/AnswerSheetRowsModal.vue'

const router = useRouter()
const authStore = useAuthStore()

const mappings = ref([])
const loading = ref(true)
const loadError = ref('')
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

const search = ref('')
const courseFilter = ref('') // '' | course id
const examTermFilter = ref('') // '' | exam term id
const examTypeFilter = ref('') // '' | exam type id
const semesterFilter = ref('') // '' | 1-12
const statusFilter = ref('') // '' | 'uploaded' | 'empty'

async function fetchMappings(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (courseFilter.value) params.course_id = courseFilter.value
    if (examTermFilter.value) params.exam_term_id = examTermFilter.value
    if (examTypeFilter.value) params.exam_type_id = examTypeFilter.value
    if (semesterFilter.value) params.semester = semesterFilter.value
    if (statusFilter.value) params.status = statusFilter.value

    const res = await api.get('/answer-sheet-mappings', { params })
    mappings.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load answer sheet packets.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchMappings(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchMappings(page)
}

// Same lightweight "every active row, unpaginated" source every other
// filter dropdown in this app already uses for its own picker.
const availableCourses = ref([])
const availableExamTerms = ref([])
const availableExamTypes = ref([])
async function loadFilterOptions() {
  try {
    const [coursesRes, examTermsRes, examTypesRes] = await Promise.all([
      api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } }),
      api.get('/exam-terms', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } }),
      api.get('/exam-types', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } }),
    ])
    availableCourses.value = coursesRes.data.data.map((course) => ({
      ...course,
      name: course.code ? `${course.name} (${course.code})` : course.name,
    }))
    availableExamTerms.value = examTermsRes.data.data
    availableExamTypes.value = examTypesRes.data.data
  } catch {
    // Non-fatal — the course/exam term/exam type filters just stay empty; search still works.
  }
}

const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
onMounted(() => document.addEventListener('click', closeFilter))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))

function applyFilters() {
  showFilter.value = false
  fetchMappings(1)
}
function resetFilters() {
  courseFilter.value = ''
  examTermFilter.value = ''
  examTypeFilter.value = ''
  semesterFilter.value = ''
  statusFilter.value = ''
  showFilter.value = false
  fetchMappings(1)
}

function goToUpload() {
  router.push({ name: 'answer-sheets-upload' })
}

const viewingMapping = ref(null)
function openRowsModal(mapping) {
  viewingMapping.value = mapping
}
function closeRowsModal() {
  viewingMapping.value = null
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('answer-sheet-list')) {
    fetchMappings(1)
    loadFilterOptions()
  }
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('answer-sheet-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
        <span class="text-[13px] sm:text-sm text-gray-700">Answer Sheet List</span>
      </div>
      <div v-if="authStore.can('answer-sheet-upload')" class="flex items-center gap-2">
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToUpload"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          Upload Answer Sheet
        </button>
      </div>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 3v12M12 3l-4 4M12 3l4 4" />
            <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Answer Sheet Batches</h2>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:ml-auto" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search packets…"
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
            class="absolute right-0 top-full mt-2 z-40 w-[min(280px,calc(100vw-2rem))] bg-white rounded-2xl shadow-panel border border-soft p-4 text-left"
            role="dialog"
            aria-label="Filter answer sheet batches"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Course</label>
                <div class="relative">
                  <select
                    v-model="courseFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Courses</option>
                    <option v-for="course in availableCourses" :key="course.id" :value="course.id">{{ course.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Exam Term</label>
                <div class="relative">
                  <select
                    v-model="examTermFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Exam Terms</option>
                    <option v-for="examTerm in availableExamTerms" :key="examTerm.id" :value="examTerm.id">{{ examTerm.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Exam Type</label>
                <div class="relative">
                  <select
                    v-model="examTypeFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Exam Types</option>
                    <option v-for="examType in availableExamTypes" :key="examType.id" :value="examType.id">{{ examType.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Semester</label>
                <input
                  v-model="semesterFilter"
                  type="number"
                  min="1"
                  max="12"
                  placeholder="Any semester"
                  class="h-8 px-3 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Status</label>
                <div class="relative">
                  <select
                    v-model="statusFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Status</option>
                    <option value="uploaded">Uploaded</option>
                    <option value="empty">Empty</option>
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
        <table class="w-full min-w-[1280px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Program</th>
              <th class="px-4 py-1.5 font-medium">Question Paper</th>
              <th class="px-4 py-1.5 font-medium">Exam Term</th>
              <th class="px-4 py-1.5 font-medium">Exam Type</th>
              <th class="px-4 py-1.5 font-medium">Semester</th>
              <th class="px-4 py-1.5 font-medium">Packet Code</th>
              <th class="px-4 py-1.5 font-medium">Answer Sheets</th>
              <th class="px-4 py-1.5 font-medium">Uploaded By</th>
              <th class="px-4 py-1.5 font-medium">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('answer-sheet-view')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="11" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!mappings.length">
              <td colspan="11" class="px-4 py-10 text-center text-sm text-muted">
                No answer sheets uploaded yet. Click "Upload Answer Sheet" to get started.
              </td>
            </tr>
            <tr
              v-for="(mapping, index) in mappings"
              v-else
              :key="mapping.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1">{{ mapping.program_name || '—' }}</td>
              <td class="px-4 py-1">
                {{ mapping.course_name || '—' }}
                <span v-if="mapping.exam_year" class="text-muted">— {{ mapping.exam_year }}</span>
              </td>
              <td class="px-4 py-1">{{ mapping.exam_term_name || '—' }}</td>
              <td class="px-4 py-1">{{ mapping.exam_type_name || '—' }}</td>
              <td class="px-4 py-1">{{ mapping.semester }}</td>
              <td class="px-4 py-1 font-semibold">{{ mapping.packet_code }}</td>
              <td class="px-4 py-1">{{ mapping.answer_sheet_count }}</td>
              <td class="px-4 py-1">{{ mapping.created_by_name || '—' }}</td>
              <td class="px-4 py-1">
                <span
                  class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold"
                  :class="mapping.answer_sheet_count > 0 ? 'bg-success/10 text-success' : 'bg-badge/15 text-badge'"
                >
                  {{ mapping.answer_sheet_count > 0 ? 'Uploaded' : 'Empty' }}
                </span>
              </td>
              <td v-if="authStore.can('answer-sheet-view')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu width="w-48">
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="openRowsModal(mapping)"
                  >
                    View Answer Sheets
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

    <AnswerSheetRowsModal v-if="viewingMapping" :mapping="viewingMapping" @close="closeRowsModal" />
  </div>
</template>
