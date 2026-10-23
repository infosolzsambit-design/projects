<script setup>
// Scan Papers → a packet's "Scan" (ScanPapersView.vue's row action):
// /qr/scan-papers/:id. The packet's details on top (GET
// /answer-sheet-mappings/{id}); below them the scanning itself
// (QrScanController):
//  - the scanner's own software saves each scan as a PDF into a folder; this
//    page watches that folder (utils/scanFolderWatcher.js — Chrome/Edge) and
//    takes each new PDF one at a time. Choose PDF / drag-and-drop also work.
//  - each scan: the QR code is read in the browser (utils/qr.js, as Answer
//    Sheet Upload does), then the PDF is saved against that QR's answer
//    sheet and its handwritten student ID is read on the server.
//  - a QR code that isn't valid / is from another packet → ScanProblemModal
//    (nothing saved); a student ID that doesn't match → StudentIdReviewModal
//    (Mark as Correct / Reject Scan). Scanning waits while either is open.
//  - a scan that came from the scanner folder is moved into that folder's
//    "scanned_copy" subfolder once accepted (ID matched, or Mark as Correct),
//    or its "rejected_copy" subfolder when rejected (Reject Scan).
//  - Complete → ScanCompleteModal: unscanned sheets are marked absent and the
//    packet moves to Answer Sheets.
//  - After Complete (Answer Sheets → "Scan Pending Copy"): the same page scans
//    absent students' copies in late — only those can be scanned then.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { resolveStorageUrl } from '../utils/api'
import { useToast } from '../composables/useToast'
import { useProgramsStore } from '../stores/programs'
import { courseLabel } from '../utils/course'
import { ordinal } from '../utils/ordinal'
import { extractQrFromPdfFirstPage } from '../utils/qr'
import { REJECTED_COPY_FOLDER, SCANNED_COPY_FOLDER, ensureFolderPermission, folderWatchSupported, moveToSubfolder, pickScanFolder, rememberedScanFolder, watchScanFolder } from '../utils/scanFolderWatcher'
import ScanProblemModal from '../components/qr/ScanProblemModal.vue'
import StudentIdReviewModal from '../components/qr/StudentIdReviewModal.vue'
import ScanCompleteModal from '../components/qr/ScanCompleteModal.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const packetId = route.params.id

// Program names display as "Name (Label)" — see stores/programs.js.
const programsStore = useProgramsStore()
programsStore.load().catch(() => {})

const packet = ref(null)
const loading = ref(true)
const loadError = ref('')

async function loadPacket() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/answer-sheet-mappings/${packetId}`)
    packet.value = res.data.data
    await loadSheets()
    // Scanning pending copies → start on the absent students' sheets.
    if (packet.value.is_scan_complete && sheets.value.some((s) => s.absent)) listFilter.value = 'not_scanned'
  } catch (err) {
    loadError.value = err.response?.status === 404 ? 'This packet no longer exists.' : err.response?.data?.message || 'Could not load this packet.'
  } finally {
    loading.value = false
  }
}

const details = computed(() => {
  const p = packet.value
  if (!p) return []
  return [
    { label: 'Program', value: programsStore.display(p.program_name) },
    { label: 'Course', value: p.course_name ? courseLabel(p.course_name, p.course_code, p.course_type) : '' },
    { label: 'Semester', value: p.semester ? `${ordinal(p.semester)} Semester` : '' },
    { label: 'Exam Term', value: p.exam_term_name },
    { label: 'Exam Type', value: p.exam_type_name },
    { label: 'Exam Sub Type', value: p.exam_sub_type_name },
    { label: 'Department', value: p.department_name },
    { label: 'Batch', value: p.batch },
    { label: 'Exam Year', value: p.packet_exam_year },
    { label: 'Question Paper', value: p.question_paper_id ? `#${p.question_paper_id}${p.exam_year ? ` — ${p.exam_year}` : ''}` : '' },
    // answer_sheet_count leaves absent students out — QR codes are all of them.
    { label: 'QR Codes', value: (p.answer_sheet_count || 0) + (p.absent_answer_sheet_count || 0) },
  ]
})

// Once complete, the packet lives on Answer Sheets (where "Scan Pending Copy" opens this).
const backTo = computed(() => (packet.value?.is_scan_complete ? { name: 'answer-sheets', label: 'Answer Sheets' } : { name: 'qr-scan-papers', label: 'Scan Papers' }))
function back() {
  router.push({ name: backTo.value.name })
}

