<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

// Opened from AssignedTeachersView.vue's row action menu ("Reassign") —
// the "this teacher is on leave, split their pending work across the rest
// of the team" flow. Two steps:
//  1. Course list — GET /teachers/{id}/assignments, one row per packet
//     (question_answer_sheet_mapping) this teacher has any sheets in.
//  2. Reassign panel for whichever course was clicked — Distribute
//     Equally (or hand-picked quantities) across one or more other
//     teachers, same shape as AssignTeacherView.vue's own main flow, just
//     drawing from this one teacher's own sheets in that packet instead
//     of the pending pool. POST /teachers/{id}/assignments/reassign.
const props = defineProps({
  teacher: { type: Object, required: true }, // { id, name }
})
const emit = defineEmits(['close', 'reassigned'])
const toast = useToast()

const step = ref('courses') // 'courses' | 'reassign'

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

// --- Every other active teacher (this one excluded), loaded once and
// reused for whichever course's reassign panel is open. -----------------
const allOtherTeachers = ref([])
const teachersLoading = ref(true)
async function loadTeachers() {
  teachersLoading.value = true
  try {
    const res = await api.get('/teachers', { params: { per_page: 200, is_active: 'yes' } })
    allOtherTeachers.value = res.data.data.items
      .filter((t) => t.id !== props.teacher.id)
      .map((t) => ({ id: t.id, name: t.name, department: t.department || '—' }))
  } catch {
    // Non-fatal — the picker just stays empty; the rest of the modal still works.
  } finally {
    teachersLoading.value = false
  }
}
onMounted(loadTeachers)

// --- Step 2: reassign panel for the course picked in step 1 -------------
const selectedRow = ref(null)
const reassignRows = ref([])
const reassignSearch = ref('')
const reassigning = ref(false)

function selectCourse(row) {
  selectedRow.value = row
  reassignSearch.value = ''
  reassignRows.value = allOtherTeachers.value.map((t) => ({ ...t, selected: false, quantity: 0 }))
  step.value = 'reassign'
}

function backToCourses() {
  step.value = 'courses'
  selectedRow.value = null
}

const filteredReassignRows = computed(() => {
  const q = reassignSearch.value.trim().toLowerCase()
  if (!q) return reassignRows.value
  return reassignRows.value.filter((r) => r.name.toLowerCase().includes(q) || r.department.toLowerCase().includes(q))
})
const selectedReassignRows = computed(() => reassignRows.value.filter((r) => r.selected))
const allReassignRowsSelected = computed(
  () => filteredReassignRows.value.length > 0 && filteredReassignRows.value.every((r) => r.selected),
)
function toggleSelectAllReassignRows() {
  const next = !allReassignRowsSelected.value
  filteredReassignRows.value.forEach((r) => (r.selected = next))
}

const reassignEachGets = computed(() => {
  if (!selectedRow.value || !selectedReassignRows.value.length) return 0
  return Math.floor(selectedRow.value.sheet_count / selectedReassignRows.value.length)
})
const reassignRemainder = computed(() => {
  if (!selectedRow.value) return 0
  return selectedRow.value.sheet_count - reassignEachGets.value * selectedReassignRows.value.length
})
const reassignTotalPicked = computed(() => reassignRows.value.reduce((sum, r) => sum + (Number(r.quantity) || 0), 0))

function distributeReassignEqually() {
  if (!selectedReassignRows.value.length) {
    toast.error('Select at least one teacher first.')
    return
  }
  const base = reassignEachGets.value
  const remainder = reassignRemainder.value
  let selectedIndex = 0
  reassignRows.value.forEach((r) => {
    if (!r.selected) {
      r.quantity = 0
      return
    }
    r.quantity = base + (selectedIndex < remainder ? 1 : 0)
    selectedIndex++
  })
}

