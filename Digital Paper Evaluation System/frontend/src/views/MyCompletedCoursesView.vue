<script setup>
// Sidebar's "My Completed Course" — the mirror image of
// MyPendingCoursesView.vue (see its own docblock for the shared shape:
// course chips grouped by the packet's actual course, first course
// auto-selected, same exam-year scoping via the header's picker), just
// showing what this teacher has already evaluated (marks IS NOT NULL —
// see MyCompletedCourseController) instead of what's still waiting.
// Read-only — there's no "resume/re-open" action here, on purpose.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useExamTypeStore } from '../stores/examType'
import { formatDateTime } from '../utils/date'
import DatePicker from '../components/common/DatePicker.vue'

const authStore = useAuthStore()
const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const courses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')
const activeCourse = ref(null)

const papers = ref([])
const papersLoading = ref(false)
const papersError = ref('')

// Search + Filter for the paper list below — same designed_files/
// subject-list.html header pattern as every other list page in this app,
// applied client-side since one course's own completed list is always
// already fully loaded (never paginated). No Status filter here — unlike
// My Pending Course, every row on this page is already "Evaluated".
const paperSearch = ref('')
const completedFromDate = ref('') // 'YYYY-MM-DD'
const completedToDate = ref('') // 'YYYY-MM-DD'
const filteredPapers = computed(() => {
  const q = paperSearch.value.trim().toLowerCase()
  return papers.value.filter((paper) => {
    if (q) {
      const haystack = `${paper.barcode || ''} ${paper.subject_barcode || ''} ${paper.roll_no || ''}`.toLowerCase()
      if (!haystack.includes(q)) return false
    }
    if ((completedFromDate.value || completedToDate.value) && paper.updated_at) {
      const completedDate = paper.updated_at.slice(0, 10)
      if (completedFromDate.value && completedDate < completedFromDate.value) return false
      if (completedToDate.value && completedDate > completedToDate.value) return false
    }
    return true
  })
})

const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
onMounted(() => document.addEventListener('click', closeFilter))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))
function resetPaperFilters() {
  completedFromDate.value = ''
  completedToDate.value = ''
  showFilter.value = false
}

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  // Cleared up front — see MyPendingCoursesView.vue's own loadCourses()
  // for exactly why (switching Exam Year to one with no data of its own
  // used to leave the previous year's active course/paper list on screen).
  activeCourse.value = null
  papers.value = []
  try {
    const params = {}
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/my-completed-courses', { params })
    courses.value = res.data.data
    if (courses.value.length) selectCourse(courses.value[0])
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load your completed courses.'
  } finally {
    coursesLoading.value = false
  }
}

function selectCourse(course) {
  activeCourse.value = course
  loadPapers(course)
}

async function loadPapers(course) {
  papersLoading.value = true
  papersError.value = ''
  papers.value = []
  try {
    const params = { course_id: course.course_id }
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/my-completed-courses/papers', { params })
    papers.value = res.data.data
  } catch (err) {
    papersError.value = err.response?.data?.message || 'Could not load papers for this course.'
  } finally {
    papersLoading.value = false
  }
}

function formatCompletedAt(value) {
  return formatDateTime(value)
}

const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('my-pending-course-list')) loadCourses()
})

