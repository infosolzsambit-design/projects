<script setup>
// "See All" behind AdminDashboardView.vue's own Department Wise Progress
// card (shown there only once department_progress_has_more is true) — GET
// /dashboard/department-progress, the same query as that card's own
// capped one, just without the limit. Same row markup as the card itself,
// just given a full modal's worth of room instead of a max-width cap on
// the bar.
import { onMounted, ref } from 'vue'
import api from '../../utils/api'

const emit = defineEmits(['close'])

const departments = ref([])
const loading = ref(true)
const loadError = ref('')

async function fetchDepartments() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/dashboard/department-progress')
    departments.value = res.data.data.map((d) => ({
      name: d.name,
      evaluated: d.evaluated_pct,
      pending: d.pending_pct,
      notAssigned: d.not_assigned_pct,
    }))
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load department progress.'
  } finally {
    loading.value = false
  }
}
onMounted(fetchDepartments)

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Department Wise Progress</h2>
          <p class="text-[13px] text-muted mt-0.5">{{ departments.length }} department{{ departments.length === 1 ? '' : 's' }} with answer sheets.</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-4">
        <p v-if="loading" class="text-[13px] text-muted text-center py-10">Loading&hellip;</p>
        <p v-else-if="loadError" class="text-[13px] text-brand text-center py-10">{{ loadError }}</p>
        <p v-else-if="!departments.length" class="text-[13px] text-muted text-center py-10">No department has any answer sheets yet.</p>

        <template v-else>
          <div class="flex flex-col gap-3">
            <div v-for="dept in departments" :key="dept.name" class="flex items-center gap-2.5">
              <span :title="dept.name" class="w-[170px] shrink-0 text-[12.5px] text-gray-700 truncate cursor-default">{{ dept.name }}</span>
              <div class="flex-1 h-2.5 rounded-full overflow-hidden bg-gray-100 flex">
                <span class="h-full bg-emerald-500" :style="{ width: dept.evaluated + '%' }"></span>
                <span class="h-full bg-amber-400" :style="{ width: dept.pending + '%' }"></span>
                <span class="h-full bg-gray-300" :style="{ width: dept.notAssigned + '%' }"></span>
              </div>
              <span class="w-9 shrink-0 text-right text-[12.5px] font-semibold text-gray-800">{{ dept.evaluated }}%</span>
            </div>
          </div>
          <div class="flex items-center gap-4 text-[11px] text-gray-600 mt-4 pt-3 border-t border-soft">
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>Evaluated</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>Pending</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-300"></span>Not Assigned</span>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
