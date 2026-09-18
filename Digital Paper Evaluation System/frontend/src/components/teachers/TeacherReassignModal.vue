<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'
import { useBrandingStore } from '../../stores/branding'
import TeacherAllocationModal from './TeacherAllocationModal.vue'
import SendAssignmentEmailModal from './SendAssignmentEmailModal.vue'

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
const brandingStore = useBrandingStore()

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
    // reassignable_count excludes sheets this teacher has already
    // completed (marks set) — those are final and can never be handed to
    // another teacher (see AssignTeacherService::reassign()'s own
    // whereNull('marks') filter); only not-started/in-draft sheets
    // (sheet_count - completed_count) are actually eligible to move.
    breakdown.value = res.data.data.breakdown.map((row) => ({
      ...row,
      reassignable_count: row.sheet_count - (row.completed_count || 0),
    }))
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
      .map((t) => ({
        id: t.id,
        name: t.name,
        emp_code: t.emp_code || '',
        department: t.department || '—',
        allocated_answer_sheet_count: t.allocated_answer_sheet_count ?? 0,
        completed_answer_sheet_count: t.completed_answer_sheet_count ?? 0,
      }))
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
const reassignDepartment = ref('')

// Candidate teacher whose own packet-wise allocation is being inspected —
// reuses TeacherAllocationModal.vue (same read-only breakdown shown from
// AssignedTeachersView.vue) so an admin can check what someone already has
// on their plate before handing them more work, without leaving this panel.
const viewingAllocationFor = ref(null) // { id, name } | null

function selectCourse(row) {
  if (row.reassignable_count <= 0) {
    toast.error('Every sheet in this course has already been completed — nothing left to reassign.')
    return
  }
  selectedRow.value = row
  reassignSearch.value = ''
  reassignRows.value = allOtherTeachers.value.map((t) => ({ ...t, selected: false, quantity: 0 }))
  // Default the department filter to the teacher being reassigned *from*
  // — most reassignments look for someone in the same department first —
  // but only when someone in that department is actually available here;
  // otherwise this would just silently show "No teachers found".
  reassignDepartment.value = reassignRows.value.some((t) => t.department === props.teacher.department)
    ? props.teacher.department
    : ''
  step.value = 'reassign'
}

function backToCourses() {
  step.value = 'courses'
  selectedRow.value = null
}

