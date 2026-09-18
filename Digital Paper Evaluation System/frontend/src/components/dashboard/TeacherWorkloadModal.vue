<script setup>
// "See All" behind AdminDashboardView.vue's own Teacher Workload card
// (shown there only once teacher_workload_has_more is true) — GET
// /dashboard/teacher-workload, the same query as that card's own capped
// one (busiest first), just without the limit. Same table markup as the
// card itself, just given a full modal's worth of scroll room.
import { onMounted, ref } from 'vue'
import api from '../../utils/api'

const emit = defineEmits(['close'])

const rows = ref([])
const loading = ref(true)
const loadError = ref('')

async function fetchRows() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/dashboard/teacher-workload')
    rows.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load teacher workload.'
  } finally {
    loading.value = false
  }
}
onMounted(fetchRows)

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Teacher Workload</h2>
          <p class="text-[13px] text-muted mt-0.5">{{ rows.length }} teacher{{ rows.length === 1 ? '' : 's' }} with answer sheets assigned.</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>

        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[420px] text-left text-[12.5px]">
            <thead>
              <tr class="bg-subject-header text-white text-[11px] font-medium">
                <th class="px-2.5 py-2 rounded-tl-xl">#</th>
                <th class="px-2.5 py-2">Teacher Name</th>
                <th class="px-2.5 py-2 text-center">Assigned</th>
                <th class="px-2.5 py-2 text-center">Evaluated</th>
                <th class="px-2.5 py-2 text-center">Pending</th>
                <th class="px-2.5 py-2 text-center rounded-tr-xl">Issues</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="loading">
                <td colspan="6" class="px-2.5 py-10 text-center text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="!rows.length">
                <td colspan="6" class="px-2.5 py-10 text-center text-muted">No answer sheets assigned to any teacher yet.</td>
              </tr>
              <tr v-for="(row, i) in rows" v-else :key="row.name" class="text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0">
                <td class="px-2.5 py-2 text-gray-500">{{ i + 1 }}</td>
                <td :title="row.emp_code" class="px-2.5 py-2 font-medium whitespace-nowrap cursor-default">{{ row.name }}</td>
                <td class="px-2.5 py-2 text-center">{{ row.assigned }}</td>
                <td class="px-2.5 py-2 text-center">{{ row.evaluated }}</td>
                <td class="px-2.5 py-2 text-center">
                  <span class="inline-flex min-w-[28px] justify-center rounded-full bg-amber-50 text-badge font-semibold px-1.5 py-0.5">{{ row.pending }}</span>
                </td>
                <td class="px-2.5 py-2 text-center">
                  <span class="inline-flex min-w-[22px] justify-center rounded-full bg-rose-50 text-brand font-semibold px-1.5 py-0.5">{{ row.issues }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
