<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

// Programs list → Courses column's eye icon: every course mapped to one
// program, with its code and type. Filterable, since a program can have a
// long course list.
const props = defineProps({
  program: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const search = ref('')
const courses = computed(() => {
  const all = [...(props.program.courses || [])].sort((a, b) => a.name.localeCompare(b.name))
  const q = search.value.trim().toLowerCase()
  if (!q) return all
  return all.filter((c) => [c.name, c.code, c.type].some((v) => String(v ?? '').toLowerCase().includes(q)))
})
const total = computed(() => (props.program.courses || []).length)

function onKey(event) {
  if (event.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
    <div class="w-full max-w-[640px] max-h-[85vh] flex flex-col rounded-[24px] bg-white shadow-panel overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="program-courses-title">
      <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-soft">
        <div class="min-w-0">
          <h2 id="program-courses-title" class="text-[18px] font-semibold text-gray-900">Mapped Courses</h2>
          <p class="text-[12px] text-muted mt-0.5 truncate">
            {{ program.name }}<template v-if="program.label"> ({{ program.label }})</template>
            <template v-if="program.code"> · {{ program.code }}</template>
            · {{ total }} course{{ total === 1 ? '' : 's' }}
          </p>
        </div>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="emit('close')">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div v-if="total > 6" class="px-5 pt-3">
        <input
          v-model="search"
          type="text"
          placeholder="Search by name, code or type…"
          class="w-full h-9 px-3 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
        />
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-3">
        <table class="w-full text-left border-separate border-spacing-0">
          <thead class="sticky top-0">
            <tr class="bg-subject-header text-white text-[11px] font-medium">
              <th class="px-3 py-2 rounded-tl-xl w-px whitespace-nowrap">#</th>
              <th class="px-3 py-2">Course Name</th>
              <th class="px-3 py-2">Code</th>
              <th class="px-3 py-2 rounded-tr-xl">Type</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!courses.length">
              <td colspan="4" class="px-3 py-6 text-center text-[13px] text-muted">{{ total ? 'No courses match your search.' : 'No courses mapped to this program.' }}</td>
            </tr>
            <tr v-for="(course, i) in courses" :key="course.id" class="text-[12.5px] text-gray-800 even:bg-gray-50">
              <td class="px-3 py-2 text-muted border-b border-gray-100">{{ i + 1 }}</td>
              <td class="px-3 py-2 font-medium border-b border-gray-100">{{ course.name }}</td>
              <td class="px-3 py-2 whitespace-nowrap border-b border-gray-100">{{ course.code || '—' }}</td>
              <td class="px-3 py-2 border-b border-gray-100">
                <span v-if="course.type" class="inline-flex items-center rounded-md bg-soft text-brand-blue px-2 py-0.5 text-[11px] font-semibold">{{ course.type }}</span>
                <span v-else class="text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