const reassignDepartments = computed(() => [...new Set(reassignRows.value.map((r) => r.department))].sort())
const filteredReassignRows = computed(() => {
  const q = reassignSearch.value.trim().toLowerCase()
  const dept = reassignDepartment.value
  return reassignRows.value.filter((r) => {
    if (dept && r.department !== dept) return false
    if (!q) return true
    return r.name.toLowerCase().includes(q) || r.department.toLowerCase().includes(q)
  })
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
  return Math.floor(selectedRow.value.reassignable_count / selectedReassignRows.value.length)
})
const reassignRemainder = computed(() => {
  if (!selectedRow.value) return 0
  return selectedRow.value.reassignable_count - reassignEachGets.value * selectedReassignRows.value.length
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

// Validation gate before "Reassign" actually commits anything — once it
// passes, a "Send Mail and Reassign" modal opens (pre-filled subject/body)
// instead of posting right away, same flow as AssignTeacherView.vue's own
// "Assign" button (see SendAssignmentEmailModal.vue). That modal is what
// actually calls POST /teachers/{id}/assignments/reassign.
const showSendMailModal = ref(false)
const pendingReassignPayload = ref(null)
const pendingReassignPicks = ref([])

function submitReassign() {
  const row = selectedRow.value
  if (!row) return

  const picks = reassignRows.value.filter((r) => r.selected && Number(r.quantity) > 0)
  if (!picks.length) {
    toast.error('Select at least one teacher and give them a quantity, or click "Distribute Equally".')
    return
  }
  if (reassignTotalPicked.value > row.reassignable_count) {
    toast.error(`Only ${row.reassignable_count} sheet(s) are still eligible to reassign (not yet completed) with this teacher for this course.`)
    return
  }

  pendingReassignPicks.value = picks
  pendingReassignPayload.value = {
    mapping_id: row.mapping_id,
    reassignments: picks.map((r) => ({ teacher_id: r.id, quantity: Number(r.quantity) })),
  }
  showSendMailModal.value = true
}

// Fires once SendAssignmentEmailModal.vue's own POST call has actually
// committed the reassignment — the exact same post-success cleanup
// submitReassign() used to run inline before this modal existed.
function onReassigned(resData) {
  const row = selectedRow.value
  const picks = pendingReassignPicks.value
  if (!row || !picks.length) return

  toast.success(resData.message || 'Reassigned successfully.')

  const movedTotal = picks.reduce((sum, r) => sum + Number(r.quantity), 0)
  row.sheet_count -= movedTotal
  row.reassignable_count -= movedTotal
  total.value -= movedTotal
  if (row.reassignable_count <= 0) {
    breakdown.value = breakdown.value.filter((r) => r.mapping_id !== row.mapping_id)
  }

  // Let the parent list refresh every affected teacher's "Already
  // Allocated" number.
  emit('reassigned', {
    fromTeacherId: props.teacher.id,
    targets: picks.map((r) => ({ teacherId: r.id, quantity: Number(r.quantity) })),
  })

  backToCourses()
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
            <span v-if="teacher.emp_code" class="text-muted font-normal">({{ teacher.emp_code }})</span>
          </h2>
          <p class="text-[13px] text-muted mt-0.5">
            <template v-if="step === 'courses'">
              {{ total }} answer sheet{{ total === 1 ? '' : 's' }} allocated across {{ breakdown.length }} course{{ breakdown.length === 1 ? '' : 's' }} — pick one to reassign.
            </template>
            <template v-else>Choose who this course's remaining sheets go to.</template>
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
              class="w-full text-left rounded-xl border border-soft px-3.5 py-3 flex items-center justify-between gap-3 transition-colors"
              :class="row.reassignable_count > 0 ? 'hover:border-brand-blue' : 'opacity-60 cursor-not-allowed'"
              @click="selectCourse(row)"
            >
              <div class="min-w-0">
                <p class="text-[13px] font-semibold text-gray-900 truncate">
                  {{ row.course_name || 'Untitled course' }}
                  <span v-if="row.course_code" class="text-muted font-normal">({{ row.course_code }})</span>
                </p>
                <p class="text-[11.5px] text-muted mt-0.5 truncate">
                  {{ row.program_name || '—' }} · {{ row.exam_term_name || '—' }} · {{ row.exam_type_name || '—' }} · Sem {{ row.semester ?? '—' }} · {{ row.exam_year ?? '—' }}
                  <span v-if="row.packet_code"> · {{ row.packet_code }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span v-if="row.reassignable_count > 0" class="inline-flex items-center rounded-full bg-soft text-brand-blue text-[12px] font-bold px-2.5 py-1">
                  {{ row.reassignable_count }} to reassign
                </span>
                <span v-else class="inline-flex items-center rounded-full bg-soft text-muted text-[12px] font-bold px-2.5 py-1">All completed</span>
                <svg class="w-4 h-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
              </div>
            </button>
          </div>
        </template>

        <!-- Step 2: reassign panel for the selected course -->
        <template v-else>
          <!-- Course + exam-detail summary — a proper card instead of a
               cramped "·"-joined line, so it's actually legible at a
               glance instead of read like a raw ID string. -->
          <div class="rounded-2xl bg-gradient-to-br from-brand-blue/[0.06] to-badge/[0.05] border border-brand-blue/15 px-4 py-3.5 mb-3">
            <div class="flex items-start gap-2.5">
              <span class="w-9 h-9 rounded-xl bg-white shadow-sm flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
              </span>
              <div class="min-w-0">
                <p class="text-[15px] font-bold text-gray-900 truncate">
                  {{ selectedRow?.course_name || 'This course' }}
                  <span v-if="selectedRow?.course_code" class="text-muted font-medium">({{ selectedRow.course_code }})</span>
                </p>
                <p class="text-[12px] text-muted mt-0.5">
                  Split {{ selectedRow?.reassignable_count }} sheet{{ selectedRow?.reassignable_count === 1 ? '' : 's' }} across one or more other teachers.
                </p>
              </div>
            </div>
            <div class="flex flex-wrap gap-1.5 mt-3">
              <span class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">{{ selectedRow?.program_name || '—' }}</span>
              <span class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">{{ selectedRow?.exam_term_name || '—' }}</span>
              <span class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">{{ selectedRow?.exam_type_name || '—' }}</span>
              <span class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">Semester {{ selectedRow?.semester ?? '—' }}</span>
              <span class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">{{ selectedRow?.exam_year ?? '—' }}</span>
              <span v-if="selectedRow?.packet_code" class="inline-flex items-center rounded-full bg-white border border-input-border px-2.5 py-1 text-[11px] font-medium text-gray-700">Packet {{ selectedRow.packet_code }}</span>
            </div>
          </div>

          <!-- Stats — one glance at where this distribution stands. -->
          <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-3">
            <div class="rounded-xl bg-white border border-soft px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-gray-900 leading-none">{{ selectedRow.sheet_count }}</p>
              <p class="text-[10px] text-muted mt-1.5">Total Sheets</p>
            </div>
            <div class="rounded-xl bg-white border border-soft px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-success leading-none">{{ selectedRow.completed_count }}</p>
              <p class="text-[10px] text-muted mt-1.5">Completed</p>
            </div>
            <div class="rounded-xl bg-brand-blue/5 border border-brand-blue/20 px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-brand-blue leading-none">{{ selectedRow.reassignable_count }}</p>
              <p class="text-[10px] text-muted mt-1.5">To Reassign</p>
            </div>
            <div class="rounded-xl bg-white border border-soft px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-gray-900 leading-none">{{ selectedReassignRows.length }}</p>
              <p class="text-[10px] text-muted mt-1.5">Teachers Picked</p>
            </div>
            <div class="rounded-xl bg-white border border-soft px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-gray-900 leading-none">{{ reassignEachGets }}</p>
              <p class="text-[10px] text-muted mt-1.5">Each Gets</p>
            </div>
            <div class="rounded-xl bg-brand-blue/5 border border-brand-blue/20 px-2 py-2.5 text-center">
              <p class="text-[17px] font-bold text-brand-blue leading-none">{{ reassignTotalPicked }}<span class="text-[12px] font-semibold text-muted">/{{ selectedRow.reassignable_count }}</span></p>
              <p class="text-[10px] text-muted mt-1.5">Picked So Far</p>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
              <input
                v-model="reassignSearch"
                type="text"
                placeholder="Search teacher…"
                class="w-full sm:w-48 h-9 px-3 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
              />
              <select
                v-model="reassignDepartment"
                class="w-full sm:w-48 h-9 px-3 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
              >
                <option value="">All departments</option>
                <option v-for="dept in reassignDepartments" :key="dept" :value="dept">{{ dept }}</option>
              </select>
            </div>
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
                  <th class="px-2.5 py-1.5 font-medium text-center">Already Allocated</th>
                  <th class="px-2.5 py-1.5 font-medium text-center">Completed</th>
                  <th class="px-2.5 py-1.5 font-medium text-center">Quantity</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="teachersLoading">
                  <td colspan="6" class="px-2.5 py-5 text-center text-muted">Loading teachers&hellip;</td>
                </tr>
                <tr v-else-if="!filteredReassignRows.length">
                  <td colspan="6" class="px-2.5 py-5 text-center text-muted">No teachers found.</td>
                </tr>
                <tr v-for="r in filteredReassignRows" v-else :key="r.id" class="border-b border-gray-100 last:border-b-0 even:bg-gray-50">
                  <td class="px-2.5 py-1.5">
                    <input v-model="r.selected" type="checkbox" class="w-3.5 h-3.5 cursor-pointer" />
                  </td>
                  <td class="px-2.5 py-1.5 font-medium">
                    {{ r.name }}
                    <span v-if="r.emp_code" class="text-muted font-normal">({{ r.emp_code }})</span>
                  </td>
                  <td class="px-2.5 py-1.5">{{ r.department }}</td>
                  <td class="px-2.5 py-1.5 text-center">
                    <button
                      type="button"
                      class="font-semibold text-brand-blue hover:underline"
                      @click="viewingAllocationFor = { id: r.id, name: r.name }"
                    >
                      {{ r.allocated_answer_sheet_count }}
                    </button>
                  </td>
                  <td class="px-2.5 py-1.5 text-center font-semibold text-success">{{ r.completed_answer_sheet_count }}</td>
                  <td class="px-2.5 py-1.5 text-center">
                    <input
                      v-model.number="r.quantity"
                      type="number"
                      min="0"
                      :max="selectedRow.reassignable_count"
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
              class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
              @click="backToCourses"
            >
              Back
            </button>
            <button
              type="button"
              class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity"
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

  <TeacherAllocationModal
    v-if="viewingAllocationFor"
    :teacher="viewingAllocationFor"
    @close="viewingAllocationFor = null"
  />

  <SendAssignmentEmailModal
    v-if="showSendMailModal"
    :endpoint="`/teachers/${teacher.id}/assignments/reassign`"
    action-word="Reassign"
    :payload="pendingReassignPayload"
    :course-name="selectedRow?.course_name ? `${selectedRow.course_name}${selectedRow.course_code ? ` (${selectedRow.course_code})` : ''}` : ''"
    :site-title="brandingStore.siteTitleValue"
    @close="showSendMailModal = false"
    @assigned="onReassigned"
  />
</template>
