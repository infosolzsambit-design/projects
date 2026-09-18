<script setup>
// Configuration → Email Logs — every actual email attempt the app has made
// (assign-teacher notices, reassignments, issue raised/resolved, ...), one
// row per attempt (see backend EmailLog's own docblock). Gated by
// 'email-log-list' (the page itself) and 'email-resend-button' (just the
// Resend action) — same UI-only authStore.can() pattern as every sibling
// page under Configurations.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../../utils/api'
import { useAuthStore } from '../../stores/auth'
import { formatDateTime } from '../../utils/date'
import RowActionMenu from '../../components/common/RowActionMenu.vue'
import Pagination from '../../components/common/Pagination.vue'
import ResendEmailModal from '../../components/emailLogs/ResendEmailModal.vue'

const authStore = useAuthStore()

const logs = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const typeFilter = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'
const dateFrom = ref('')
const dateTo = ref('')

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchLogs(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (typeFilter.value) params.type = typeFilter.value
    if (statusFilter.value) params.is_sent = statusFilter.value
    if (dateFrom.value) params.date_from = dateFrom.value
    if (dateTo.value) params.date_to = dateTo.value

    const res = await api.get('/email-logs', { params })
    logs.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load email logs.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchLogs(1)
}

const availableTypes = ref([])
async function loadTypes() {
  try {
    const res = await api.get('/email-logs/types')
    availableTypes.value = res.data.data
  } catch {
    // Non-fatal — the Type filter just stays empty; search still works.
  }
}

// Same "Type" tags are all snake_case (e.g. answer_sheet_assigned) —
// title-cased here purely for display, the raw value is still what's sent
// as ?type= .
function typeLabel(type) {
  return type ? type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—'
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
  fetchLogs(1)
}
function resetFilters() {
  typeFilter.value = ''
  statusFilter.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  showFilter.value = false
  fetchLogs(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchLogs(page)
}

const resendingLog = ref(null)
function openResend(log) {
  resendingLog.value = log
}
function closeResend() {
  resendingLog.value = null
}
function onResent() {
  resendingLog.value = null
  fetchLogs(pagination.current_page)
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('email-log-list')) {
    fetchLogs(1)
    loadTypes()
  }
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('email-log-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
      <span class="text-[13px] sm:text-sm text-gray-700">Email Logs</span>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M3 7l9 6 9-6" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">Email Logs</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search subject or recipient…"
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
            aria-label="Filter email logs"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Type</label>
                <div class="relative">
                  <select
                    v-model="typeFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Types</option>
                    <option v-for="type in availableTypes" :key="type" :value="type">{{ typeLabel(type) }}</option>
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
                    <option value="yes">Sent</option>
                    <option value="no">Failed</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">From</label>
                <input v-model="dateFrom" type="date" class="w-full h-8 px-3 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">To</label>
                <input v-model="dateTo" type="date" class="w-full h-8 px-3 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition" />
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
              <th class="px-4 py-1.5 font-medium">Subject</th>
              <th class="px-4 py-1.5 font-medium">To</th>
              <th class="px-4 py-1.5 font-medium">Type</th>
              <th class="px-4 py-1.5 font-medium">Status</th>
              <th class="px-4 py-1.5 font-medium">Sent / Attempted At</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!logs.length">
              <td colspan="7" class="px-4 py-10 text-center text-sm text-muted">No email logs found.</td>
            </tr>
            <tr
              v-for="(log, index) in logs"
              v-else
              :key="log.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1 max-w-[320px]">
                <p class="font-semibold truncate">{{ log.subject }}</p>
              </td>
              <td class="px-4 py-1">
                {{ log.receiver_name || '—' }}
                <span v-if="log.receiver_email" class="text-muted block text-[11.5px]">{{ log.receiver_email }}</span>
              </td>
              <td class="px-4 py-1">{{ typeLabel(log.type) }}</td>
              <td class="px-4 py-1">
                <span
                  class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold"
                  :class="log.is_sent ? 'bg-success/10 text-success' : 'bg-brand/10 text-brand'"
                >
                  {{ log.is_sent ? 'Sent' : 'Failed' }}
                </span>
                <p v-if="!log.is_sent && log.error_message" class="text-[11px] text-brand mt-1 max-w-[220px] truncate" :title="log.error_message">
                  {{ log.error_message }}
                </p>
              </td>
              <td class="px-4 py-1 whitespace-nowrap">{{ formatDateTime(log.sent_at || log.created_at) }}</td>
              <td class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu width="w-40">
                  <button
                    v-if="!log.is_sent && authStore.can('email-resend-button')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="openResend(log)"
                  >
                    Resend
                  </button>
                  <span v-else-if="log.is_sent" class="block px-3.5 py-2 text-[12.5px] text-muted">Already sent</span>
                  <span v-else class="block px-3.5 py-2 text-[12.5px] text-muted">—</span>
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

    <ResendEmailModal v-if="resendingLog" :log="resendingLog" @close="closeResend" @resent="onResent" />
  </div>
</template>
