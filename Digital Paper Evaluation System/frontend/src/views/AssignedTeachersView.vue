<script setup>
// Companion to AssignTeacherView.vue — every teacher who currently has at
// least one answer sheet allocated to them (see TeacherController::index()'s
// ?has_assignments=yes filter). Sits under the same "Assign Teacher"
// sidebar submenu as that page — see AppSidebar.vue.
//
// Three separate, deliberately distinct entry points into a teacher's
// allocations:
//  - Clicking the "Allocated Answer Sheets" count opens
//    TeacherAllocationModal.vue — plain read-only breakdown by packet, no
//    actions.
//  - Clicking the "Courses" count opens TeacherCoursesModal.vue — the
//    same idea, one level coarser: just the distinct courses, no packet
//    detail.
//  - The row's own "⋮" action menu has a "Reassign" item, which opens
//    TeacherReassignModal.vue — the "this teacher is on leave" flow
//    (pick a course, then split its sheets across other teachers).
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useExamTypeStore } from '../stores/examType'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import TeacherAllocationModal from '../components/teachers/TeacherAllocationModal.vue'
import TeacherCoursesModal from '../components/teachers/TeacherCoursesModal.vue'
import TeacherReassignModal from '../components/teachers/TeacherReassignModal.vue'

const authStore = useAuthStore()
const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const teachers = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const departmentFilter = ref('') // '' | department id
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchTeachers(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page, has_assignments: 'yes', department_scope: 'self' }
    if (search.value) params.search = search.value
    if (departmentFilter.value) params.department_id = departmentFilter.value
    // Server-enforced too (see TeacherController::index()'s own
    // has_assignments=yes branch) — sending it here is what actually
    // picks *which* year, not what makes the scoping happen at all.
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }

    const res = await api.get('/teachers', { params })
    teachers.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load assigned teachers.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchTeachers(1)
}

// Same "active departments, name+code" source TeachersView.vue's own
// department filter already uses.
const availableDepartments = ref([])
async function loadDepartments() {
  try {
    // The list itself is already always restricted to a non-super-admin's
    // own department regardless of this dropdown (department_scope=self
    // above, enforced server-side — see TeacherController::index()'s own
    // docblock), so there's no point offering every other department here.
    if (!authStore.user?.is_super_admin) {
      availableDepartments.value = authStore.user?.department_id
        ? [{ id: authStore.user.department_id, name: authStore.user.department || 'My Department' }]
        : []
      return
    }

    const res = await api.get('/departments', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    availableDepartments.value = res.data.data.map((department) => ({
      ...department,
      name: department.code ? `${department.name} (${department.code})` : department.name,
    }))
  } catch {
    // Non-fatal — the department filter just stays empty; search still works.
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
  fetchTeachers(1)
}
function resetFilters() {
  departmentFilter.value = ''
  showFilter.value = false
  fetchTeachers(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchTeachers(page)
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('assigned-teacher-list')) {
    fetchTeachers(1)
    loadDepartments()
  }
})

watch(
  () => [examYearStore.selectedYear, examTypeStore.selectedId],
  () => {
    if (permissionChecked.value && authStore.can('assigned-teacher-list')) fetchTeachers(1)
  },
)

const allocationModalTeacher = ref(null)
function openAllocationModal(teacher) {
  allocationModalTeacher.value = teacher
}
function closeAllocationModal() {
  allocationModalTeacher.value = null
}

const coursesModalTeacher = ref(null)
function openCoursesModal(teacher) {
  coursesModalTeacher.value = teacher
}
function closeCoursesModal() {
  coursesModalTeacher.value = null
}

const reassignModalTeacher = ref(null)
function openReassignModal(teacher) {
  reassignModalTeacher.value = teacher
}
function closeReassignModal() {
  reassignModalTeacher.value = null
}
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('assigned-teacher-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
      <span class="text-[13px] sm:text-sm text-gray-700">Assigned Teacher List</span>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="9" y="2" width="6" height="4" rx="1" />
            <path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4" />
            <path d="M9 14l2 2 4-4" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Teachers With Papers Assigned</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search teachers…"
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
            class="absolute right-0 top-full mt-2 z-40 w-[min(260px,calc(100vw-2rem))] bg-white rounded-2xl shadow-panel border border-soft p-4 text-left"
            role="dialog"
            aria-label="Filter assigned teachers"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Department</label>
                <div class="relative">
                  <select
                    v-model="departmentFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Departments</option>
                    <option v-for="department in availableDepartments" :key="department.id" :value="department.id">{{ department.name }}</option>
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
        <table class="w-full min-w-[960px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Name</th>
              <th class="px-4 py-1.5 font-medium">Emp Code</th>
              <th class="px-4 py-1.5 font-medium">Department</th>
              <th class="px-4 py-1.5 font-medium">Designation</th>
              <th class="px-4 py-1.5 font-medium text-center" v-if="authStore.can('view-allocated-answersheet')">Courses</th>
              <th class="px-4 py-1.5 font-medium text-center" v-if="authStore.can('view-allocated-answersheet')">Allocated Answer Sheets</th>
              <th class="px-4 py-1.5 font-medium text-center" v-if="authStore.can('view-allocated-answersheet')">Completed Sheets</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('reassign-teacher')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="9" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!teachers.length">
              <td colspan="9" class="px-4 py-10 text-center text-sm text-muted">No teacher has any answer sheets assigned yet.</td>
            </tr>
            <tr
              v-for="(teacher, index) in teachers"
              v-else
              :key="teacher.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1 font-semibold">{{ teacher.name }}</td>
              <td class="px-4 py-1">{{ teacher.emp_code || '—' }}</td>
              <td class="px-4 py-1">{{ teacher.department || '—' }}</td>
              <td class="px-4 py-1">{{ teacher.designation || '—' }}</td>
              <td v-if="authStore.can('view-allocated-answersheet')" class="px-4 py-1 text-center">
                <button
                  type="button"
                  class="font-semibold text-brand-blue hover:underline"
                  @click="openCoursesModal(teacher)"
                >
                  {{ teacher.allocated_course_count }}
                </button>
              </td>
              <td v-if="authStore.can('view-allocated-answersheet')" class="px-4 py-1 text-center">
                <button
                  type="button"
                  class="font-semibold text-brand-blue hover:underline"
                  @click="openAllocationModal(teacher)"
                >
                  {{ teacher.allocated_answer_sheet_count }}
                </button>
              </td>
              <td v-if="authStore.can('view-allocated-answersheet')" class="px-4 py-1 text-center font-semibold text-success">
                {{ teacher.completed_answer_sheet_count }}
              </td>
              <td v-if="authStore.can('reassign-teacher')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu width="w-40">
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="openReassignModal(teacher)"
                  >
                    Reassign
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

    <TeacherAllocationModal v-if="allocationModalTeacher" :teacher="allocationModalTeacher" @close="closeAllocationModal" />

    <TeacherCoursesModal v-if="coursesModalTeacher" :teacher="coursesModalTeacher" @close="closeCoursesModal" />

    <TeacherReassignModal
      v-if="reassignModalTeacher"
      :teacher="reassignModalTeacher"
      @close="closeReassignModal"
      @reassigned="fetchTeachers(pagination.current_page)"
    />
  </div>
</template>
