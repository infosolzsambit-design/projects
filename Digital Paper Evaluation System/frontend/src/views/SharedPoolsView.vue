<script setup>
// Shared Pools (Assign Teacher menu) — every pool made with Assign Teacher
// → "Pool": who shares it and how far its sheets have got. An admin can
// change the teachers sharing a pool, or cancel it (sheets nobody has
// started yet go back to "pending to assign"; started sheets stay with
// their teachers). See AnswerSheetPoolController on the backend. Gated by
// 'shared-pool-list' (page), 'shared-pool-manage' and 'shared-pool-cancel'.
import { onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../composables/useToast'
import { useConfirm } from '../composables/useConfirm'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import SearchableMultiSelect from '../components/common/SearchableMultiSelect.vue'
import { courseLabel } from '../utils/course'
import { ordinal } from '../utils/ordinal'
import { formatDateTime } from '../utils/date'

const authStore = useAuthStore()
const toast = useToast()
const { confirmDialog } = useConfirm()

const pools = ref([])
const loading = ref(true)
const loadError = ref('')
const search = ref('')
const statusTab = ref('active') // 'active' | 'cancelled'
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchPools(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value.trim()) params.search = search.value.trim()
    if (statusTab.value === 'cancelled') params.status = 'deleted'
    const res = await api.get('/answer-sheet-pools', { params })
    pools.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load the shared pools.'
  } finally {
    loading.value = false
  }
}

function switchTab(tab) {
  if (statusTab.value === tab) return
  statusTab.value = tab
  fetchPools(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPools(page)
}

// --- Teachers: View button — hover for a quick preview (teleported to
// <body> so the table can't clip it), click for the full list in a modal.
// Each teacher with how many of the pool's sheets they've started. ---------
const teacherPreview = ref(null) // { pool, top, bottom, left }
const teacherModalPool = ref(null)
function showTeacherPreview(pool, event) {
  const rect = event.currentTarget.getBoundingClientRect()
  const width = 300
  const below = window.innerHeight - rect.bottom > 240
  teacherPreview.value = {
    pool,
    left: Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8)),
    top: below ? rect.bottom + 8 : null,
    bottom: below ? null : window.innerHeight - rect.top + 8,
  }
}
function hideTeacherPreview() {
  teacherPreview.value = null
}
function openTeacherModal(pool) {
  teacherPreview.value = null
  teacherModalPool.value = pool
}

// --- Manage Teachers modal -------------------------------------------------
const allTeachers = ref([])
const teachersLoading = ref(false)
async function loadTeachers() {
  if (allTeachers.value.length) return
  teachersLoading.value = true
  try {
    const res = await api.get('/teachers', { params: { per_page: 200, is_active: 'yes' } })
    allTeachers.value = res.data.data.items.map((t) => ({ id: t.id, name: t.emp_code ? `${t.name} (${t.emp_code})` : t.name }))
  } catch {
    // The modal just shows an empty list.
  } finally {
    teachersLoading.value = false
  }
}

const editingPool = ref(null)
const editingTeacherIds = ref([])
const savingTeachers = ref(false)
function openTeachers(pool) {
  editingPool.value = pool
  editingTeacherIds.value = pool.teachers.map((t) => t.id)
  loadTeachers()
}
async function saveTeachers() {
  if (!editingTeacherIds.value.length) {
    toast.error('Select at least one teacher.')
    return
  }
  savingTeachers.value = true
  try {
    await api.put(`/answer-sheet-pools/${editingPool.value.id}/teachers`, { teacher_ids: editingTeacherIds.value })
    toast.success('Pool teachers updated.')
    editingPool.value = null
    fetchPools(pagination.current_page)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not update the pool teachers.')
  } finally {
    savingTeachers.value = false
  }
}

