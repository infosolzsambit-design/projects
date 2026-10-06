<script setup>
// Sidebar's "My Problem Course" — the third leg alongside MyPendingCoursesView.vue
// and MyCompletedCoursesView.vue (same course-chips + active-course-header +
// paper-list shape as both). Shows this teacher's own answer sheets that
// have had an issue raised (see MyPendingCourseController::raiseIssue()).
// Open ones ("Issue Pending") are excluded from Pending Course and can't be
// evaluated; once an admin resolves one (NotificationController's
// resolve*Issue() methods) it goes back to Pending and stays listed here as
// "Resolved". Read-only, like Completed Course — no action column.
import { computed, onMounted, ref, watch } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useExamTypeStore } from '../stores/examType'
import { formatDateTime } from '../utils/date'
import { typeSuffix } from '../utils/course'

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

const paperSearch = ref('')
const filteredPapers = computed(() => {
  const q = paperSearch.value.trim().toLowerCase()
  if (!q) return papers.value
  return papers.value.filter((paper) => {
    const haystack = `${paper.barcode || ''} ${paper.subject_barcode || ''} ${paper.roll_no || ''}`.toLowerCase()
    return haystack.includes(q)
  })
})

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  activeCourse.value = null
  papers.value = []
  try {
    const params = {}
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/my-problem-courses', { params })
    courses.value = res.data.data
    if (courses.value.length) selectCourse(courses.value[0])
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load your problem courses.'
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
    const res = await api.get('/my-problem-courses/papers', { params })
    papers.value = res.data.data
  } catch (err) {
    papersError.value = err.response?.data?.message || 'Could not load papers for this course.'
  } finally {
    papersLoading.value = false
  }
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const userReady = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  userReady.value = true
  if (authStore.can('my-problem-course-list')) loadCourses()
})

watch(
  () => [examYearStore.selectedYear, examTypeStore.selectedId],
  () => {
    if (userReady.value && authStore.can('my-problem-course-list')) loadCourses()
  },
)
</script>

<template>
  <p v-if="!userReady" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('my-problem-course-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
        <span class="text-[13px] sm:text-sm text-gray-700">My Problem Course</span>
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
        <h2 class="text-[15px] sm:text-base font-semibold text-gray-800">Course Problem List</h2>
      </div>

      <p v-if="coursesLoading" class="text-center text-sm text-muted py-6">Loading&hellip;</p>
      <p v-else-if="coursesError" class="text-center text-sm text-brand py-6">{{ coursesError }}</p>
      <p v-else-if="!courses.length" class="text-center text-sm text-muted py-6">No open issues on your answer sheets right now.</p>
      <div v-else class="flex flex-wrap gap-3 pb-1">
        <button
          v-for="course in courses"
          :key="course.course_id"
          type="button"
          class="subject-chip shrink-0 flex items-center gap-[2px] rounded-xl border bg-white shadow-chip px-2 py-3 text-[12px] sm:text-[13px] font-medium text-left transition-colors"
          :class="activeCourse === course ? 'border-brand-blue text-brand-blue' : 'border-chip-border text-gray-800'"
          @click="selectCourse(course)"
        >
          <svg class="w-5 h-5 text-brand shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          <span class="min-w-0 leading-tight break-words">{{ course.course_code }} - {{ course.course_name?.toUpperCase() }}{{ typeSuffix(course.course_type).toUpperCase() }}</span>
        </button>
      </div>
    </section>

    <template v-if="activeCourse">
      <!-- Active course header -->
      <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          </span>
          <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">{{ activeCourse.course_code }} - {{ activeCourse.course_name }}{{ typeSuffix(activeCourse.course_type) }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2" @click.stop>
          <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2">Problem : {{ activeCourse.problem_count }}</span>
          <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2">Resolved : {{ activeCourse.resolved_count ?? 0 }}</span>
          <input
            v-model="paperSearch"
            type="text"
            placeholder="Search unique number…"
            class="w-full sm:w-48 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          />
        </div>
      </section>

      <!-- Paper list -->
      <section class="overflow-hidden rounded-2xl shadow-panel">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[900px] text-left">
            <thead>
              <tr class="bg-subject-header text-white text-[12px] font-medium">
                <th class="px-4 py-1.5 font-medium rounded-tl-2xl w-px whitespace-nowrap">#</th>
                <th class="px-4 py-1.5 font-medium">Script/Unique Number</th>
                <th class="px-4 py-1.5 font-medium">Issue Type</th>
                <th class="px-4 py-1.5 font-medium">Raised At</th>
                <th class="px-4 py-1.5 font-medium">Remarks</th>
                <th class="px-4 py-1.5 font-medium">Resolved At</th>
                <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl">Status</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="papersLoading">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="papersError">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-brand">{{ papersError }}</td>
              </tr>
              <tr v-else-if="!papers.length">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No problem papers for this course.</td>
              </tr>
              <tr v-else-if="!filteredPapers.length">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No papers match your search.</td>
              </tr>
              <tr v-for="(paper, index) in filteredPapers" :key="paper.id" class="text-[12px] text-gray-800">
                <td class="px-4 py-1.5">
                  <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium"># {{ index + 1 }}</span>
                </td>
                <td class="px-4 py-1.5 font-semibold">{{ paper.barcode || paper.subject_barcode || paper.roll_no || '—' }}</td>
                <td class="px-4 py-1.5">
                  <span class="inline-flex items-center gap-1 rounded-full bg-brand/10 text-brand text-[11px] font-medium px-2 py-0.5 whitespace-nowrap">
                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
                    {{ paper.issue_master_name || 'Issue' }}
                  </span>
                </td>
                <td class="px-4 py-1.5 whitespace-nowrap">{{ formatDateTime(paper.issue_raised_at) }}</td>
                <td class="px-4 py-1.5 max-w-[240px] truncate" :title="paper.issue_remarks || undefined">{{ paper.issue_remarks || '—' }}</td>
                <td class="px-4 py-1.5 whitespace-nowrap">{{ formatDateTime(paper.issue_fixed_at) }}</td>
                <td class="px-4 py-1.5 text-center">
                  <span
                    v-if="paper.issue_status === 'resolved'"
                    class="inline-flex items-center gap-1.5 rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium text-success whitespace-nowrap"
                    :title="paper.issue_admin_remarks || undefined"
                  >
                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
                    Resolved
                  </span>
                  <span
                    v-else
                    class="inline-flex items-center gap-1.5 rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium text-brand whitespace-nowrap"
                  >
                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
                    Issue Pending
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
