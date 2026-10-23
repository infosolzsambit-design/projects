<script setup>
// ScanPacketView.vue: a scan that wasn't saved — no QR code could be read,
// the QR code isn't one of ours, or it belongs to another packet (then
// which one, and whose sheet it is). Scanning waits until this is closed.
import { onBeforeUnmount, onMounted } from 'vue'
import { ordinal } from '../../utils/ordinal'

const props = defineProps({
  // { reason: 'no_qr'|'unknown'|'other_packet'|'already_scanned'|'error', message, file_name, qr_code?, other_packet?, student? }
  problem: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const TITLES = {
  no_qr: 'No QR Code Found',
  unknown: 'QR Code Not Valid',
  other_packet: 'QR Code Not In This Packet',
  already_scanned: 'Already Scanned',
  error: 'Scan Not Saved',
}

function onKeydown(event) {
  if (event.key === 'Escape' || event.key === 'Enter') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))

const other = props.problem.other_packet
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-md flex flex-col overflow-hidden" role="alertdialog" aria-labelledby="scan-problem-title">
      <div class="flex items-start gap-3 px-6 pt-6 pb-4">
        <span class="w-10 h-10 rounded-full bg-red-50 text-brand flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
        </span>
        <div class="min-w-0">
          <h2 id="scan-problem-title" class="text-lg font-bold text-black">{{ TITLES[problem.reason] || TITLES.error }}</h2>
          <p class="text-[13px] text-gray-700 mt-1">{{ problem.message }}</p>
        </div>
      </div>

      <div class="px-6 pb-4 space-y-3">
        <dl class="rounded-2xl border border-soft divide-y divide-soft text-[12.5px]">
          <div v-if="problem.qr_code" class="grid grid-cols-[120px_1fr] gap-3 px-4 py-2">
            <dt class="text-muted">QR Code</dt>
            <dd class="font-semibold text-gray-900">{{ problem.qr_code }}</dd>
          </div>
          <div class="grid grid-cols-[120px_1fr] gap-3 px-4 py-2">
            <dt class="text-muted">Scanned File</dt>
            <dd class="text-gray-800 break-all">{{ problem.file_name || '—' }}</dd>
          </div>
        </dl>

        <!-- Which packet it does belong to -->
        <div v-if="other" class="rounded-2xl bg-soft px-4 py-3 text-[12.5px]">
          <p class="font-semibold text-gray-900 mb-1.5">It belongs to packet {{ other.packet_code }}</p>
          <dl class="grid grid-cols-[110px_1fr] gap-x-3 gap-y-1">
            <dt class="text-muted">Program</dt><dd class="text-gray-800">{{ other.program_name || '—' }}</dd>
            <dt class="text-muted">Course</dt><dd class="text-gray-800">{{ other.course_name || '—' }}<span v-if="other.course_code"> ({{ other.course_code }})</span></dd>
            <dt class="text-muted">Semester</dt><dd class="text-gray-800">{{ other.semester ? `${ordinal(other.semester)} Semester` : '—' }}</dd>
            <dt class="text-muted">Department</dt><dd class="text-gray-800">{{ other.department_name || '—' }}</dd>
            <dt class="text-muted">Batch</dt><dd class="text-gray-800">{{ other.batch || '—' }}</dd>
            <template v-if="problem.student">
              <dt class="text-muted">Student</dt><dd class="text-gray-800">{{ problem.student.name || '—' }} ({{ problem.student.registration_no || '—' }})</dd>
            </template>
          </dl>
        </div>

        <p class="text-[12px] text-muted">
          Nothing was saved for this scan.
          <template v-if="problem.reason === 'already_scanned'">This student's sheet was scanned before the packet was completed — only absent students' copies can be scanned now.</template>
          <template v-else>Put this sheet aside{{ other ? ' for its own packet' : ', check it, and scan it again' }}.</template>
        </p>
      </div>

      <div class="flex justify-end px-6 py-4 border-t border-soft">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-6 hover:opacity-90 transition-opacity"
          @click="emit('close')"
        >
          OK
        </button>
      </div>
    </div>
  </div>
</template>