// --- Cancel pool -----------------------------------------------------------
async function cancelPool(pool) {
  const ok = await confirmDialog({
    title: 'Cancel this pool?',
    message: `${pool.waiting_count} answer sheet(s) nobody has started yet will go back to "pending to assign". The ${pool.started_count} sheet(s) already started stay with their teachers.`,
    confirmText: 'Cancel Pool',
    danger: true,
  })
  if (!ok) return
  try {
    const res = await api.post(`/answer-sheet-pools/${pool.id}/cancel`)
    toast.success(res.data.message || 'Pool cancelled.')
    fetchPools(pagination.current_page)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not cancel this pool.')
  }
}

const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('shared-pool-list')) fetchPools(1)
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('shared-pool-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
      <span class="text-[13px] sm:text-sm text-gray-700">Shared Pools</span>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Shared Pools</h2>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg bg-white/15 p-0.5 text-[12px] font-semibold">
          <button
            v-for="tab in [{ id: 'active', label: 'Active' }, { id: 'cancelled', label: 'Cancelled' }]"
            :key="tab.id"
            type="button"
            class="px-3 h-9 rounded-md transition-colors"
            :class="statusTab === tab.id ? 'bg-white text-brand-blue' : 'text-white hover:bg-white/10'"
            @click="switchTab(tab.id)"
          >
            {{ tab.label }}
          </button>
        </div>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[12px] px-3.5 py-2 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search program or course…"
          class="w-full sm:w-56 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          @keyup.enter="fetchPools(1)"
        />
        <button type="button" class="h-10 w-10 shrink-0 rounded-lg bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors" aria-label="Search" @click="fetchPools(1)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        </button>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1120px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium whitespace-nowrap">
              <th class="px-3 py-1.5 font-medium rounded-tl-2xl w-px whitespace-nowrap">#</th>
              <th class="px-3 py-1.5 font-medium">Program / Course / Dept</th>
              <th class="px-3 py-1.5 font-medium">Exam Term / Type</th>
              <th class="px-3 py-1.5 font-medium">Teachers</th>
              <th class="px-3 py-1.5 font-medium whitespace-nowrap">Total / Waiting</th>
              <th class="px-3 py-1.5 font-medium whitespace-nowrap">Started / Evaluated</th>
              <th class="px-3 py-1.5 font-medium">Evaluation Window</th>
              <th class="px-3 py-1.5 font-medium">{{ statusTab === 'cancelled' ? 'Created / Cancelled' : 'Created By / At' }}</th>
              <th class="px-3 py-1.5 font-medium text-center rounded-tr-2xl w-px whitespace-nowrap" v-if="authStore.can('shared-pool-manage') || authStore.can('shared-pool-cancel')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="9" class="px-4 py-10 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!pools.length">
              <td colspan="9" class="px-4 py-10 text-center text-sm text-muted">
                {{ statusTab === 'cancelled' ? 'No cancelled pools.' : 'No shared pools yet. Create one from Assign Teacher → Pool.' }}
              </td>
            </tr>
            <tr v-for="(pool, index) in pools" v-else :key="pool.id" class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top">
              <td class="px-3 py-2">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium"># {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}</span>
              </td>
              <!-- Program, Course and Dept — labelled, lined up. -->
              <td class="px-3 py-2 min-w-[270px]">
                <div class="grid grid-cols-[auto_auto_minmax(0,1fr)] gap-x-1.5 gap-y-0.5 leading-snug">
                  <span class="text-muted">Prog.</span><span class="text-muted">:</span>
                  <span class="font-medium text-gray-900">{{ pool.program_display || pool.program_name || '—' }}</span>
                  <span class="text-muted">Course</span><span class="text-muted">:</span>
                  <span class="whitespace-nowrap">{{ pool.course_name ? courseLabel(pool.course_name, pool.course_code, pool.course_type) : '—' }}</span>
                  <span class="text-muted">Dept.</span><span class="text-muted">:</span>
                  <span>{{ pool.department_name || 'All departments' }}</span>
                </div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <p class="leading-snug">{{ pool.exam_term_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ pool.exam_type_name || '—' }} · Sem {{ ordinal(pool.semester) }} · {{ pool.exam_year }}</p>
              </td>
              <!-- How many teachers share it + a View button: hover previews each
                   teacher with their started count, click opens the modal. -->
              <td class="px-3 py-2 whitespace-nowrap">
                <p class="leading-snug mb-1">{{ pool.teachers.length }} teacher{{ pool.teachers.length === 1 ? '' : 's' }}</p>
                <button
                  v-if="pool.teacher_breakdown?.length"
                  type="button"
                  class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg bg-soft text-brand-blue text-[12px] font-medium hover:bg-brand-blue/10 transition-colors"
                  :aria-label="`View the teachers of this pool`"
                  @mouseenter="showTeacherPreview(pool, $event)"
                  @mouseleave="hideTeacherPreview"
                  @focus="showTeacherPreview(pool, $event)"
                  @blur="hideTeacherPreview"
                  @click.stop="openTeacherModal(pool)"
                >
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                  View
                </button>
              </td>
              <td class="px-3 py-2">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 leading-snug whitespace-nowrap">
                  <span class="text-muted">Total</span><span class="text-muted">:</span><span class="font-semibold text-gray-900">{{ pool.sheet_count }}</span>
                  <span class="text-muted">Waiting</span><span class="text-muted">:</span><span class="font-semibold text-cyan-700">{{ pool.waiting_count }}</span>
                </div>
              </td>
              <td class="px-3 py-2">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 leading-snug whitespace-nowrap">
                  <span class="text-muted">Started</span><span class="text-muted">:</span><span class="font-semibold text-brand">{{ pool.started_count }}</span>
                  <span class="text-muted">Evaluated</span><span class="text-muted">:</span><span class="font-semibold text-success">{{ pool.evaluated_count }}</span>
                </div>
              </td>
              <td class="px-3 py-2">
                <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 leading-snug whitespace-nowrap">
                  <span class="text-muted">Start</span><span class="text-muted">:</span><span>{{ formatDateTime(pool.evaluation_start_date) }}</span>
                  <span class="text-muted">End</span><span class="text-muted">:</span><span>{{ formatDateTime(pool.evaluation_end_date) }}</span>
                </div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <p class="leading-snug">{{ pool.created_by_name || '—' }}</p>
                <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ formatDateTime(pool.created_at) }}</p>
                <p v-if="pool.cancelled_at" class="mt-0.5 text-[11px] text-brand leading-snug">Cancelled {{ formatDateTime(pool.cancelled_at) }}</p>
              </td>
              <td v-if="authStore.can('shared-pool-manage') || authStore.can('shared-pool-cancel')" class="px-3 py-2 text-center relative" @click.stop>
                <RowActionMenu v-if="statusTab === 'active'" width="w-44">
                  <button v-if="authStore.can('shared-pool-manage')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="openTeachers(pool)">
                    Manage Teachers
                  </button>
                  <button v-if="authStore.can('shared-pool-cancel')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-brand hover:bg-brand/5 transition-colors" @click="cancelPool(pool)">
                    Cancel Pool
                  </button>
                </RowActionMenu>
                <span v-else class="text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Pagination :current-page="pagination.current_page" :last-page="pagination.last_page" :total="pagination.total" @change="goToPage" />

    <Teleport to="body">
      <!-- Teachers hover preview -->
      <div
        v-if="teacherPreview"
        class="fixed z-[150] w-[300px] rounded-xl bg-white shadow-panel border border-soft p-3 pointer-events-none"
        :style="{ left: teacherPreview.left + 'px', top: teacherPreview.top != null ? teacherPreview.top + 'px' : 'auto', bottom: teacherPreview.bottom != null ? teacherPreview.bottom + 'px' : 'auto' }"
        role="tooltip"
      >
        <p class="text-[11px] font-semibold text-muted uppercase tracking-wide mb-1.5">Teachers · sheets started</p>
        <ul class="space-y-1">
          <li v-for="t in teacherPreview.pool.teacher_breakdown.slice(0, 8)" :key="t.id" class="flex items-center justify-between gap-3 text-[12px] leading-snug">
            <span class="truncate" :class="t.in_pool ? 'text-gray-800' : 'text-muted'">{{ t.name }}<span v-if="!t.in_pool"> (removed)</span></span>
            <span class="shrink-0 font-semibold text-brand">{{ t.started_count }}</span>
          </li>
        </ul>
        <p class="mt-2 pt-2 border-t border-soft text-[11px] text-brand-blue">
          <template v-if="teacherPreview.pool.teacher_breakdown.length > 8">+{{ teacherPreview.pool.teacher_breakdown.length - 8 }} more · </template>Click to view all
        </p>
      </div>

      <!-- Teachers modal -->
      <div v-if="teacherModalPool" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="teacherModalPool = null">
        <div class="w-full max-w-[600px] max-h-[80vh] flex flex-col rounded-[24px] bg-white shadow-panel overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="pool-teachers-title">
          <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-soft">
            <div class="min-w-0">
              <h2 id="pool-teachers-title" class="text-[18px] font-semibold text-gray-900">Pool Teachers</h2>
              <p class="text-[12px] text-muted mt-0.5 truncate">
                {{ teacherModalPool.course_name ? courseLabel(teacherModalPool.course_name, teacherModalPool.course_code, teacherModalPool.course_type) : 'Pool' }}
                · {{ teacherModalPool.waiting_count }} of {{ teacherModalPool.sheet_count }} sheet(s) still waiting
              </p>
            </div>
            <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="teacherModalPool = null">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
          </div>
          <div class="flex-1 overflow-y-auto px-5 py-3">
            <table class="w-full text-left border-separate border-spacing-0">
              <thead class="sticky top-0">
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-3 py-2 rounded-tl-xl w-px whitespace-nowrap">#</th>
                  <th class="px-3 py-2">Teacher</th>
                  <th class="px-3 py-2 text-center">Started</th>
                  <th class="px-3 py-2 text-center rounded-tr-xl">Evaluated</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(t, i) in teacherModalPool.teacher_breakdown" :key="t.id" class="text-[12.5px] text-gray-800 even:bg-gray-50">
                  <td class="px-3 py-2 text-muted border-b border-gray-100">{{ i + 1 }}</td>
                  <td class="px-3 py-2 border-b border-gray-100">
                    <span class="font-medium">{{ t.name }}</span><span v-if="t.emp_code" class="text-muted"> ({{ t.emp_code }})</span>
                    <span v-if="!t.in_pool" class="ml-1.5 inline-flex items-center rounded-full bg-gray-100 text-muted text-[10.5px] font-semibold px-2 py-0.5" title="Removed from the pool — keeps the sheets they had already started">Removed</span>
                  </td>
                  <td class="px-3 py-2 text-center font-semibold text-brand border-b border-gray-100">{{ t.started_count }}</td>
                  <td class="px-3 py-2 text-center font-semibold text-success border-b border-gray-100">{{ t.evaluated_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Manage Teachers -->
    <div v-if="editingPool" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="editingPool = null">
      <div class="bg-white rounded-[24px] shadow-card w-full max-w-lg px-6 py-6">
        <div class="flex items-start justify-between gap-3 mb-1">
          <h2 class="text-lg font-bold text-black">Manage Pool Teachers</h2>
          <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="editingPool = null">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
          </button>
        </div>
        <p class="text-[12.5px] text-muted mb-4">
          {{ editingPool.course_name ? courseLabel(editingPool.course_name, editingPool.course_code, editingPool.course_type) : 'This pool' }} —
          {{ editingPool.waiting_count }} sheet(s) still waiting. Added teachers get a notification; removed teachers keep any sheet they already started.
        </p>
        <label class="text-[12px] text-label">Teachers sharing this pool <span class="text-brand">*</span></label>
        <SearchableMultiSelect
          v-model="editingTeacherIds"
          class="mt-1"
          :options="allTeachers"
          :loading="teachersLoading"
          placeholder="Select teachers"
          search-placeholder="Search teachers…"
        />
        <div class="mt-5 flex items-center justify-end gap-3">
          <button type="button" class="min-w-[110px] h-10 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="editingPool = null">Close</button>
          <button type="button" class="min-w-[110px] h-10 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-opacity disabled:opacity-60" :disabled="savingTeachers" @click="saveTeachers">
            {{ savingTeachers ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
