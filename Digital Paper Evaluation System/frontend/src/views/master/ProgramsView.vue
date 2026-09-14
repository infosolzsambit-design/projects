<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { useConfirm } from '../../composables/useConfirm'
import { useToast } from '../../composables/useToast'
import Pagination from '../../components/common/Pagination.vue'
import RowActionMenu from '../../components/common/RowActionMenu.vue'

const router = useRouter()
const { confirmDialog } = useConfirm()
const toast = useToast()
const authStore = useAuthStore()

const programs = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchPrograms(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.is_active = statusFilter.value

    const res = await api.get('/programs', { params })
    programs.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load programs.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchPrograms(1)
}

// Shows up to 2 course names then "+N more", so the column doesn't
// balloon for programs mapped to many courses.
function courseSummary(program) {
  const names = (program.courses || []).map((c) => c.name)
  if (names.length === 0) return '—'
  if (names.length <= 2) return names.join(', ')
  return `${names.slice(0, 2).join(', ')} +${names.length - 2} more`
}

const statusOptions = [
  { value: '', label: 'All Status' },
  { value: 'yes', label: 'Active' },
  { value: 'no', label: 'Inactive' },
]
const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
function selectStatus(value) {
  statusFilter.value = value
  showFilter.value = false
  fetchPrograms(1)
}
onMounted(() => document.addEventListener('click', closeFilter))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPrograms(page)
}

const deletingId = ref(null)
const togglingId = ref(null)

async function toggleStatus(program) {
  togglingId.value = program.id
  const next = !program.status
  try {
    await api.put(`/programs/${program.id}`, { status: next })
    program.status = next
    toast.success(`Program ${next ? 'activated' : 'deactivated'} successfully.`)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not update program status.'
  } finally {
    togglingId.value = null
  }
}

function goToCreate() {
  router.push({ name: 'master-programs-create' })
}

function goToEdit(program) {
  router.push({ name: 'master-programs-edit', params: { id: program.id } })
}

async function removeProgram(program) {
  const confirmed = await confirmDialog({
    title: 'Delete Program',
    message: `Delete program "${program.name}"? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = program.id
  try {
    await api.delete(`/programs/${program.id}`)
    if (programs.value.length === 1 && pagination.current_page > 1) {
      await fetchPrograms(pagination.current_page - 1)
    } else {
      await fetchPrograms(pagination.current_page)
    }
    toast.success('Program deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete program.'
  } finally {
    deletingId.value = null
  }
}

// Guards against a false "no permission" flash on a hard refresh — see
// CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('program-list')) fetchPrograms(1)
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('program-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
        <span class="text-[13px] sm:text-sm text-gray-700">Program List</span>
      </div>
      <div v-if="authStore.can('program-add')" class="flex items-center gap-2">
        <RouterLink
          :to="{ name: 'master-programs-bulk-upload' }"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 sm:px-5 hover:border-brand-blue hover:text-brand-blue transition-colors"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          Bulk Upload
        </RouterLink>
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToCreate"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
          Add Program
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
            <ellipse cx="12" cy="5" rx="9" ry="3" />
            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" />
            <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Programs</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search programs…"
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
          class="absolute right-4 sm:right-5 top-full mt-2 w-40 bg-white rounded-xl shadow-panel border border-soft py-1.5 z-30 text-left"
        >
          <button
            v-for="option in statusOptions"
            :key="option.value"
            type="button"
            class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] transition-colors"
            :class="statusFilter === option.value ? 'text-brand-blue font-semibold bg-soft' : 'text-gray-700 hover:bg-soft hover:text-brand-blue'"
            @click="selectStatus(option.value)"
          >
            {{ option.label }}
          </button>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[13px] font-medium">
              <th class="px-4 py-3.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-3.5 font-medium">Name</th>
              <th class="px-4 py-3.5 font-medium">Department</th>
              <th class="px-4 py-3.5 font-medium">Code</th>
              <th class="px-4 py-3.5 font-medium">Courses</th>
              <th class="px-4 py-3.5 font-medium" v-if="authStore.can('program-status-change')">Status</th>
              <th class="px-4 py-3.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('program-edit') || authStore.can('program-delete')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!programs.length">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No programs found.</td>
            </tr>
            <tr
              v-for="(program, index) in programs"
              v-else
              :key="program.id"
              class="text-[13px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-3.5">
                <span class="inline-flex items-center justify-center min-w-[46px] rounded-md status-gradient-border px-2 py-1.5 text-[12px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-3.5 font-semibold">{{ program.name }}</td>
              <td class="px-4 py-3.5">{{ program.department }}</td>
              <td class="px-4 py-3.5">{{ program.code || '—' }}</td>
              <td class="px-4 py-3.5 text-muted">{{ courseSummary(program) }}</td>
              <td v-if="authStore.can('program-status-change')" class="px-4 py-3.5" @click.stop>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="program.status"
                  :disabled="togglingId === program.id"
                  :title="program.status ? 'Click to deactivate' : 'Click to activate'"
                  class="relative inline-flex items-center w-24 h-8 rounded-full text-[12px] font-semibold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="program.status ? 'bg-btn-gradient text-white justify-start pl-3 pr-7' : 'bg-gray-300 text-gray-600 justify-end pl-7 pr-3'"
                  @click="toggleStatus(program)"
                >
                  <span>{{ program.status ? 'Active' : 'Inactive' }}</span>
                  <span
                    class="absolute top-[7px] left-[7px] w-[18px] h-[18px] rounded-full bg-white shadow transition-transform duration-200"
                    :class="program.status ? 'translate-x-16' : 'translate-x-0'"
                  ></span>
                </button>
              </td>
              <td v-if="authStore.can('program-edit') || authStore.can('program-delete')" class="px-4 py-3.5 text-center relative" @click.stop>
                <RowActionMenu>
                  <button v-if="authStore.can('program-edit')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="goToEdit(program)">
                    Edit
                  </button>
                  <button
                    v-if="authStore.can('program-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                    :disabled="deletingId === program.id"
                    @click="removeProgram(program)"
                  >
                    {{ deletingId === program.id ? 'Deleting…' : 'Delete' }}
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
  </div>
</template>