async function submitReassign() {
  const row = selectedRow.value
  if (!row) return

  const picks = reassignRows.value.filter((r) => r.selected && Number(r.quantity) > 0)
  if (!picks.length) {
    toast.error('Select at least one teacher and give them a quantity, or click "Distribute Equally".')
    return
  }
  if (reassignTotalPicked.value > row.sheet_count) {
    toast.error(`Only ${row.sheet_count} sheet(s) are with this teacher for this course.`)
    return
  }

  reassigning.value = true
  try {
    const res = await api.post(`/teachers/${props.teacher.id}/assignments/reassign`, {
      mapping_id: row.mapping_id,
      reassignments: picks.map((r) => ({ teacher_id: r.id, quantity: Number(r.quantity) })),
    })

    toast.success(res.data.message || 'Reassigned successfully.')

    const movedTotal = picks.reduce((sum, r) => sum + Number(r.quantity), 0)
    row.sheet_count -= movedTotal
    total.value -= movedTotal
    if (row.sheet_count <= 0) {
      breakdown.value = breakdown.value.filter((r) => r.mapping_id !== row.mapping_id)
    }

    // Let the parent list refresh every affected teacher's "Already
    // Allocated" number.
    emit('reassigned', {
      fromTeacherId: props.teacher.id,
      targets: picks.map((r) => ({ teacherId: r.id, quantity: Number(r.quantity) })),
    })

    backToCourses()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not reassign these answer sheets.')
  } finally {
    reassigning.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">
            <button v-if="step === 'reassign'" type="button" class="text-gray-400 hover:text-gray-700 mr-1.5 align-middle" aria-label="Back to courses" @click="backToCourses">
              <svg class="w-5 h-5 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6" /></svg>
            </button>
            Reassign — {{ teacher.name }}
          </h2>
          <p class="text-[13px] text-muted mt-0.5">
            <template v-if="step === 'courses'">
              {{ total }} answer sheet{{ total === 1 ? '' : 's' }} allocated across {{ breakdown.length }} course{{ breakdown.length === 1 ? '' : 's' }} — pick one to reassign.
            </template>
            <template v-else>
              {{ selectedRow?.course_name || 'This course' }} — split {{ selectedRow?.sheet_count }} sheet{{ selectedRow?.sheet_count === 1 ? '' : 's' }} across one or more other teachers.
            </template>
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>

        <!-- Step 1: course list -->
        <template v-else-if="step === 'courses'">
          <p v-if="loading" class="text-[13px] text-muted text-center py-10">Loading&hellip;</p>
          <p v-else-if="!breakdown.length" class="text-[13px] text-muted text-center py-10">No answer sheets allocated to this teacher yet.</p>
          <div v-else class="flex flex-col gap-2">
            <button
              v-for="row in breakdown"
              :key="row.mapping_id"
              type="button"
              class="w-full text-left rounded-xl border border-soft hover:border-brand-blue px-3.5 py-3 flex items-center justify-between gap-3 transition-colors"
              @click="selectCourse(row)"
            >
              <div class="min-w-0">
                <p class="text-[13px] font-semibold text-gray-900 truncate">
                  {{ row.course_name || 'Untitled course' }}
                  <span v-if="row.course_code" class="text-muted font-normal">({{ row.course_code }})</span>
                </p>
                <p class="text-[11.5px] text-muted mt-0.5 truncate">
                  {{ row.program_name || '—' }} · {{ row.exam_term_name || '—' }} · Sem {{ row.semester ?? '—' }} · {{ row.exam_year ?? '—' }}
                  <span v-if="row.packet_code"> · {{ row.packet_code }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span class="inline-flex items-center rounded-full bg-soft text-brand-blue text-[12px] font-bold px-2.5 py-1">{{ row.sheet_count }} sheet{{ row.sheet_count === 1 ? '' : 's' }}</span>
                <svg class="w-4 h-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
              </div>
            </button>
          </div>
        </template>

        <!-- Step 2: reassign panel for the selected course -->
        <template v-else>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-2.5">
            <div class="rounded-lg bg-input-bg p-2">
              <p class="text-[10px] text-muted mb-0.5">Sheets to reassign</p>
              <p class="text-[13px] font-bold text-gray-900">{{ selectedRow.sheet_count }}</p>
            </div>
            <div class="rounded-lg bg-input-bg p-2">
              <p class="text-[10px] text-muted mb-0.5">Selected teachers</p>
              <p class="text-[13px] font-bold text-gray-900">{{ selectedReassignRows.length }}</p>
            </div>
            <div class="rounded-lg bg-input-bg p-2">
              <p class="text-[10px] text-muted mb-0.5">Each gets</p>
              <p class="text-[13px] font-bold text-gray-900">{{ reassignEachGets }}</p>
            </div>
            <div class="rounded-lg bg-input-bg p-2">
              <p class="text-[10px] text-muted mb-0.5">Picked so far</p>
              <p class="text-[13px] font-bold text-gray-900">{{ reassignTotalPicked }} / {{ selectedRow.sheet_count }}</p>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
            <input
              v-model="reassignSearch"
              type="text"
              placeholder="Search teacher…"
              class="w-full sm:w-56 h-9 px-3 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            />
            <button
              type="button"
              class="h-9 shrink-0 inline-flex items-center justify-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity"
              @click="distributeReassignEqually"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg>
              Distribute Equally
            </button>
          </div>

          <div class="overflow-y-auto max-h-72 rounded-lg border border-soft mb-2.5">
            <table class="w-full text-left text-[12.5px]">
              <thead class="sticky top-0">
                <tr class="bg-subject-header text-white">
                  <th class="px-2.5 py-1.5 w-9">
                    <input type="checkbox" :checked="allReassignRowsSelected" class="w-3.5 h-3.5 cursor-pointer" @change="toggleSelectAllReassignRows" />
                  </th>
                  <th class="px-2.5 py-1.5 font-medium">Teacher</th>
                  <th class="px-2.5 py-1.5 font-medium">Department</th>
                  <th class="px-2.5 py-1.5 font-medium text-center">Quantity</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="teachersLoading">
                  <td colspan="4" class="px-2.5 py-5 text-center text-muted">Loading teachers&hellip;</td>
                </tr>
                <tr v-else-if="!filteredReassignRows.length">
                  <td colspan="4" class="px-2.5 py-5 text-center text-muted">No teachers found.</td>
                </tr>
                <tr v-for="r in filteredReassignRows" v-else :key="r.id" class="border-b border-gray-100 last:border-b-0 even:bg-gray-50">
                  <td class="px-2.5 py-1.5">
                    <input v-model="r.selected" type="checkbox" class="w-3.5 h-3.5 cursor-pointer" />
                  </td>
                  <td class="px-2.5 py-1.5 font-medium">{{ r.name }}</td>
                  <td class="px-2.5 py-1.5">{{ r.department }}</td>
                  <td class="px-2.5 py-1.5 text-center">
                    <input
                      v-model.number="r.quantity"
                      type="number"
                      min="0"
                      :max="selectedRow.sheet_count"
                      :disabled="!r.selected"
                      class="w-16 h-7 px-1.5 rounded-md bg-input-bg text-center text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue transition disabled:opacity-50 disabled:cursor-not-allowed"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="reassigning"
              @click="backToCourses"
            >
              Back
            </button>
            <button
              type="button"
              class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="reassigning"
              @click="submitReassign"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12" /></svg>
              Reassign
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