// --- The packet's sheets --------------------------------------------------
const sheets = ref([])
async function loadSheets() {
  const res = await api.get(`/qr/packets/${packetId}/sheets`, { skipLoader: true })
  sheets.value = res.data.data.sheets
}
function upsertSheet(sheet) {
  const i = sheets.value.findIndex((s) => s.id === sheet.id)
  if (i >= 0) sheets.value.splice(i, 1, sheet)
}

const OK_STATUSES = ['matched', 'confirmed']
const needsReview = (s) => s.scanned && !OK_STATUSES.includes(s.id_status)
const counts = computed(() => ({
  total: sheets.value.length,
  scanned: sheets.value.filter((s) => s.scanned).length,
  ok: sheets.value.filter((s) => s.scanned && OK_STATUSES.includes(s.id_status)).length,
  review: sheets.value.filter(needsReview).length,
  notScanned: sheets.value.filter((s) => !s.scanned).length,
  absent: sheets.value.filter((s) => s.absent).length,
}))
const progress = computed(() => (counts.value.total ? Math.round((counts.value.scanned / counts.value.total) * 100) : 0))

const STATUS = {
  matched: { label: 'ID Matched', class: 'bg-green-50 text-green-700' },
  confirmed: { label: 'Confirmed', class: 'bg-green-50 text-green-700' },
  mismatch: { label: 'ID Mismatch', class: 'bg-red-50 text-brand' },
  unreadable: { label: 'ID Unreadable', class: 'bg-amber-50 text-amber-700' },
  error: { label: 'ID Not Checked', class: 'bg-amber-50 text-amber-700' },
  not_checked: { label: 'ID Not Checked', class: 'bg-amber-50 text-amber-700' },
  not_scanned: { label: 'Not Scanned', class: 'bg-gray-100 text-gray-500' },
}

const listFilter = ref('all') // 'all' | 'scanned' | 'review' | 'not_scanned'
const listSearch = ref('')
// Nothing left to review → back to the full list.
watch(() => counts.value.review, (n) => {
  if (!n && listFilter.value === 'review') listFilter.value = 'all'
})
const visibleSheets = computed(() => {
  const q = listSearch.value.trim().toLowerCase()
  return sheets.value.filter((s) => {
    if (listFilter.value === 'scanned' && !s.scanned) return false
    if (listFilter.value === 'review' && !needsReview(s)) return false
    if (listFilter.value === 'not_scanned' && s.scanned) return false
    return !q || [s.qr_code, s.registration_no, s.name].some((v) => String(v ?? '').toLowerCase().includes(q))
  })
})

// --- Scanning: one file at a time ------------------------------------------
// Each item: { file, handle, folder } — handle/folder only for a scan that
// came from the watched scanner folder (so it can be moved once accepted).
const queue = ref([])
const current = ref(null) // { name, stage } while one is processed
const activity = ref([]) // latest first: { key, file, type: 'ok'|'warn'|'error', text }
const problem = ref(null) // ScanProblemModal
const reviewSheet = ref(null) // StudentIdReviewModal
const reviewBusy = ref('')
const completeSummary = ref(null) // ScanCompleteModal
const completing = ref(false)

// A modal open = wait — each scan is dealt with before the next one.
const paused = computed(() => !!problem.value || !!reviewSheet.value || !!completeSummary.value)
let processing = false
let logKey = 0

function addActivity(type, file, text) {
  activity.value.unshift({ key: ++logKey, type, file, text })
  activity.value.splice(30)
}

