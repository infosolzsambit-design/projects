<script setup>
// "See All" behind TeacherDashboardView.vue's Course Wise Pending
// Evaluation card (shown only when course_pending_has_more) — GET
// /dashboard/teacher-course-pending: the same query as the card's capped
// one (most pending first), without the limit. Same look as the admin
// dashboard's See All modals.
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
    const res = await api.get('/dashboard/teacher-course-pending', { params })
    rows.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load course wise pending evaluation.'
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
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Course Wise Pending Evaluation</h2>
          <p class="text-[13px] text-muted mt-0.5">{{ rows.length }} course{{ rows.length === 1 ? '' : 's' }} with pending sheets.</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>
        <table v-else class="w-full text-left text-[12.5px]">
          <thead>
            <tr class="bg-subject-header text-white text-[11px] font-medium">
              <th class="px-2.5 py-2 rounded-tl-xl w-px whitespace-nowrap">#</th>
              <th class="px-2.5 py-2">Course</th>
              <th class="px-2.5 py-2 text-center rounded-tr-xl w-px whitespace-nowrap">Pending</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="3" class="px-2.5 py-10 text-center text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="3" class="px-2.5 py-10 text-center text-muted">Nothing pending — every assigned sheet is evaluated.</td>
            </tr>
            <tr v-for="(row, i) in rows" v-else :key="row.name" class="text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0">
              <td class="px-2.5 py-2 text-gray-500">{{ i + 1 }}</td>
              <td class="px-2.5 py-2 font-medium">{{ row.name }}</td>
              <td class="px-2.5 py-2 text-center">
                <span class="inline-flex min-w-[28px] justify-center rounded-full bg-amber-50 text-badge font-semibold px-1.5 py-0.5">{{ row.pending }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
