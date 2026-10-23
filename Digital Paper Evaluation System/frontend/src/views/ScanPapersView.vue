<script setup>
// "Generate QR & Scan" → Scan Papers: the packets Generate QR created whose
// scan isn't complete yet (question_answer_sheet_mappings.is_scan_complete
// = 0 — GET /answer-sheet-mappings?is_scan_complete=0). Once a packet's
// scan is complete it leaves this list and shows on Answer Sheets instead
// (and can then be assigned to teachers). Same shell as AnswerSheetsView.vue.
// Row actions: "Print Student List" and "Print QR Code" each open a
// printable A4 PDF (the student list / the QR labels — built in the browser,
// utils/qrCodePdf.js); "Scan" opens the packet's scan page (ScanPacketView.vue).
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useToast } from '../composables/useToast'
import { buildQrCodePdf } from '../utils/qrCodePdf'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'
import { useProgramsStore } from '../stores/programs'
import { courseLabel } from '../utils/course'
import { ordinal } from '../utils/ordinal'
import { formatDateTime } from '../utils/date'

// Program names display as "Name (Label)" — see stores/programs.js.
const programsStore = useProgramsStore()
programsStore.load().catch(() => {})

const router = useRouter()

const packets = ref([])
const loading = ref(true)
const loadError = ref('')
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })
const search = ref('')

async function fetchPackets(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page, is_scan_complete: 0 }
    if (search.value.trim()) params.search = search.value.trim()
    const res = await api.get('/answer-sheet-mappings', { params })
    packets.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load the packets.'
  } finally {
    loading.value = false
  }
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPackets(page)
}

// --- Print Student List / Print QR Code ---
const toast = useToast()
const printing = ref(null) // `${packet.id}-${part}` while that PDF is being prepared
async function printPdf(packet, part) {
  if (printing.value) return
  // Opened right away, inside the click — a tab opened after the await
  // would be stopped by the browser's popup blocker.
  const tab = window.open('', '_blank')
  if (tab) tab.document.write('<p style="font-family:sans-serif;padding:24px">Preparing the QR codes…</p>')
  printing.value = `${packet.id}-${part}`
  try {
    const res = await api.get(`/qr/packets/${packet.id}/codes`, { skipLoader: true })
    const { packet: details, codes } = res.data.data
    if (!codes.length) throw new Error('This packet has no QR codes.')
    const bytes = await buildQrCodePdf(details, codes, part)
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
    if (tab) {
      tab.location.href = url
    } else {
      // Popups blocked — download it instead.
      const a = document.createElement('a')
      a.href = url
      a.download = `${part === 'list' ? 'student-list' : 'qr-codes'}-${details.packet_code}.pdf`
      a.click()
    }
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (err) {
    tab?.close()
    toast.error(err.response?.data?.message || err.message || 'Could not prepare the QR codes.')
  } finally {
    printing.value = null
  }
}

onMounted(() => fetchPackets(1))
</script>

<template>
  <div>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-2.5 flex items-center gap-3 min-h-[44px]">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Generate QR &amp; Scan</span>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Scan Papers</span>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-3">{{ loadError }}</p>

    <!-- Section title bar -->
    <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-2 mb-1.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-2.5 min-w-0">
        <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" />
            <line x1="7" y1="12" x2="17" y2="12" />
          </svg>
        </span>
        <h2 class="text-white text-[15px] font-medium truncate">Scan Papers</h2>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3 py-1.5 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search packets…"
          class="w-full sm:w-56 h-8 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          @keyup.enter="fetchPackets(1)"
        />
        <button
          type="button"
          class="h-8 w-8 shrink-0 rounded-lg bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors"
          aria-label="Search"
          @click="fetchPackets(1)"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        </button>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[960px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl w-px whitespace-nowrap">#</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Packet Code</th>
              <th class="px-4 py-1.5 font-medium">Program / Course</th>
              <th class="px-4 py-1.5 font-medium">Exam</th>
              <th class="px-4 py-1.5 font-medium">Department</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Batch</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">Exam Year</th>
              <th class="px-4 py-1.5 font-medium whitespace-nowrap">QR Codes</th>
              <th class="px-4 py-1.5 font-medium">Generated</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl w-px whitespace-nowrap">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!packets.length">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">
                {{ search.trim() ? 'No packets match your search.' : 'No packets are waiting to be scanned.' }}
              </td>
            </tr>
            <tr
              v-for="(packet, index) in packets"
              v-else
              :key="packet.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1.5">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1.5 font-semibold whitespace-nowrap">{{ packet.packet_code }}</td>
              <td class="px-4 py-1.5">
                <p class="font-medium text-gray-900 leading-snug">{{ programsStore.display(packet.program_name) || '—' }}</p>
                <p class="text-[11px] text-muted leading-snug">
                  {{ packet.course_name ? courseLabel(packet.course_name, packet.course_code, packet.course_type) : '—' }} · {{ ordinal(packet.semester) }} Sem
                </p>
              </td>
              <td class="px-4 py-1.5">
                <p class="leading-snug">{{ packet.exam_term_name || '—' }}</p>
                <p class="text-[11px] text-muted leading-snug">{{ [packet.exam_type_name, packet.exam_sub_type_name].filter(Boolean).join(' · ') || '—' }}</p>
              </td>
              <td class="px-4 py-1.5">{{ packet.department_name || '—' }}</td>
              <td class="px-4 py-1.5 whitespace-nowrap">{{ packet.batch || '—' }}</td>
              <td class="px-4 py-1.5 whitespace-nowrap">{{ packet.packet_exam_year || '—' }}</td>
              <td class="px-4 py-1.5 whitespace-nowrap">
                <span class="inline-flex items-center rounded-full bg-soft text-brand-blue px-2.5 py-0.5 text-[11px] font-semibold">{{ packet.answer_sheet_count }}</span>
              </td>
              <td class="px-4 py-1.5">
                <p class="leading-snug">{{ packet.created_by_name || '—' }}</p>
                <p class="text-[11px] text-muted leading-snug">{{ formatDateTime(packet.created_at) }}</p>
              </td>
              <td class="px-4 py-1.5 text-center relative" @click.stop>
                <RowActionMenu width="w-48">
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors disabled:opacity-50"
                    :disabled="printing === `${packet.id}-list`"
                    @click="printPdf(packet, 'list')"
                  >
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg>
                    {{ printing === `${packet.id}-list` ? 'Preparing…' : 'Print Student List' }}
                  </button>
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors disabled:opacity-50"
                    :disabled="printing === `${packet.id}-labels`"
                    @click="printPdf(packet, 'labels')"
                  >
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3" /></svg>
                    {{ printing === `${packet.id}-labels` ? 'Preparing…' : 'Print QR Code' }}
                  </button>
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="router.push({ name: 'qr-scan-packet', params: { id: packet.id } })"
                  >
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" /><line x1="7" y1="12" x2="17" y2="12" /></svg>
                    Scan
                  </button>
                </RowActionMenu>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Pagination
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      :total="pagination.total"
      @change="goToPage"
    />
  </div>
</template>
