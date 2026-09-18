<script setup>
// Sidebar's "Notifications" page — every evaluation issue a teacher has
// raised against an answer sheet (see RaiseIssueModal.vue /
// MyPendingCourseController::raiseIssue()), most recent first, open or
// already resolved. Same list-page shell as every other master list in
// this app (breadcrumb, colored title bar with a Total pill, gradient
// table header) — see master/DepartmentsView.vue for the pattern this
// mirrors. The header's search box + "Filter" dropdown (Issue Type,
// Status, Raised date range) mirrors designed_files/subject-list.html's
// own Filter panel, wired to real query params on GET /notifications
// (see NotificationController::index()) instead of that mockup's inert
// jQuery datepicker.
//
// "Resolve" only shows for a still-open issue and opens whichever modal
// matches its real type — is_printing_issue/is_timing_issue are resolved
// by id server-side (see NotificationResource's own docblock), never by
// matching an issue's name string.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useNotificationsStore } from '../stores/notifications'
import { formatDateTime } from '../utils/date'
import DatePicker from '../components/common/DatePicker.vue'
import ResolveTimingIssueModal from '../components/notifications/ResolveTimingIssueModal.vue'
import ResolvePrintingIssueModal from '../components/notifications/ResolvePrintingIssueModal.vue'

const authStore = useAuthStore()
const notificationsStore = useNotificationsStore()

const notifications = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const filters = reactive({ issue_type: '', status: '', raised_from: '', raised_to: '' })

async function loadNotifications() {
  loading.value = true
  loadError.value = ''
  try {
    const params = {}
    if (search.value) params.search = search.value
    if (filters.issue_type) params.issue_type = filters.issue_type
    if (filters.status) params.status = filters.status
    if (filters.raised_from) params.raised_from = filters.raised_from
    if (filters.raised_to) params.raised_to = filters.raised_to
    const res = await api.get('/notifications', { params })
    notifications.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load notifications.'
  } finally {
    loading.value = false
  }
}
// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('notification-list')) loadNotifications()
})

function runSearch() {
  loadNotifications()
}

// --- Filter dropdown — same open/close-on-outside-click pattern as
// master/DepartmentsView.vue's own status filter. ---
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
  loadNotifications()
}
function resetFilters() {
  filters.issue_type = ''
  filters.status = ''
  filters.raised_from = ''
  filters.raised_to = ''
  showFilter.value = false
  loadNotifications()
}

const resolvingTiming = ref(null) // notification row, or null
const resolvingPrinting = ref(null) // notification row, or null

function openResolve(item) {
  if (item.is_printing_issue) resolvingPrinting.value = item
  else if (item.is_timing_issue) resolvingTiming.value = item
}
// Just refreshes the list in the background — closing the modal is each
// modal's own call now (see ResolvePrintingIssueModal.vue, which shows a
// "Resolved" confirmation in place rather than closing immediately).
function onResolved() {
  loadNotifications()
  notificationsStore.loadUnresolvedCount()
}
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('notification-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-3.5 mb-5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Notifications</span>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar, same pattern as every other list page's colored
         info bar directly above its table (see master/DepartmentsView.vue),
         with the Filter dropdown from designed_files/subject-list.html. -->
    <section class="relative z-30 bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Raised Issues</h2>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:ml-auto" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ notifications.length }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search notifications…"
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
            aria-label="Filter notifications"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Issue Type</label>
                <div class="relative">
                  <select
                    v-model="filters.issue_type"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Types</option>
                    <option value="printing">Printing Issue</option>
                    <option value="timing">Timing Issue</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Status</label>
                <div class="relative">
                  <select
                    v-model="filters.status"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Status</option>
                    <option value="open">Open</option>
                    <option value="resolved">Resolved</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div>
                <p class="text-[13px] text-label mb-1">Raised Date</p>
                <div class="grid grid-cols-2 gap-3">
                  <div class="flex flex-col gap-1 min-w-0">
                    <label class="text-[12px] text-muted">From Date</label>
                    <DatePicker v-model="filters.raised_from" dense />
                  </div>
                  <div class="flex flex-col gap-1 min-w-0">
                    <label class="text-[12px] text-muted">To Date</label>
                    <DatePicker v-model="filters.raised_to" dense :min="filters.raised_from" />
                  </div>
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
        <table class="w-full min-w-[920px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Unique Number</th>
              <th class="px-4 py-1.5 font-medium">Course</th>
              <th class="px-4 py-1.5 font-medium">Issue Type</th>
              <th class="px-4 py-1.5 font-medium">Remarks</th>
              <th class="px-4 py-1.5 font-medium">Raised By</th>
              <th class="px-4 py-1.5 font-medium">Raised At</th>
              <th class="px-4 py-1.5 font-medium">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('notification-resolve')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="9" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!notifications.length">
              <td colspan="9" class="px-4 py-8 text-center text-sm text-muted">No notifications found.</td>
            </tr>
            <tr
              v-for="(item, index) in notifications"
              v-else
              :key="item.answer_sheet_id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium"># {{ index + 1 }}</span>
              </td>
              <td class="px-4 py-1 font-semibold">{{ item.unique_number || '—' }}</td>
              <td class="px-4 py-1">
                {{ item.course_name || '—' }}
                <span v-if="item.course_code" class="text-muted">({{ item.course_code }})</span>
              </td>
              <td class="px-4 py-1">{{ item.issue_name || '—' }}</td>
              <td class="px-4 py-1 max-w-[220px] truncate" :title="item.remarks || undefined">{{ item.remarks || '—' }}</td>
              <td class="px-4 py-1">{{ item.raised_by || '—' }}</td>
              <td class="px-4 py-1 whitespace-nowrap">{{ formatDateTime(item.raised_at) }}</td>
              <td class="px-4 py-1">
                <span
                  class="inline-flex items-center rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
                  :class="item.issue_status === 'open' ? 'text-brand' : 'text-success'"
                >
                  {{ item.issue_status === 'open' ? 'Open' : 'Resolved' }}
                </span>
              </td>
              <td class="px-4 py-1 text-center" v-if="authStore.can('notification-resolve')">
                <button
                  v-if="item.issue_status === 'open'"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg status-gradient-border px-3.5 py-2 text-[13px] font-medium text-gray-800 hover:text-brand-blue transition-colors"
                  @click="openResolve(item)"
                >
                  <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
                  Resolve
                </button>
                <span v-else class="text-[12px] text-muted">
                  {{ item.fixed_by ? `by ${item.fixed_by}` : '—' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <ResolveTimingIssueModal
      v-if="resolvingTiming"
      :notification="resolvingTiming"
      @close="resolvingTiming = null"
      @resolved="onResolved"
    />
    <ResolvePrintingIssueModal
      v-if="resolvingPrinting"
      :notification="resolvingPrinting"
      @close="resolvingPrinting = null"
      @resolved="onResolved"
    />
  </div>
</template>