watch(
  () => [examYearStore.selectedYear, examTypeStore.selectedId],
  () => {
    if (permissionChecked.value && authStore.can('my-pending-course-list')) loadCourses()
  },
)
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('my-pending-course-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb + page action -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-3.5 mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700">My Completed Course</span>
      </div>
      <RouterLink
        v-if="authStore.can('my-pending-course-list')"
        :to="{ name: 'my-pending-courses' }"
        class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-full bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
        Pending Course
      </RouterLink>
    </div>

    <!-- Course chips -->
    <section class="bg-white rounded-2xl shadow-panel p-4 sm:p-5 mb-5">
      <div class="flex items-center gap-2 mb-4">
        <svg class="w-5 h-5 text-brand-blue shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
        <h2 class="text-[15px] sm:text-base font-semibold text-gray-800">Course Completed List</h2>
      </div>

      <p v-if="coursesLoading" class="text-center text-sm text-muted py-6">Loading&hellip;</p>
      <p v-else-if="coursesError" class="text-center text-sm text-brand py-6">{{ coursesError }}</p>
      <p v-else-if="!courses.length" class="text-center text-sm text-muted py-6">You haven't completed any evaluations yet.</p>
      <div v-else class="flex flex-wrap gap-3 pb-1">
        <button
          v-for="course in courses"
          :key="course.course_id"
          type="button"
          class="subject-chip shrink-0 flex items-center gap-[2px] rounded-xl border bg-white shadow-chip px-2 py-3 text-[12px] sm:text-[13px] font-medium text-left transition-colors"
          :class="activeCourse === course ? 'border-brand-blue text-brand-blue' : 'border-chip-border text-gray-800'"
          @click="selectCourse(course)"
        >
          <svg class="w-5 h-5 text-success shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></svg>
          <span class="min-w-0 leading-tight break-words">{{ course.course_code }} - {{ course.course_name?.toUpperCase() }}</span>
        </button>
      </div>
    </section>

    <template v-if="activeCourse">
      <!-- Active course header -->
      <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></svg>
          </span>
          <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">{{ activeCourse.course_code }} - {{ activeCourse.course_name }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2" @click.stop>
          <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2">Completed : {{ activeCourse.completed_count }}</span>
          <input
            v-model="paperSearch"
            type="text"
            placeholder="Search unique number…"
            class="w-full sm:w-48 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          />
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
              aria-label="Filter completed papers"
            >
              <div class="flex flex-col gap-3">
                <div>
                  <p class="text-[13px] text-label mb-1">Completed Date</p>
                  <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1 min-w-0">
                      <label class="text-[12px] text-muted">From Date</label>
                      <DatePicker v-model="completedFromDate" dense />
                    </div>
                    <div class="flex flex-col gap-1 min-w-0">
                      <label class="text-[12px] text-muted">To Date</label>
                      <DatePicker v-model="completedToDate" dense :min="completedFromDate" />
                    </div>
                  </div>
                </div>
                <div class="flex items-center justify-end gap-2 pt-1">
                  <button type="button" class="min-w-[88px] h-9 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="resetPaperFilters">Reset</button>
                  <button type="button" class="min-w-[88px] h-9 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-all" @click="showFilter = false">Search</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Paper list -->
      <section class="overflow-hidden rounded-2xl shadow-panel">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[680px] text-left">
            <thead>
              <tr class="bg-subject-header text-white text-[12px] font-medium">
                <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
                <th class="px-4 py-1.5 font-medium">Script/Unique Number</th>
                <th class="px-4 py-1.5 font-medium">Marks</th>
                <th class="px-4 py-1.5 font-medium">Max Mark</th>
                <th class="px-4 py-1.5 font-medium">Completed On</th>
                <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl">Status</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="papersLoading">
                <td colspan="6" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="papersError">
                <td colspan="6" class="px-4 py-8 text-center text-sm text-brand">{{ papersError }}</td>
              </tr>
              <tr v-else-if="!papers.length">
                <td colspan="6" class="px-4 py-8 text-center text-sm text-muted">No completed papers for this course.</td>
              </tr>
              <tr v-else-if="!filteredPapers.length">
                <td colspan="6" class="px-4 py-8 text-center text-sm text-muted">No papers match your search/filter.</td>
              </tr>
              <tr v-for="(paper, index) in filteredPapers" :key="paper.id" class="text-[12px] text-gray-800">
                <td class="px-4 py-1.5">
                  <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium"># {{ index + 1 }}</span>
                </td>
                <td class="px-4 py-1.5 font-semibold">{{ paper.barcode || paper.subject_barcode || paper.roll_no || '—' }}</td>
                <td class="px-4 py-1.5 font-semibold">{{ paper.marks }}</td>
                <td class="px-4 py-1.5">{{ paper.max_marks ?? '—' }}</td>
                <td class="px-4 py-1.5 whitespace-nowrap">{{ formatCompletedAt(paper.updated_at) }}</td>
                <td class="px-4 py-1.5 text-center">
                  <span class="inline-flex items-center gap-1.5 rounded-lg status-gradient-border px-3.5 py-2 text-[13px] font-medium text-success">
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
                    Evaluated
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
