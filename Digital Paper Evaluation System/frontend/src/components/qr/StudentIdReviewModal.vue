<script setup>
// ScanPacketView.vue: a scanned sheet whose handwritten STUDENT'S ID NO.
// didn't match its registration number (or couldn't be read). Shows what was
// read — and the cropped ID boxes — next to the student the QR code belongs
// to, for a person to decide: Mark as Correct (it IS this student) or Reject
// Scan (removed, scan the sheet again). Scanning waits until this is closed.
import { onBeforeUnmount, onMounted } from 'vue'
import { resolveStorageUrl } from '../../utils/api'

defineProps({
  // ScanPacketView sheet: { id, qr_code, registration_no, name, id_status, student_id_read, student_id_crop_path, pdf_path }
  sheet: { type: Object, required: true },
  busy: { type: String, default: '' }, // '' | 'confirm' | 'reject'
})
const emit = defineEmits(['close', 'confirm', 'reject'])

const REASONS = {
  mismatch: 'The student ID written on the sheet does not match this student\'s registration number.',
  unreadable: 'The student ID written on the sheet could not be read.',
  error: 'The student ID could not be checked automatically.',
  not_checked: 'The student ID has not been checked yet.',
}

function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="!busy && emit('close')">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden" role="dialog" aria-labelledby="id-review-title">
      <div class="flex items-start justify-between gap-3 px-6 pt-6 pb-4 border-b border-soft">
        <div class="min-w-0">
          <h2 id="id-review-title" class="text-lg font-bold text-black">Check Student ID</h2>
          <p class="text-[13px] text-gray-700 mt-0.5">{{ REASONS[sheet.id_status] || REASONS.mismatch }}</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="!!busy" @click="emit('close')">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
        <!-- What's written on the sheet -->
        <div>
          <p class="text-[11px] uppercase tracking-wide text-muted mb-1.5">Written on the answer sheet</p>
          <div class="rounded-2xl border border-soft p-3 space-y-2">
            <img
              v-if="sheet.student_id_crop_path"
              :src="resolveStorageUrl(sheet.student_id_crop_path)"
              alt="Student ID boxes from the scanned sheet"
              class="w-full max-h-28 object-contain bg-gray-50 rounded-lg"
            />
            <p class="text-[12.5px]">
              <span class="text-muted">Read as:</span>
              <span class="ml-1 font-mono text-[14px] font-bold" :class="sheet.student_id_read ? 'text-brand' : 'text-muted'">{{ sheet.student_id_read || 'Could not be read' }}</span>
            </p>
          </div>
        </div>

        <!-- Who the QR code belongs to -->
        <div>
          <p class="text-[11px] uppercase tracking-wide text-muted mb-1.5">Student for this QR code</p>
          <dl class="rounded-2xl bg-soft px-4 py-3 grid grid-cols-[130px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
            <dt class="text-muted">Registration No</dt><dd class="font-mono text-[14px] font-bold text-green-700">{{ sheet.registration_no || '—' }}</dd>
            <dt class="text-muted">Name</dt><dd class="font-semibold text-gray-900">{{ sheet.name || '—' }}</dd>
            <dt class="text-muted">QR Code</dt><dd class="text-gray-800">{{ sheet.qr_code }}</dd>
          </dl>
        </div>

        <a
          v-if="sheet.pdf_path"
          :href="resolveStorageUrl(sheet.pdf_path)"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-brand-blue hover:underline"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
          Open the scanned sheet
        </a>
      </div>

      <div class="flex flex-wrap justify-end gap-2 px-6 py-4 border-t border-soft">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
          :disabled="!!busy"
          @click="emit('close')"
        >
          Decide Later
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-red-200 bg-white text-[12.5px] font-semibold text-brand px-4 hover:bg-red-50 transition-colors disabled:opacity-60"
          :disabled="!!busy"
          @click="emit('reject')"
        >
          {{ busy === 'reject' ? 'Removing…' : 'Reject Scan' }}
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60"
          :disabled="!!busy"
          @click="emit('confirm')"
        >
          {{ busy === 'confirm' ? 'Saving…' : 'Mark as Correct' }}
        </button>
      </div>
    </div>
  </div>
</template>
