<script setup>
import { onMounted, ref } from 'vue'
import api from '../../utils/api'

// Opened by clicking a teacher's "Already Allocated" number in
// AssignTeacherView.vue / AssignedTeachersView.vue's tables — GET
// /teachers/{id}/assignments, one row per packet
// (question_answer_sheet_mapping) this teacher has any sheets in, not one
// row per sheet (see TeacherController::assignments()'s own docblock on
// the backend). Pure read-only display — no action menu, no reassign
// here. Reassigning a teacher's work lives on AssignedTeachersView.vue's
// own row action menu instead (see TeacherReassignModal.vue), reached
// deliberately, not as a side door off this summary view.
const props = defineProps({
  teacher: { type: Object, required: true }, // { id, name } — enough to title the modal
})
const emit = defineEmits(['close'])

const breakdown = ref([])
const total = ref(0)
const loading = ref(true)
const loadError = ref('')

async function fetchAssignments() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/teachers/${props.teacher.id}/assignments`)
    breakdown.value = res.data.data.breakdown
    total.value = res.data.data.total
  } catch (err) {
    loadError.value = err.response?.data?.message || "Could not load this teacher's allocations."
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}

onMounted(fetchAssignments)
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-6xl max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Allocated Answer Sheets — {{ teacher.name }}</h2>
          <p class="text-[13px] text-muted mt-0.5">
            {{ total }} answer sheet{{ total === 1 ? '' : 's' }} allocated across {{ breakdown.length }} course{{ breakdown.length === 1 ? '' : 's' }}.
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>

        <template v-else>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[920px] text-left border-separate border-spacing-0">
              <thead>
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-2.5 py-2 rounded-tl-xl">Program</th>
                  <th class="px-2.5 py-2">Course</th>
                  <th class="px-2.5 py-2">Exam Term</th>
                  <th class="px-2.5 py-2">Exam Type</th>
                  <th class="px-2.5 py-2 text-center">Semester</th>
                  <th class="px-2.5 py-2 text-center">Exam Year</th>
                  <th class="px-2.5 py-2">Packet Code</th>
                  <th class="px-2.5 py-2 text-center">Sheets</th>
                  <th class="px-2.5 py-2 text-center">Completed</th>
                  <th class="px-2.5 py-2 text-center rounded-tr-xl">In Draft</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="loading">
                  <td colspan="10" class="px-2.5 py-10 text-center text-[13px] text-muted">Loading&hellip;</td>
                </tr>
                <tr v-else-if="!breakdown.length">
                  <td colspan="10" class="px-2.5 py-10 text-center text-[13px] text-muted">No answer sheets allocated to this teacher yet.</td>
                </tr>
                <tr
                  v-for="row in breakdown"
                  v-else
                  :key="row.mapping_id"
                  class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top"
                >
                  <td class="px-2.5 py-2.5">{{ row.program_name || '—' }}</td>
                  <td class="px-2.5 py-2.5">
                    {{ row.course_name || '—' }}
                    <span v-if="row.course_code" class="text-muted">({{ row.course_code }})</span>
                  </td>
                  <td class="px-2.5 py-2.5">{{ row.exam_term_name || '—' }}</td>
                  <td class="px-2.5 py-2.5">{{ row.exam_type_name || '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center">{{ row.semester ?? '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center">{{ row.exam_year ?? '—' }}</td>
                  <td class="px-2.5 py-2.5 font-medium">{{ row.packet_code || '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center font-semibold">{{ row.sheet_count }}</td>
                  <td class="px-2.5 py-2.5 text-center text-success font-semibold">{{ row.completed_count }}</td>
                  <td class="px-2.5 py-2.5 text-center text-brand font-semibold">{{ row.draft_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
