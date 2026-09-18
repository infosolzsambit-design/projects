<script setup>
import { onMounted, ref } from 'vue'
import api from '../../utils/api'

// Opened by clicking a teacher's "Courses" count in AssignedTeachersView.vue
// — GET /teachers/{id}/courses, one row per *distinct* course this teacher
// has any answer sheets in (summed across however many packets/exam terms
// that course spans), not one row per packet. Deliberately the simpler
// counterpart to TeacherAllocationModal.vue's own packet-level breakdown —
// same layout/styling as that modal (see its own docblock), just a shorter
// table with only what "which courses" actually needs.
const props = defineProps({
  teacher: { type: Object, required: true }, // { id, name } — enough to title the modal
})
const emit = defineEmits(['close'])

const courses = ref([])
const loading = ref(true)
const loadError = ref('')

async function fetchCourses() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/teachers/${props.teacher.id}/courses`)
    courses.value = res.data.data.courses
  } catch (err) {
    loadError.value = err.response?.data?.message || "Could not load this teacher's courses."
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}

onMounted(fetchCourses)
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Courses — {{ teacher.name }}</h2>
          <p class="text-[13px] text-muted mt-0.5">
            {{ courses.length }} course{{ courses.length === 1 ? '' : 's' }} with answer sheets allocated to this teacher.
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
            <table class="w-full min-w-[560px] text-left border-separate border-spacing-0">
              <thead>
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-2.5 py-2 rounded-tl-xl">Course</th>
                  <th class="px-2.5 py-2">Exam Type</th>
                  <th class="px-2.5 py-2 text-center">Sheets</th>
                  <th class="px-2.5 py-2 text-center rounded-tr-xl">Completed</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="loading">
                  <td colspan="4" class="px-2.5 py-10 text-center text-[13px] text-muted">Loading&hellip;</td>
                </tr>
                <tr v-else-if="!courses.length">
                  <td colspan="4" class="px-2.5 py-10 text-center text-[13px] text-muted">No answer sheets allocated to this teacher yet.</td>
                </tr>
                <tr
                  v-for="row in courses"
                  v-else
                  :key="row.course_id"
                  class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top"
                >
                  <td class="px-2.5 py-2.5">
                    {{ row.course_name || '—' }}
                    <span v-if="row.course_code" class="text-muted">({{ row.course_code }})</span>
                  </td>
                  <td class="px-2.5 py-2.5">{{ row.exam_type_names || '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center font-semibold">{{ row.sheet_count }}</td>
                  <td class="px-2.5 py-2.5 text-center text-success font-semibold">{{ row.completed_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
