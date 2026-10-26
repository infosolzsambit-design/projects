<script setup>
// Clone of ExamTypesView.vue for the Exam Sub Type master — same shape,
// /exam-sub-types instead of /exam-types, plus each sub type's Exam Type
// and Exam Term (columns and filters). Page gated by 'exam-sub-type-list'.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { useConfirm } from '../../composables/useConfirm'
import { useToast } from '../../composables/useToast'
import Pagination from '../../components/common/Pagination.vue'
import RowActionMenu from '../../components/common/RowActionMenu.vue'
import MainDbSyncControl from '../../components/common/MainDbSyncControl.vue'

const router = useRouter()
const { confirmDialog } = useConfirm()
const toast = useToast()
const authStore = useAuthStore()

const examSubTypes = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'
const examTypeFilter = ref('')
const examTermFilter = ref('')

// Filter dropdowns — every exam type / exam term (inactive ones too, so
// sub types under them can still be found).
const examTypes = ref([])
const examTerms = ref([])
async function loadFilterLists() {
  const params = { status: 'all', table_fields: ['name'], sort_by_field: 'name', sort_by: 'asc' }
  const [types, terms] = await Promise.allSettled([
    api.get('/exam-types', { params, skipLoader: true }),
    api.get('/exam-terms', { params, skipLoader: true }),
  ])
  if (types.status === 'fulfilled') examTypes.value = types.value.data.data
  if (terms.status === 'fulfilled') examTerms.value = terms.value.data.data
}

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchExamSubTypes(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.is_active = statusFilter.value
    if (examTypeFilter.value !== '') params.exam_type_id = examTypeFilter.value
    if (examTermFilter.value !== '') params.exam_term_id = examTermFilter.value

    const res = await api.get('/exam-sub-types', { params })
    examSubTypes.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load exam sub types.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchExamSubTypes(1)
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
  fetchExamSubTypes(1)
}
function resetFilters() {
  statusFilter.value = ''
  examTypeFilter.value = ''
  examTermFilter.value = ''
  showFilter.value = false
  fetchExamSubTypes(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchExamSubTypes(page)
}

const deletingId = ref(null)
const togglingId = ref(null)

async function toggleStatus(examSubType) {
  togglingId.value = examSubType.id
  const next = !examSubType.status
  try {
    await api.put(`/exam-sub-types/${examSubType.id}`, { status: next })
    examSubType.status = next
    toast.success(`Exam sub type ${next ? 'activated' : 'deactivated'} successfully.`)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not update exam sub type status.'
  } finally {
    togglingId.value = null
  }
}

function goToCreate() {
  router.push({ name: 'master-exam-sub-types-create' })
}

function goToEdit(examSubType) {
  router.push({ name: 'master-exam-sub-types-edit', params: { id: examSubType.id } })
}

async function removeExamSubType(examSubType) {
  const confirmed = await confirmDialog({
    title: 'Delete Exam Sub Type',
    message: `Delete exam sub type "${examSubType.name}"? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = examSubType.id
  try {
    await api.delete(`/exam-sub-types/${examSubType.id}`)
    if (examSubTypes.value.length === 1 && pagination.current_page > 1) {
      await fetchExamSubTypes(pagination.current_page - 1)
    } else {
      await fetchExamSubTypes(pagination.current_page)
    }
    toast.success('Exam sub type deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete exam sub type.'
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
  if (!authStore.can('exam-sub-type-list')) return
  fetchExamSubTypes(1)
  loadFilterLists()
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('exam-sub-type-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb + page action -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-2.5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700">Exam Sub Type List</span>
      </div>
      <div class="flex items-center gap-2">
        <MainDbSyncControl v-if="authStore.can('exam-sub-type-sync')" entity="exam-sub-types" label="exam sub types" endpoint="/exam-sub-types/sync" button-label="Sync Exam Sub Type" @synced="fetchExamSubTypes(1)" />
        <button
          v-if="authStore.can('exam-sub-type-add')"
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToCreate"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
          Add Exam Sub Type
        </button>
      </div>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-2 mb-1.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2" />
            <line x1="16" y1="2" x2="16" y2="6" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="3" y1="10" x2="21" y2="10" />
          </svg>
        </span>
        <h2 class="text-white text-[15px] font-medium truncate">All Exam Sub Types</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3 py-1.5 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search exam sub types…"
          class="w-full sm:w-56 h-8 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          @keyup.enter="runSearch"
        />
        <button
          type="button"
          class="h-8 w-8 shrink-0 rounded-lg bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors"
          aria-label="Search"
          @click="runSearch"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        </button>
        <div class="relative">
          <button
            type="button"
            class="h-8 shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-white text-[13px] font-medium px-3.5 transition-colors"
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
            aria-label="Filter exam sub types"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Exam Type</label>
                <div class="relative">
                  <select
                    v-model="examTypeFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Exam Types</option>
                    <option v-for="type in examTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
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
                    <option v-for="term in examTerms" :key="term.id" :value="term.id">{{ term.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Status</label>
                <div class="relative">
                  <select
                    v-model="statusFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Status</option>
                    <option value="yes">Active</option>
                    <option value="no">Inactive</option>
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
        <table class="w-full min-w-[760px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl w-px whitespace-nowrap">#</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Ref Id</th>
              <th class="px-4 py-1.5 font-medium">Name</th>
              <th class="px-4 py-1.5 font-medium">Exam Type</th>
              <th class="px-4 py-1.5 font-medium">Exam Term</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Is Deleted From API</th>
              <th class="px-4 py-1.5 font-medium" v-if="authStore.can('exam-sub-type-status-change')">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl w-px whitespace-nowrap" v-if="authStore.can('exam-sub-type-edit') || authStore.can('exam-sub-type-delete')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!examSubTypes.length">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">No exam sub types found.</td>
            </tr>
            <tr
              v-for="(examSubType, index) in examSubTypes"
              v-else
              :key="examSubType.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <!-- inserted_id: the college main database's own id (Sync Exam Sub Type) -->
              <td class="px-4 py-1">{{ examSubType.inserted_id ?? '—' }}</td>
              <td class="px-4 py-1 font-semibold">{{ examSubType.name }}</td>
              <td class="px-4 py-1">{{ examSubType.exam_type || '—' }}</td>
              <td class="px-4 py-1">{{ examSubType.exam_term || '—' }}</td>
              <td class="px-4 py-1">
                <span :class="examSubType.deleted_from_main_db ? 'text-brand font-semibold' : 'text-gray-500'">{{ examSubType.deleted_from_main_db ? 'Yes' : 'No' }}</span>
              </td>
              <td v-if="authStore.can('exam-sub-type-status-change')" class="px-4 py-1" @click.stop>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="examSubType.status"
                  :disabled="togglingId === examSubType.id"
                  :title="examSubType.status ? 'Click to deactivate' : 'Click to activate'"
                  class="relative inline-flex items-center w-20 h-6 rounded-full text-[10px] font-semibold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="examSubType.status ? 'bg-btn-gradient text-white justify-start pl-2.5 pr-6' : 'bg-gray-300 text-gray-600 justify-end pl-6 pr-2.5'"
                  @click="toggleStatus(examSubType)"
                >
                  <span>{{ examSubType.status ? 'Active' : 'Inactive' }}</span>
                  <span
                    class="absolute top-[5px] left-[5px] w-[14px] h-[14px] rounded-full bg-white shadow transition-transform duration-200"
                    :class="examSubType.status ? 'translate-x-[56px]' : 'translate-x-0'"
                  ></span>
                </button>
              </td>
              <td v-if="authStore.can('exam-sub-type-edit') || authStore.can('exam-sub-type-delete')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu>
                  <button v-if="authStore.can('exam-sub-type-edit')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="goToEdit(examSubType)">
                    Edit
                  </button>
                  <button
                    v-if="authStore.can('exam-sub-type-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                    :disabled="deletingId === examSubType.id"
                    @click="removeExamSubType(examSubType)"
                  >
                    {{ deletingId === examSubType.id ? 'Deleting…' : 'Delete' }}
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
