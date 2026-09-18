<script setup>
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
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

const groups = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchGroups(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.is_active = statusFilter.value

    const res = await api.get('/permission-groups', { params })
    groups.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load permission groups.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchGroups(1)
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
  fetchGroups(1)
}
function resetFilters() {
  statusFilter.value = ''
  showFilter.value = false
  fetchGroups(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchGroups(page)
}

const deletingId = ref(null)
const togglingId = ref(null)

async function toggleStatus(group) {
  togglingId.value = group.id
  const next = !group.status
  try {
    await api.put(`/permission-groups/${group.id}`, { status: next })
    group.status = next
    toast.success(`Permission group ${next ? 'activated' : 'deactivated'} successfully.`)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not update permission group status.'
  } finally {
    togglingId.value = null
  }
}

// --- Inline Sort Order edit — click the value to turn it into a number
// input (same click-to-edit idea as the Status switch's toggle-in-place),
// Enter/blur saves, Escape cancels.
const editingSortOrderId = ref(null)
const sortOrderDraft = ref(0)
const savingSortOrderId = ref(null)
const sortOrderInputRefs = {}

function startEditSortOrder(group) {
  editingSortOrderId.value = group.id
  sortOrderDraft.value = group.sort_order
  nextTick(() => sortOrderInputRefs[group.id]?.focus())
}

function cancelEditSortOrder() {
  editingSortOrderId.value = null
}

async function saveSortOrder(group) {
  const next = Number(sortOrderDraft.value)
  editingSortOrderId.value = null

  if (!Number.isInteger(next) || next < 0 || next === group.sort_order) return

  savingSortOrderId.value = group.id
  try {
    await api.put(`/permission-groups/${group.id}`, { sort_order: next })
    group.sort_order = next
    toast.success('Sort order updated successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not update sort order.'
  } finally {
    savingSortOrderId.value = null
  }
}

function goToCreate() {
  router.push({ name: 'configurations-permission-groups-create' })
}

function goToEdit(group) {
  router.push({ name: 'configurations-permission-groups-edit', params: { id: group.id } })
}

async function removeGroup(group) {
  const confirmed = await confirmDialog({
    title: 'Delete Permission Group',
    message: `Delete permission group "${group.name}"? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = group.id
  try {
    await api.delete(`/permission-groups/${group.id}`)
    if (groups.value.length === 1 && pagination.current_page > 1) {
      await fetchGroups(pagination.current_page - 1)
    } else {
      await fetchGroups(pagination.current_page)
    }
    toast.success('Permission group deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete permission group.'
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
  if (authStore.can('permission-group-list')) fetchGroups(1)
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('permission-group-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
        <span class="text-[13px] sm:text-sm text-gray-700">Permission Group List</span>
      </div>
      <div v-if="authStore.can('permission-group-add')" class="flex items-center gap-2">
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToCreate"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
          Add Permission Group
        </button>
      </div>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="10" rx="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Permission Groups</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search permission groups…"
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
            aria-label="Filter permission groups"
          >
            <div class="flex flex-col gap-3">
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
        <table class="w-full min-w-[560px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Name</th>
              <th class="px-4 py-1.5 font-medium">Sort Order</th>
              <th class="px-4 py-1.5 font-medium" v-if="authStore.can('permission-group-status-change')">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('permission-group-edit') || authStore.can('permission-group-delete')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!groups.length">
              <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">No permission groups found.</td>
            </tr>
            <tr
              v-for="(group, index) in groups"
              v-else
              :key="group.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1 font-semibold">{{ group.name }}</td>
              <td class="px-4 py-1" @click.stop>
                <input
                  v-if="editingSortOrderId === group.id"
                  :ref="(el) => (sortOrderInputRefs[group.id] = el)"
                  v-model="sortOrderDraft"
                  type="number"
                  min="0"
                  class="w-16 h-8 px-2 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                  @keyup.enter="saveSortOrder(group)"
                  @keyup.escape="cancelEditSortOrder"
                  @blur="saveSortOrder(group)"
                />
                <button
                  v-else
                  type="button"
                  class="inline-flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg border border-transparent hover:border-input-border hover:bg-soft transition-colors disabled:opacity-50"
                  title="Click to edit sort order"
                  :disabled="savingSortOrderId === group.id"
                  @click="startEditSortOrder(group)"
                >
                  {{ savingSortOrderId === group.id ? '…' : group.sort_order }}
                </button>
              </td>
              <td v-if="authStore.can('permission-group-status-change')" class="px-4 py-1" @click.stop>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="group.status"
                  :disabled="togglingId === group.id"
                  :title="group.status ? 'Click to deactivate' : 'Click to activate'"
                  class="relative inline-flex items-center w-20 h-6 rounded-full text-[10px] font-semibold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="group.status ? 'bg-btn-gradient text-white justify-start pl-2.5 pr-6' : 'bg-gray-300 text-gray-600 justify-end pl-6 pr-2.5'"
                  @click="toggleStatus(group)"
                >
                  <span>{{ group.status ? 'Active' : 'Inactive' }}</span>
                  <span
                    class="absolute top-[5px] left-[5px] w-[14px] h-[14px] rounded-full bg-white shadow transition-transform duration-200"
                    :class="group.status ? 'translate-x-[56px]' : 'translate-x-0'"
                  ></span>
                </button>
              </td>
              <td v-if="authStore.can('permission-group-edit') || authStore.can('permission-group-delete')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu>
                  <button v-if="authStore.can('permission-group-edit')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="goToEdit(group)">
                    Edit
                  </button>
                  <button
                    v-if="authStore.can('permission-group-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                    :disabled="deletingId === group.id"
                    @click="removeGroup(group)"
                  >
                    {{ deletingId === group.id ? 'Deleting…' : 'Delete' }}
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
