<script setup>
// ScanPacketView.vue → Complete: confirms finishing the packet's scan. Any
// sheet not scanned by then is marked absent; the packet then moves to
// Answer Sheets (is_scan_complete = 1) and can be assigned to teachers.
import { onBeforeUnmount, onMounted } from 'vue'

defineProps({
  summary: { type: Object, required: true }, // { total, scanned, unscanned }
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'confirm'])

function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="!busy && emit('close')">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-md flex flex-col overflow-hidden" role="dialog" aria-labelledby="scan-complete-title">
      <div class="px-6 pt-6 pb-4">
        <h2 id="scan-complete-title" class="text-lg font-bold text-black">Complete Scan</h2>
        <p class="text-[13px] text-gray-700 mt-1">
          <template v-if="summary.unscanned">
            <strong class="text-brand">{{ summary.unscanned }}</strong> of {{ summary.total }} answer sheet{{ summary.total === 1 ? '' : 's' }} {{ summary.unscanned === 1 ? 'is' : 'are' }} not scanned.
            If you proceed, {{ summary.unscanned === 1 ? 'it' : 'they' }} will be marked <strong>absent</strong>.
          </template>
          <template v-else>All {{ summary.total }} answer sheets are scanned.</template>
        </p>
      </div>

      <div class="px-6 pb-4 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-soft py-2.5">
          <p class="text-[18px] font-bold text-gray-900 leading-none">{{ summary.total }}</p>
          <p class="mt-1 text-[11px] text-muted">Total</p>
        </div>
        <div class="rounded-xl bg-green-50 py-2.5">
          <p class="text-[18px] font-bold text-green-700 leading-none">{{ summary.scanned }}</p>
          <p class="mt-1 text-[11px] text-muted">Scanned</p>
        </div>
        <div class="rounded-xl py-2.5" :class="summary.unscanned ? 'bg-red-50' : 'bg-soft'">
          <p class="text-[18px] font-bold leading-none" :class="summary.unscanned ? 'text-brand' : 'text-gray-900'">{{ summary.unscanned }}</p>
          <p class="mt-1 text-[11px] text-muted">Will be Absent</p>
        </div>
      </div>

      <p class="px-6 pb-4 text-[12px] text-muted">After completing, this packet moves to Answer Sheets and can be assigned to teachers. It can't be scanned any more.</p>

      <div class="flex justify-end gap-2 px-6 py-4 border-t border-soft">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
          :disabled="busy"
          @click="emit('close')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60"
          :disabled="busy"
          @click="emit('confirm')"
        >
          {{ busy ? 'Completing…' : summary.unscanned ? 'Proceed — Mark Absent & Complete' : 'Complete' }}
        </button>
      </div>
    </div>
  </div>
</template>
