<script setup>
// "See All" behind TeacherDashboardView.vue's Recent Evaluation Activity
// card (shown only when recent_has_more) — GET /dashboard/teacher-recent:
// every course with its evaluation window and pending count, most recently
// active first. Same look as the admin dashboard's See All modals.
import { onMounted, ref } from 'vue'
import api from '../../utils/api'
import { useExamYearStore } from '../../stores/examYear'
import { useExamTypeStore } from '../../stores/examType'

const emit = defineEmits(['close'])
const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const rows = ref([])
const loading = ref(true)
const loadError = ref('')

async function fetchRows() {
  loading.value = true
  loadError.value = ''
  try {
    const params = { exam_year: examYearStore.selectedYear }
    if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    const res = await api.get('/dashboard/teacher-recent', { params })
    rows.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load recent evaluation activity.'
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
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Recent Evaluation Activity</h2>
          <p class="text-[13px] text-muted mt-0.5">{{ rows.length }} course{{ rows.length === 1 ? '' : 's' }} with their evaluation window.</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[560px] text-left text-[12.5px]">
            <thead>
              <tr class="bg-subject-header text-white text-[11px] font-medium">
                <th class="px-2.5 py-2 rounded-tl-xl w-px whitespace-nowrap">#</th>
                <th class="px-2.5 py-2">Course</th>
                <th class="px-2.5 py-2 whitespace-nowrap">Start Date</th>
                <th class="px-2.5 py-2 whitespace-nowrap">End Date</th>
                <th class="px-2.5 py-2 text-center">Status</th>
                <th class="px-2.5 py-2 text-center rounded-tr-xl w-px whitespace-nowrap">Pending</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="loading">
                <td colspan="6" class="px-2.5 py-10 text-center text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="!rows.length">
                <td colspan="6" class="px-2.5 py-10 text-center text-muted">No answer sheets assigned to you yet.</td>
              </tr>
              <tr v-for="(row, i) in rows" v-else :key="row.course" class="text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0">
                <td class="px-2.5 py-2 text-gray-500">{{ i + 1 }}</td>
                <td class="px-2.5 py-2 font-medium">{{ row.course }}</td>
                <td class="px-2.5 py-2 whitespace-nowrap">{{ row.start || '—' }}</td>
                <td class="px-2.5 py-2 whitespace-nowrap">{{ row.end || '—' }}</td>
                <td class="px-2.5 py-2 text-center whitespace-nowrap">
                  <span v-if="row.pending > 0" class="inline-flex justify-center rounded-full bg-amber-50 text-badge font-semibold px-2 py-0.5">Pending</span>
                  <span v-else class="inline-flex justify-center rounded-full bg-emerald-50 text-success font-semibold px-2 py-0.5">Completed</span>
                </td>
                <td class="px-2.5 py-2 text-center">
                  <span v-if="row.pending > 0" class="inline-flex min-w-[28px] justify-center rounded-full bg-amber-50 text-badge font-semibold px-1.5 py-0.5">{{ row.pending }}</span>
                  <span v-else class="text-gray-400">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
