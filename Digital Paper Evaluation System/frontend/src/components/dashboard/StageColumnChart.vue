<script setup>
// Column chart of counts per stage — e.g. how many teachers / courses are at
// each stage. Used where a dashboard card would otherwise list names (Admin
// Teacher Workload, Teacher Recent Evaluation Activity): one column per
// stage stays readable however many rows there are. Each column has its
// count above it and its label below (never colour alone); hover / tap
// shows the stage's hint as a tooltip.
import { computed, ref } from 'vue'

const props = defineProps({
  // [{ key, label, hint, value, color? }] — hint finishes the sentence
  // "<value> <unit>(s) …" in the tooltip.
  stages: { type: Array, required: true },
  unit: { type: String, required: true },
  caption: { type: String, default: '' },
})

const DEFAULT_COLOR = '#2f56c0'
const max = computed(() => Math.max(1, ...props.stages.map((s) => s.value)))
const columns = computed(() => props.stages.map((s) => ({ ...s, heightPct: (s.value / max.value) * 100 })))
const hovered = ref(null)

function fmt(n) {
  return (n ?? 0).toLocaleString()
}
</script>

<template>
  <div>
    <div class="flex items-end gap-3 h-[132px] border-b border-gray-200 px-1" @mouseleave="hovered = null">
      <div
        v-for="(col, i) in columns"
        :key="col.key"
        class="relative flex-1 h-full flex flex-col items-center justify-end cursor-default"
        @mouseenter="hovered = i"
        @click="hovered = hovered === i ? null : i"
      >
        <span class="text-[12px] font-semibold text-gray-900 mb-1">{{ fmt(col.value) }}</span>
        <span
          class="w-full max-w-[44px] rounded-t transition-all"
          :class="hovered !== null && hovered !== i ? 'opacity-60' : ''"
          :style="{ height: `max(${col.heightPct * 0.8}%, ${col.value ? 3 : 0}px)`, background: col.color || DEFAULT_COLOR }"
        ></span>
        <div
          v-if="hovered === i"
          class="absolute z-20 bottom-full mb-1 w-44 rounded-lg bg-white border border-gray-200 shadow-lg px-3 py-2 text-[12px] text-gray-700 pointer-events-none"
          :class="i === 0 ? 'left-0' : i === columns.length - 1 ? 'right-0' : 'left-1/2 -translate-x-1/2'"
        >
          <p class="font-semibold text-gray-900">{{ col.label }}</p>
          <p><span class="font-semibold text-gray-900">{{ fmt(col.value) }}</span> {{ unit }}{{ col.value === 1 ? '' : 's' }} {{ col.hint }}</p>
        </div>
      </div>
    </div>
    <div class="flex gap-3 px-1 mt-1.5">
      <span v-for="col in columns" :key="col.key" class="flex-1 text-center text-[11px] text-gray-600 leading-tight">{{ col.label }}</span>
    </div>
    <p v-if="caption" class="text-[11px] text-muted text-center mt-1">{{ caption }}</p>
  </div>
</template>
