<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { useProgramsStore } from '../../stores/programs'
import { courseLabel } from '../../utils/course'
import { formatDateTime } from '../../utils/date'
import { ordinal } from '../../utils/ordinal'

// Program names display as "Name (Label)" — see stores/programs.js.
const programsStore = useProgramsStore()
programsStore.load().catch(() => {})

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
  // Opened from the Pending count instead: same table, only the packets
  // that still have pending sheets, totals counted in pending sheets.
  pendingOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const breakdown = ref([])
const total = ref(0)
// Sheets waiting (nobody has started them) in shared pools this teacher is in.
const poolWaitingTotal = ref(0)
const loading = ref(true)
const loadError = ref('')

async function fetchAssignments() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/teachers/${props.teacher.id}/assignments`)
    breakdown.value = res.data.data.breakdown
    total.value = res.data.data.total
    poolWaitingTotal.value = res.data.data.pool_waiting_total ?? 0
  } catch (err) {
    loadError.value = err.response?.data?.message || "Could not load this teacher's allocations."
  } finally {
    loading.value = false
  }
}

const rows = computed(() => (props.pendingOnly ? breakdown.value.filter((row) => (row.pending_count ?? 0) > 0) : breakdown.value))
const sheetTotal = computed(() => (props.pendingOnly ? rows.value.reduce((sum, row) => sum + (row.pending_count ?? 0), 0) : total.value))

function close() {
  emit('close')
}

onMounted(fetchAssignments)
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-[1400px] max-h-[85vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">{{ pendingOnly ? 'Pending' : 'Allocated' }} Answer Sheets — {{ teacher.name }}</h2>
          <p class="text-[13px] text-muted mt-0.5">
            {{ sheetTotal }} {{ pendingOnly ? 'pending ' : '' }}answer sheet{{ sheetTotal === 1 ? '' : 's' }} {{ pendingOnly ? '' : 'allocated ' }}across {{ rows.length }} course{{ rows.length === 1 ? '' : 's' }}<template v-if="!pendingOnly && poolWaitingTotal">, plus <span class="font-semibold text-cyan-700">{{ poolWaitingTotal }}</span> waiting in shared pools</template>.
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
            <table class="w-full min-w-[1200px] text-left border-separate border-spacing-0">
              <thead>
                <tr class="bg-subject-header text-white text-[11px] font-medium whitespace-nowrap">
                  <th class="px-2.5 py-2 rounded-tl-xl">Program / Course</th>
                  <th class="px-2.5 py-2">Department</th>
                  <th class="px-2.5 py-2">Exam Term / Type</th>
                  <th class="px-2.5 py-2 text-center">Sem</th>
                  <!-- <th class="px-2.5 py-2 text-center">Exam Year</th> -->
                  <th class="px-2.5 py-2">Pkt. Code</th>
                  <th class="px-2.5 py-2 whitespace-nowrap">Assigned By / At</th>
                  <th class="px-2.5 py-2">Sheets</th>
                  <th class="px-2.5 py-2 rounded-tr-xl whitespace-nowrap">Pending / Problem</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="loading">
                  <td colspan="8" class="px-2.5 py-10 text-center text-[13px] text-muted">Loading&hellip;</td>
                </tr>
                <tr v-else-if="!rows.length">
                  <td colspan="8" class="px-2.5 py-10 text-center text-[13px] text-muted">
                    {{ pendingOnly ? 'No pending answer sheets for this teacher.' : 'No answer sheets allocated to this teacher yet.' }}
                  </td>
                </tr>
                <tr
                  v-for="row in rows"
                  v-else
                  :key="row.mapping_id"
                  class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top"
                >
                  <!-- Program and Course — labelled, labels and values lined up. -->
                  <td class="px-2.5 py-2.5 min-w-[320px]">
                    <div class="grid grid-cols-[auto_auto_minmax(0,1fr)] gap-x-1.5 gap-y-0.5 leading-snug">
                      <span class="text-muted">Prog.</span><span class="text-muted">:</span>
                      <span class="font-medium text-gray-900">{{ programsStore.display(row.program_name) || '—' }}</span>
                      <span class="text-muted">Course</span><span class="text-muted">:</span>
                      <span>{{ row.course_name ? courseLabel(row.course_name, row.course_code, row.course_type) : '—' }}</span>
                    </div>
                  </td>
                  <!-- The packet's own department (chosen on Answer Sheet Upload). -->
                  <td class="px-2.5 py-2.5 min-w-[220px]">{{ row.department_name || '—' }}</td>
                  <!-- Exam Term with the Exam Type below it. -->
                  <td class="px-2.5 py-2.5 whitespace-nowrap">
                    <p class="leading-snug">{{ row.exam_term_name || '—' }}</p>
                    <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ row.exam_type_name || '—' }}</p>
                  </td>
                  <td class="px-2.5 py-2.5 text-center whitespace-nowrap">{{ ordinal(row.semester) }}</td>
                  <!-- <td class="px-2.5 py-2.5 text-center">{{ row.exam_year ?? '—' }}</td> -->
                  <td class="px-2.5 py-2.5 font-medium whitespace-nowrap">{{ row.packet_code || '—' }}</td>
                  <!-- Who assigned these sheets to the teacher, with when below
                       (the latest assignment, if done in more than one go). -->
                  <td class="px-2.5 py-2.5 whitespace-nowrap">
                    <p class="leading-snug">{{ row.assigned_by_name || '—' }}</p>
                    <p class="mt-0.5 text-[11px] text-muted leading-snug">{{ formatDateTime(row.assigned_at) }}</p>
                  </td>
                  <!-- Total with Completed below; Pending with Problem below —
                       same label : value style as Generate Marksheet. -->
                  <td class="px-2.5 py-2.5">
                    <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 leading-snug whitespace-nowrap">
                      <span class="text-muted">Total</span><span class="text-muted">:</span><span class="font-semibold text-gray-900">{{ row.sheet_count }}</span>
                      <span class="text-muted">Completed</span><span class="text-muted">:</span><span class="font-semibold text-success">{{ row.completed_count }}</span>
                      <!-- Of these, taken from a shared pool. -->
                      <template v-if="row.from_pool_count > 0">
                        <span class="text-muted">From pool</span><span class="text-muted">:</span><span class="font-semibold text-gray-900">{{ row.from_pool_count }}</span>
                      </template>
                    </div>
                  </td>
                  <td class="px-2.5 py-2.5">
                    <div class="inline-grid grid-cols-[auto_auto_auto] gap-x-1.5 gap-y-0.5 leading-snug whitespace-nowrap">
                      <span class="text-muted">Pending</span><span class="text-muted">:</span><span class="font-semibold text-brand">{{ row.pending_count ?? 0 }}</span>
                      <span class="text-muted">Problem</span><span class="text-muted">:</span><span class="font-semibold text-amber-600">{{ row.problem_count ?? 0 }}</span>
                      <!-- Not started by anyone yet, in pools this teacher shares. -->
                      <template v-if="row.pool_waiting_count > 0">
                        <span class="text-muted" title="Not started by anyone yet — shared with the other pool teachers">Pool waiting</span><span class="text-muted">:</span><span class="font-semibold text-cyan-700">{{ row.pool_waiting_count }}</span>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