// `items`: Files (Choose PDF / drop) or { file, handle } from the scanner folder.
function enqueue(items) {
  const scans = [...items].map((item) => (item instanceof File ? { file: item, handle: null, folder: null } : { ...item, folder: folderHandle }))
  const pdfs = scans.filter(({ file }) => file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf'))
  if (pdfs.length < scans.length) toast.error('Only PDF files can be scanned in.')
  queue.value.push(...pdfs)
  processNext()
}

// --- Moving scans into scanned_copy / rejected_copy ------------------------
// A folder scan whose student ID still needs review: sheet id → its scan,
// moved once it's accepted (Mark as Correct) or rejected (Reject Scan).
const awaitingMove = new Map()

async function moveScan(scan, subfolder = SCANNED_COPY_FOLDER) {
  if (!scan?.handle || !scan.folder) return // not from the scanner folder
  try {
    const name = await moveToSubfolder(scan.folder, scan.handle, subfolder)
    addActivity(subfolder === REJECTED_COPY_FOLDER ? 'error' : 'ok', scan.file.name, `Moved to ${subfolder}${name !== scan.file.name ? ` as “${name}”` : ''}`)
  } catch {
    addActivity('warn', scan.file.name, `Could not move it to ${subfolder} — move it by hand`)
  }
}

async function processNext() {
  if (processing || paused.value || !queue.value.length) return
  processing = true
  const scan = queue.value.shift()
  try {
    await processFile(scan)
  } finally {
    processing = false
    current.value = null
    processNext()
  }
}
watch(paused, (isPaused) => {
  if (!isPaused) processNext()
})

async function processFile(scan) {
  const { file } = scan
  current.value = { name: file.name, stage: 'Reading QR code…' }
  let qr = null
  try {
    qr = await extractQrFromPdfFirstPage(file)
  } catch {
    problem.value = { reason: 'error', message: 'This file could not be opened as a PDF. Scan the sheet again.', file_name: file.name }
    addActivity('error', file.name, 'Could not open the PDF')
    return
  }
  if (!qr || !qr.trim()) {
    problem.value = { reason: 'no_qr', message: 'No QR code could be read on this scan. Check the sheet and scan it again.', file_name: file.name }
    addActivity('error', file.name, 'No QR code found')
    return
  }

  current.value = { name: file.name, stage: `QR ${qr.trim()} — saving and checking the student ID…` }
  const form = new FormData()
  form.append('qr_code', qr.trim())
  form.append('pdf', file)
  try {
    const res = await api.post(`/qr/packets/${packetId}/scan`, form, { skipLoader: true })
    const { sheet, rescanned } = res.data.data
    upsertSheet(sheet)
    const who = `QR ${sheet.qr_code} · ${sheet.name || sheet.registration_no}`
    if (OK_STATUSES.includes(sheet.id_status)) {
      awaitingMove.delete(sheet.id)
      addActivity('ok', file.name, `${who}${rescanned ? ' — scanned again' : ''} · student ID matched`)
      await moveScan(scan)
    } else {
      awaitingMove.set(sheet.id, scan)
      addActivity('warn', file.name, `${who} · ${STATUS[sheet.id_status]?.label || 'needs checking'}`)
      reviewSheet.value = sheet
    }
  } catch (err) {
    const data = err.response?.data
    const reason = data?.errors?.reason
    if (reason === 'unknown' || reason === 'other_packet' || reason === 'already_scanned') {
      problem.value = { ...data.errors, message: data.message, file_name: file.name }
      addActivity('error', file.name, {
        other_packet: () => `QR ${data.errors.qr_code} belongs to packet ${data.errors.other_packet?.packet_code}`,
        already_scanned: () => `QR ${data.errors.qr_code} was already scanned`,
        unknown: () => `QR ${data.errors.qr_code} is not valid`,
      }[reason]())
    } else {
      const message = data?.errors && !reason ? Object.values(data.errors)[0]?.[0] : data?.message
      problem.value = { reason: 'error', message: message || 'This scan could not be saved. Scan the sheet again.', file_name: file.name, qr_code: qr.trim() }
      addActivity('error', file.name, message || 'Not saved')
    }
  }
}

// --- Review modal ---------------------------------------------------------
async function confirmReview() {
  const sheet = reviewSheet.value
  reviewBusy.value = 'confirm'
  try {
    const res = await api.post(`/qr/packets/${packetId}/sheets/${sheet.id}/confirm`, {}, { skipLoader: true })
    upsertSheet(res.data.data.sheet)
    addActivity('ok', '', `QR ${sheet.qr_code} · ${sheet.name || sheet.registration_no} — student ID confirmed`)
    reviewSheet.value = null
    const scan = awaitingMove.get(sheet.id)
    awaitingMove.delete(sheet.id)
    await moveScan(scan)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not confirm the student ID.')
  } finally {
    reviewBusy.value = ''
  }
}
async function rejectReview() {
  const sheet = reviewSheet.value
  reviewBusy.value = 'reject'
  try {
    const res = await api.delete(`/qr/packets/${packetId}/sheets/${sheet.id}/scan`, { skipLoader: true })
    upsertSheet(res.data.data.sheet)
    addActivity('error', '', `QR ${sheet.qr_code} · scan rejected — scan this sheet again`)
    reviewSheet.value = null
    const scan = awaitingMove.get(sheet.id)
    awaitingMove.delete(sheet.id)
    await moveScan(scan, REJECTED_COPY_FOLDER)
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not remove the scan.')
  } finally {
    reviewBusy.value = ''
  }
}

// --- Scanner folder -------------------------------------------------------
const folderName = ref('')
const rememberedFolder = ref(null)
const watching = ref(false)
const lastCheck = ref(null) // { checkedAt, pdfCount } — shows the watcher is alive
const existingFiles = ref([]) // PDFs already in the folder when connected
let stopWatch = null
let folderHandle = null // the connected scanner folder

function stopWatching() {
  stopWatch?.()
  stopWatch = null
  watching.value = false
  lastCheck.value = null
  existingFiles.value = []
}

// The PDFs that were already in the folder — only when asked.
function scanExisting() {
  enqueue(existingFiles.value)
  existingFiles.value = []
}

async function connectFolder(useRemembered) {
  try {
    const handle = useRemembered ? rememberedFolder.value : await pickScanFolder()
    if (!(await ensureFolderPermission(handle))) {
      toast.error('Permission to use the folder was not given (needed to read scans and move them to scanned_copy).')
      return
    }
    stopWatching()
    folderHandle = handle
    const watcher = await watchScanFolder(
      handle,
      (scan) => enqueue([scan]),
      () => {
        watching.value = false
        toast.error('Lost access to the scanner folder — connect it again.')
      },
      (info) => (lastCheck.value = info),
    )
    stopWatch = watcher.stop
    existingFiles.value = watcher.existing
    folderName.value = handle.name
    rememberedFolder.value = handle
    watching.value = true
  } catch (err) {
    if (err?.name !== 'AbortError') toast.error('Could not open that folder.')
  }
}

// --- Choose PDF / drag and drop -------------------------------------------
const fileInput = ref(null)
const dragging = ref(false)
function onPick(event) {
  enqueue(event.target.files)
  event.target.value = ''
}
function onDrop(event) {
  dragging.value = false
  enqueue(event.dataTransfer.files)
}

// --- Complete ---------------------------------------------------------------
async function startComplete() {
  try {
    const res = await api.post(`/qr/packets/${packetId}/complete`, {}, { skipLoader: true })
    completeSummary.value = res.data.data
  } catch (err) {
    const data = err.response?.data
    if (data?.errors?.reason === 'needs_review') listFilter.value = 'review'
    toast.error(data?.message || 'Could not check the packet.')
  }
}
async function confirmComplete() {
  completing.value = true
  try {
    const res = await api.post(`/qr/packets/${packetId}/complete`, { mark_absent: true }, { skipLoader: true })
    const s = res.data.data
    stopWatching()
    toast.success(`Scan complete — ${s.scanned} scanned${s.unscanned ? `, ${s.unscanned} marked absent` : ''}.`)
    router.push({ name: 'qr-scan-papers' })
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not complete the scan.')
    completeSummary.value = null
  } finally {
    completing.value = false
  }
}

onMounted(async () => {
  await loadPacket()
  if (folderWatchSupported) rememberedFolder.value = await rememberedScanFolder()
})
onBeforeUnmount(stopWatching)
</script>

<template>
  <div>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-2.5 flex items-center justify-between gap-3 min-h-[44px]">
      <div class="flex items-center gap-3 min-w-0">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <RouterLink :to="{ name: backTo.name }" class="text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue whitespace-nowrap">{{ backTo.label }}</RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700 truncate">Scan{{ packet ? ` — Packet ${packet.packet_code}` : '' }}</span>
      </div>
      <button
        type="button"
        class="h-8 shrink-0 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
        @click="back"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
        Back
      </button>
    </div>

    <p v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
    <div v-else-if="loadError" class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-[13px] text-brand">{{ loadError }}</div>

    <template v-else-if="packet">
      <!-- Section title bar -->
      <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-2 mb-1.5 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
          <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" />
              <line x1="7" y1="12" x2="17" y2="12" />
            </svg>
          </span>
          <h2 class="text-white text-[15px] font-medium truncate">Scan — Packet {{ packet.packet_code }}</h2>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <span
            class="inline-flex items-center rounded-[10px] text-[11px] sm:text-[12px] font-semibold px-3 py-1.5 whitespace-nowrap"
            :class="packet.is_scan_complete ? 'bg-white text-green-700' : 'bg-white/15 text-white'"
          >
            {{ packet.is_scan_complete ? 'Scan Complete' : 'Scan Pending' }}
          </span>
          <button
            v-if="!packet.is_scan_complete"
            type="button"
            class="h-8 inline-flex items-center gap-1.5 rounded-lg bg-white text-brand-blue text-[12px] font-semibold px-3.5 hover:bg-white/90 transition-colors disabled:opacity-60"
            :disabled="!!current || queue.length > 0"
            :title="current || queue.length ? 'Wait for the scans in progress to finish' : ''"
            @click="startComplete"
          >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
            Complete Scan
          </button>
        </div>
      </section>

      <!-- Packet details -->
      <!-- Two columns: the first 6 on the left, the other 5 on the right. -->
      <section class="bg-white rounded-[20px] shadow-card px-3 sm:px-4 py-3 mb-3 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1.5">
        <dl v-for="(column, c) in [details.slice(0, 6), details.slice(6)]" :key="c" class="space-y-1.5">
          <div v-for="item in column" :key="item.label" class="grid grid-cols-[120px_1fr] gap-3 min-w-0">
            <dt class="text-[12px] text-label">{{ item.label }}</dt>
            <dd class="text-[12.5px] font-semibold text-gray-900 leading-snug break-words">{{ item.value === 0 ? 0 : item.value || '—' }}</dd>
          </div>
        </dl>
      </section>

      <!-- Scan container -->
      <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4 grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4">
        <!-- Left: the scanner -->
        <div class="space-y-3 min-w-0">
          <h3 class="text-[14px] font-semibold text-gray-900">Scanner</h3>

          <!-- After Complete: scanning absent students' copies in late. -->
          <template v-if="packet.is_scan_complete">
            <p v-if="counts.absent" class="rounded-xl bg-amber-50 px-3 py-2.5 text-[12.5px] text-amber-800 leading-snug">
              This packet's scan is complete. Scan the <strong>absent</strong> students' copies here —
              {{ counts.absent }} pending. A scanned copy is no longer absent and can be assigned to a teacher.
            </p>
            <p v-else class="rounded-xl bg-green-50 px-3 py-2.5 text-[12.5px] text-green-700">This packet's scan is complete — no absent students' copies left to scan.</p>
          </template>

          <template v-if="!packet.is_scan_complete || counts.absent > 0 || current || queue.length">
            <!-- Scanner folder -->
            <div class="rounded-2xl border border-soft p-3 space-y-2">
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" :class="watching ? 'bg-green-500 animate-pulse' : 'bg-gray-300'"></span>
                <p class="text-[12.5px] font-semibold text-gray-900 truncate">
                  {{ watching ? `Watching “${folderName}”` : 'Scanner folder not connected' }}
                </p>
              </div>
              <p v-if="watching && lastCheck" class="text-[11.5px] text-muted">
                Checked {{ lastCheck.checkedAt.toLocaleTimeString() }} · {{ lastCheck.pdfCount }} PDF{{ lastCheck.pdfCount === 1 ? '' : 's' }} in folder
              </p>
              <!-- PDFs that were there before connecting aren't taken automatically. -->
              <div v-if="watching && existingFiles.length" class="rounded-xl bg-amber-50 px-3 py-2 text-[12px] text-amber-800">
                <p>{{ existingFiles.length }} PDF{{ existingFiles.length === 1 ? ' was' : 's were' }} already in this folder — not scanned in yet.</p>
                <div class="mt-1.5 flex gap-2">
                  <button type="button" class="h-7 inline-flex items-center rounded-lg bg-amber-600 text-white text-[11.5px] font-semibold px-3 hover:bg-amber-700 transition-colors" @click="scanExisting">
                    Scan {{ existingFiles.length === 1 ? 'It' : 'These' }} In
                  </button>
                  <button type="button" class="h-7 inline-flex items-center rounded-lg border border-amber-300 bg-white text-[11.5px] font-semibold text-amber-800 px-3 hover:bg-amber-100 transition-colors" @click="existingFiles = []">
                    Ignore
                  </button>
                </div>
              </div>
              <p class="text-[11.5px] text-muted leading-snug">
                Set the scanner software to save each scan as a PDF into one folder, connect that folder here, then scan the sheets one by one — each new scan is picked up automatically. Accepted scans are moved into its <span class="font-semibold">scanned_copy</span> folder, rejected ones into <span class="font-semibold">rejected_copy</span>.
              </p>
              <p v-if="!folderWatchSupported" class="text-[11.5px] text-amber-700">Watching a folder needs Chrome or Edge. Use Choose PDF below instead.</p>
              <div v-else class="flex flex-wrap gap-2">
                <button
                  v-if="watching"
                  type="button"
                  class="h-8 inline-flex items-center rounded-lg border border-input-border bg-white text-[12px] font-semibold text-gray-700 px-3 hover:border-brand hover:text-brand transition-colors"
                  @click="stopWatching"
                >
                  Stop Watching
                </button>
                <template v-else>
                  <button
                    v-if="rememberedFolder"
                    type="button"
                    class="h-8 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12px] font-semibold px-3.5 hover:opacity-90 transition-opacity"
                    @click="connectFolder(true)"
                  >
                    Reconnect “{{ rememberedFolder.name }}”
                  </button>
                  <button
                    type="button"
                    class="h-8 inline-flex items-center rounded-lg text-[12px] font-semibold px-3.5 transition-colors"
                    :class="rememberedFolder ? 'border border-input-border bg-white text-gray-700 hover:border-brand-blue hover:text-brand-blue' : 'bg-btn-gradient text-white hover:opacity-90'"
                    @click="connectFolder(false)"
                  >
                    {{ rememberedFolder ? 'Choose Another Folder' : 'Connect Scanner Folder' }}
                  </button>
                </template>
              </div>
            </div>

            <!-- Choose PDF / drop -->
            <div
              class="rounded-2xl border-2 border-dashed px-3 py-4 text-center transition-colors"
              :class="dragging ? 'border-brand-blue bg-soft' : 'border-input-border'"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="onDrop"
            >
              <p class="text-[12px] text-muted">Or drop scanned PDFs here</p>
              <button
                type="button"
                class="mt-2 h-8 inline-flex items-center rounded-lg border border-input-border bg-white text-[12px] font-semibold text-gray-700 px-3.5 hover:border-brand-blue hover:text-brand-blue transition-colors"
                @click="fileInput?.click()"
              >
                Choose PDF
              </button>
              <input ref="fileInput" type="file" accept="application/pdf,.pdf" multiple class="hidden" @change="onPick" />
            </div>

            <!-- Now -->
            <div v-if="current || queue.length || paused" class="rounded-xl bg-soft px-3 py-2.5 text-[12px]">
              <p v-if="current" class="font-semibold text-gray-900 truncate">{{ current.name }}</p>
              <p v-if="current" class="text-muted">{{ current.stage }}</p>
              <p v-if="paused" class="text-amber-700 font-medium">Waiting for you — deal with the open window to continue.</p>
              <p v-if="queue.length" class="text-muted">{{ queue.length }} more waiting</p>
            </div>
          </template>

          <!-- Activity -->
          <div>
            <p class="text-[11px] uppercase tracking-wide text-muted mb-1.5">Recent Scans</p>
            <p v-if="!activity.length" class="text-[12px] text-muted">Nothing scanned yet on this visit.</p>
            <ul v-else class="space-y-1 max-h-64 overflow-y-auto pr-1">
              <li v-for="item in activity" :key="item.key" class="flex items-start gap-2 text-[12px] leading-snug">
                <span
                  class="mt-1 w-2 h-2 rounded-full shrink-0"
                  :class="item.type === 'ok' ? 'bg-green-500' : item.type === 'warn' ? 'bg-amber-500' : 'bg-brand'"
                ></span>
                <span class="min-w-0">
                  <span class="text-gray-800">{{ item.text }}</span>
                  <span v-if="item.file" class="block text-[11px] text-muted truncate">{{ item.file }}</span>
                </span>
              </li>
            </ul>
          </div>
        </div>

        <!-- Right: the packet's sheets -->
        <div class="min-w-0 space-y-3">
          <!-- Progress -->
          <div>
            <div class="flex items-center justify-between text-[12px] mb-1">
              <span class="font-semibold text-gray-900">{{ counts.scanned }} of {{ counts.total }} scanned</span>
              <span class="text-muted">{{ progress }}%</span>
            </div>
            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full bg-btn-gradient transition-all" :style="{ width: `${progress}%` }"></div>
            </div>
          </div>

          <!-- Filters -->
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-for="tab in [
                { id: 'all', label: 'All', count: counts.total },
                { id: 'scanned', label: 'Scanned', count: counts.scanned },
                { id: 'review', label: 'Needs Review', count: counts.review },
                { id: 'not_scanned', label: 'Not Scanned', count: counts.notScanned },
              ]"
              :key="tab.id"
              type="button"
              class="h-8 inline-flex items-center gap-1.5 rounded-lg px-3 text-[12px] font-semibold transition-colors"
              :class="listFilter === tab.id ? 'bg-brand-blue text-white' : 'bg-soft text-gray-700 hover:text-brand-blue'"
              @click="listFilter = tab.id"
            >
              {{ tab.label }}
              <span class="rounded-full px-1.5 text-[11px]" :class="listFilter === tab.id ? 'bg-white/20' : tab.id === 'review' && tab.count ? 'bg-red-100 text-brand' : 'bg-white'">{{ tab.count }}</span>
            </button>
            <input
              v-model="listSearch"
              type="text"
              placeholder="Search QR, registration no, name…"
              class="ml-auto w-full sm:w-60 h-8 px-3 rounded-lg bg-input-bg text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue transition"
            />
          </div>

          <!-- Sheets -->
          <div class="rounded-2xl border border-soft overflow-hidden">
            <div class="max-h-[460px] overflow-y-auto">
              <table class="w-full text-left">
                <thead class="sticky top-0 z-10">
                  <tr class="bg-subject-header text-white text-[12px]">
                    <th class="px-3 py-1.5 font-medium">QR Code</th>
                    <th class="px-3 py-1.5 font-medium">Student</th>
                    <th class="px-3 py-1.5 font-medium">Status</th>
                    <th class="px-3 py-1.5 font-medium text-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!visibleSheets.length">
                    <td colspan="4" class="px-3 py-6 text-center text-[12.5px] text-muted">No sheets here.</td>
                  </tr>
                  <tr v-for="sheet in visibleSheets" :key="sheet.id" class="text-[12px] text-gray-800 border-t border-gray-100 even:bg-gray-50">
                    <td class="px-3 py-1.5 font-mono whitespace-nowrap">{{ sheet.qr_code }}</td>
                    <td class="px-3 py-1.5">
                      <p class="font-semibold leading-snug">{{ sheet.name || '—' }}</p>
                      <p class="text-[11px] text-muted leading-snug">{{ sheet.registration_no || '—' }}</p>
                    </td>
                    <td class="px-3 py-1.5">
                      <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap" :class="(STATUS[sheet.id_status] || STATUS.not_checked).class">
                        {{ sheet.absent ? 'Absent' : (STATUS[sheet.id_status] || STATUS.not_checked).label }}
                      </span>
                    </td>
                    <td class="px-3 py-1.5 text-right whitespace-nowrap">
                      <button
                        v-if="needsReview(sheet)"
                        type="button"
                        class="text-[12px] font-semibold text-brand hover:underline mr-3"
                        :disabled="paused"
                        @click="reviewSheet = sheet"
                      >
                        Review
                      </button>
                      <a
                        v-if="sheet.pdf_path"
                        :href="resolveStorageUrl(sheet.pdf_path)"
                        target="_blank"
                        rel="noopener"
                        class="text-[12px] font-semibold text-brand-blue hover:underline"
                      >
                        View
                      </a>
                      <span v-if="!sheet.pdf_path && !needsReview(sheet)" class="text-muted">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </template>

    <ScanProblemModal v-if="problem" :problem="problem" @close="problem = null" />
    <StudentIdReviewModal
      v-if="reviewSheet"
      :sheet="reviewSheet"
      :busy="reviewBusy"
      @close="reviewSheet = null"
      @confirm="confirmReview"
      @reject="rejectReview"
    />
    <ScanCompleteModal
      v-if="completeSummary"
      :summary="completeSummary"
      :busy="completing"
      @close="completeSummary = null"
      @confirm="confirmComplete"
    />
  </div>
</template>
