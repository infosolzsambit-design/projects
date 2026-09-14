<script setup>
// Companion to AssignTeacherView.vue — every teacher who currently has at
// least one answer sheet allocated to them (see TeacherController::index()'s
// ?has_assignments=yes filter). Sits under the same "Assign Teacher"
// sidebar submenu as that page — see AppSidebar.vue.
//
// Two separate, deliberately distinct entry points into a teacher's
// allocations:
//  - Clicking the "Allocated Answer Sheets" count opens
//    TeacherAllocationModal.vue — plain read-only breakdown, no actions.
//  - The row's own "⋮" action menu has a "Reassign" item, which opens
//    TeacherReassignModal.vue — the "this teacher is on leave" flow
//    (pick a course, then split its sheets across other teachers).
import { onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import TeacherAllocationModal from '../components/teachers/TeacherAllocationModal.vue'
import TeacherReassignModal from '../components/teachers/TeacherReassignModal.vue'

const authStore = useAuthStore()

const teachers = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchTeachers(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page, has_assignments: 'yes' }
    if (search.value) params.search = search.value

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
  if (authStore.can('assigned-teacher-list')) fetchTeachers(1)
})

const allocationModalTeacher = ref(null)
function openAllocationModal(teacher) {
  allocationModalTeacher.value = teacher
}
function closeAllocationModal() {
  allocationModalTeacher.value = null
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
      <div class="flex items-center gap-2">
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
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[13px] font-medium">
              <th class="px-4 py-3.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-3.5 font-medium">Name</th>
              <th class="px-4 py-3.5 font-medium">Emp Code</th>
              <th class="px-4 py-3.5 font-medium">Department</th>
              <th class="px-4 py-3.5 font-medium">Designation</th>
              <th class="px-4 py-3.5 font-medium text-center" v-if="authStore.can('view-allocated-answersheet')">Allocated Answer Sheets</th>
              <th class="px-4 py-3.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('reassign-teacher')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!teachers.length">
              <td colspan="7" class="px-4 py-10 text-center text-sm text-muted">No teacher has any answer sheets assigned yet.</td>
            </tr>
            <tr
              v-for="(teacher, index) in teachers"
              v-else
              :key="teacher.id"
              class="text-[13px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-3.5">
                <span class="inline-flex items-center justify-center min-w-[46px] rounded-md status-gradient-border px-2 py-1.5 text-[12px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-3.5 font-semibold">{{ teacher.name }}</td>
              <td class="px-4 py-3.5">{{ teacher.emp_code || '—' }}</td>
              <td class="px-4 py-3.5">{{ teacher.department || '—' }}</td>
              <td class="px-4 py-3.5">{{ teacher.designation || '—' }}</td>
              <td v-if="authStore.can('view-allocated-answersheet')" class="px-4 py-3.5 text-center">
                <button
                  type="button"
                  class="font-semibold text-brand-blue hover:underline"
                  @click="openAllocationModal(teacher)"
                >
                  {{ teacher.allocated_answer_sheet_count }}
                </button>
              </td>
              <td v-if="authStore.can('reassign-teacher')" class="px-4 py-3.5 text-center relative" @click.stop>
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

    <TeacherReassignModal
      v-if="reassignModalTeacher"
      :teacher="reassignModalTeacher"
      @close="closeReassignModal"
      @reassigned="fetchTeachers(pagination.current_page)"
    />
  </div>
</template>
