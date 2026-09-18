<script setup>
// NotificationsView.vue's "Resolve" action for a row whose issue_name is
// Printing Issue (see that view's own is_printing_issue check — resolved
// by id server-side, never by matching the name string). A Printing
// Issue is about the physical sheet itself, so resolving one means
// replacing its scanned PDF — the new file's own QR code (decoded here
// client-side, same mechanism the original bulk-upload flow already
// trusts — see utils/qr.js) must match this exact row's subject_barcode
// (already shown in the table) before Submit unlocks, and the backend
// independently re-checks that same match (see NotificationController::
// resolvePrintingIssue()) rather than trusting this client-side gate
// alone.
import { ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'
import { extractQrFromPdfFirstPage } from '../../utils/qr'

const props = defineProps({
  notification: { type: Object, required: true },
})
const emit = defineEmits(['close', 'resolved'])
const toast = useToast()

const selectedFile = ref(null)
const decodedQr = ref(null) // string | null, once a file's been read
const checkingQr = ref(false)
const adminRemarks = ref('')
const submitting = ref(false)
// Set once the resolve actually succeeds — the modal then shows a
// "Resolved" confirmation right after the QR check area instead of just
// vanishing, so the admin can see the outcome before dismissing it
// themselves (see close() below).
const resolved = ref(false)

const qrMatches = () => !!decodedQr.value && decodedQr.value === props.notification.subject_barcode

// Same H:MM:SS/MM:SS convention as EvaluatePaperView.vue's own timer badges.
function formatConsumedTime(totalSeconds) {
  if (totalSeconds === null || totalSeconds === undefined) return '—'
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const s = Math.floor(totalSeconds % 60)
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

async function onFileChange(event) {
  const file = event.target.files?.[0] || null
  selectedFile.value = file
  decodedQr.value = null
  if (!file) return

  checkingQr.value = true
  try {
    decodedQr.value = await extractQrFromPdfFirstPage(file)
    if (!decodedQr.value) {
      toast.error("Could not find a QR code on this PDF's first page.")
    } else if (decodedQr.value !== props.notification.subject_barcode) {
      toast.error(`This PDF's QR code (${decodedQr.value}) does not match this sheet's barcode (${props.notification.subject_barcode}).`)
    }
  } catch {
    toast.error('Could not read this PDF file.')
  } finally {
    checkingQr.value = false
  }
}

function close() {
  if (submitting.value) return
  emit('close')
}

async function submit() {
  if (!selectedFile.value || !qrMatches()) {
    toast.error("Please upload a PDF whose QR code matches this sheet's barcode.")
    return
  }

  const payload = new FormData()
  payload.append('pdf', selectedFile.value)
  payload.append('qr_code', decodedQr.value)
  if (adminRemarks.value.trim()) payload.append('remarks', adminRemarks.value.trim())

  submitting.value = true
  try {
    const res = await api.post(`/notifications/${props.notification.answer_sheet_id}/resolve-printing-issue`, payload)
    toast.success(res.data.message || 'Printing issue resolved successfully.')
    resolved.value = true
    emit('resolved')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not resolve this issue.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-md px-6 py-7">
      <div class="flex items-start justify-between gap-3 mb-4">
        <h2 class="text-lg font-bold text-black">Resolve Printing Issue</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="submitting" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="rounded-xl bg-input-bg p-3.5 mb-4">
        <p class="text-[11px] text-label mb-1">Teacher's Remarks</p>
        <p class="text-[13px] text-gray-800">{{ notification.remarks || '—' }}</p>
      </div>

      <div class="grid grid-cols-2 gap-2 mb-4">
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Expected Barcode</p>
          <p class="text-[13px] font-semibold text-gray-900">{{ notification.subject_barcode || '—' }}</p>
        </div>
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Consumed Time</p>
          <!-- Almost always "—" here — raising a Printing Issue resets
               consumed_time (see MyPendingCourseController::raiseIssue()),
               unlike a Timing Issue, which preserves it. Shown anyway for
               consistency with that modal. -->
          <p class="text-[13px] font-semibold text-gray-900">{{ formatConsumedTime(notification.consumed_time) }}</p>
        </div>
      </div>

      <div class="flex flex-col gap-1.5 mb-2">
        <label class="text-[12px] text-label">Replacement PDF <span class="text-brand">*</span></label>
        <input
          type="file"
          accept="application/pdf"
          :disabled="resolved"
          class="w-full text-sm text-gray-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-soft file:text-brand-blue hover:file:bg-brand-blue/10 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
          @change="onFileChange"
        />
      </div>

      <p v-if="checkingQr" class="text-[12px] text-muted mb-4">Reading QR code&hellip;</p>
      <div v-else-if="selectedFile" class="rounded-lg p-2.5 mb-4 flex items-center gap-2" :class="qrMatches() ? 'bg-success/10' : 'bg-brand/10'">
        <svg v-if="qrMatches()" class="w-4 h-4 text-success shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
        <svg v-else class="w-4 h-4 text-brand shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        <p class="text-[12px]" :class="qrMatches() ? 'text-success' : 'text-brand'">
          {{ decodedQr ? `QR: ${decodedQr}` : 'No QR code found on this PDF.' }}
        </p>
      </div>

      <div v-if="resolved" class="rounded-lg bg-success/10 p-2.5 mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 text-success shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M9 12l2 2 4-4" /></svg>
        <p class="text-[12.5px] font-semibold text-success">Resolved — the PDF has been replaced.</p>
      </div>

      <div class="flex flex-col gap-1.5 mb-5">
        <label for="printing_admin_remarks" class="text-[12px] text-label">Admin Remarks</label>
        <textarea
          id="printing_admin_remarks"
          v-model="adminRemarks"
          rows="3"
          :disabled="resolved"
          placeholder="Optional note about this resolution…"
          class="w-full px-3 py-2.5 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition resize-none disabled:opacity-60"
        ></textarea>
      </div>

      <div class="flex justify-end gap-2">
        <button
          v-if="resolved"
          type="button"
          class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity"
          @click="close"
        >
          Close
        </button>
        <template v-else>
          <button
            type="button"
            class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="submitting"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="submitting || !qrMatches()"
            @click="submit"
          >
            {{ submitting ? 'Resolving…' : 'Resolve' }}
          </button>
        </template>
      </div>
    </div>
  </div>
</template>
