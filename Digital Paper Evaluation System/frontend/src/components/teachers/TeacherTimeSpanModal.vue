<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'
import { formatDateTime } from '../../utils/date'
import DatePicker from '../common/DatePicker.vue'
import { useProgramsStore } from '../../stores/programs'
import { typeSuffix } from '../../utils/course'

// Program names display as "Name (Label)" — see stores/programs.js.
const programsStore = useProgramsStore()
programsStore.load().catch(() => {})

// Opened from AssignedTeachersView.vue's row action menu ("Update Time
// Span"). Same course list as TeacherReassignModal.vue; picking a course
// opens a second modal showing that packet's current evaluation window(s)
// for this teacher and a form to set a new one on all of them (PUT
// /teachers/{id}/assignments/{mapping}/time-span).
const props = defineProps({
  teacher: { type: Object, required: true }, // { id, name, emp_code }
})
const emit = defineEmits(['close'])
const toast = useToast()

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
onMounted(fetchAssignments)

// --- second modal: current vs new timings for the picked course ---
const selectedRow = ref(null)
const windows = ref([])
const windowsLoading = ref(false)
const windowsError = ref('')
const form = reactive({ start: '', end: '', timePerSheet: '' })
const errors = reactive({ start: '', end: '', timePerSheet: '' })
const saving = ref(false)

async function selectCourse(row) {
  selectedRow.value = row
  windows.value = []
  windowsError.value = ''
  Object.assign(errors, { start: '', end: '', timePerSheet: '' })
  windowsLoading.value = true
  try {
    const res = await api.get(`/teachers/${props.teacher.id}/assignments/${row.mapping_id}/time-span`)
    windows.value = res.data.data.windows
    // Prefilled with the current (most common) window so only what
    // actually changes needs editing.
    const current = windows.value[0] || {}
    form.start = current.evaluation_start_date || ''
    form.end = current.evaluation_end_date || ''
    form.timePerSheet = current.evaluation_time_per_sheet != null ? String(current.evaluation_time_per_sheet) : ''
  } catch (err) {
    windowsError.value = err.response?.data?.message || 'Could not load the current timings.'
  } finally {
    windowsLoading.value = false
  }
}

function closeEditor() {
  selectedRow.value = null
}

function validate() {
  errors.start = form.start ? '' : 'Required.'
  errors.end = form.end ? '' : 'Required.'
  if (form.start && form.end && form.end < form.start) errors.end = 'Must be on or after the start.'
  const t = String(form.timePerSheet).trim()
  errors.timePerSheet = t && !/^[1-9]\d*$/.test(t) ? 'Whole minutes only (1 or more).' : ''
  return !errors.start && !errors.end && !errors.timePerSheet
}

async function save() {
  if (!validate()) return
  saving.value = true
  try {
    const t = String(form.timePerSheet).trim()
    const res = await api.put(`/teachers/${props.teacher.id}/assignments/${selectedRow.value.mapping_id}/time-span`, {
      evaluation_start_date: form.start,
      evaluation_end_date: form.end,
      evaluation_time_per_sheet: t ? Number(t) : null,
    })
    toast.success(res.data.message || 'Time span updated.')
    closeEditor()
  } catch (err) {
    const fieldErrors = err.response?.data?.errors || {}
    errors.start = fieldErrors.evaluation_start_date?.[0] || ''
    errors.end = fieldErrors.evaluation_end_date?.[0] || ''
    errors.timePerSheet = fieldErrors.evaluation_time_per_sheet?.[0] || ''
    toast.error(err.response?.data?.message || 'Could not update the time span.')
  } finally {
    saving.value = false
  }
}

function formatTimeLimit(minutes) {
  return minutes != null ? `${minutes} min per sheet` : 'No time limit'
}
</script>

<template>
  <!-- Step 1: course list (same as the Reassign modal) -->
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">
            Update Time Span — {{ teacher.name }}
            <span v-if="teacher.emp_code" class="text-muted font-normal">({{ teacher.emp_code }})</span>
          </h2>
          <p class="text-[13px] text-muted mt-0.5">
            {{ total }} answer sheet{{ total === 1 ? '' : 's' }} allocated across {{ breakdown.length }} course{{ breakdown.length === 1 ? '' : 's' }} — pick one to update its timings.
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="emit('close')">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>
        <p v-else-if="loading" class="text-[13px] text-muted text-center py-10">Loading&hellip;</p>
        <p v-else-if="!breakdown.length" class="text-[13px] text-muted text-center py-10">No answer sheets allocated to this teacher yet.</p>
        <div v-else class="flex flex-col gap-2">
          <button
            v-for="row in breakdown"
            :key="row.mapping_id"
            type="button"
            class="w-full text-left rounded-xl border border-soft px-3.5 py-3 flex items-center justify-between gap-3 transition-colors hover:border-brand-blue"
            @click="selectCourse(row)"
          >
            <div class="min-w-0">
              <p class="text-[13px] font-semibold text-gray-900 truncate">
                {{ row.course_name || 'Untitled course' }}
                <span v-if="row.course_code" class="text-muted font-normal">({{ row.course_code }})</span><span v-if="row.course_type" class="text-muted font-normal">{{ typeSuffix(row.course_type) }}</span>
              </p>
              <p class="text-[11.5px] text-muted mt-0.5 truncate">
                {{ programsStore.display(row.program_name) || '—' }} · {{ row.exam_term_name || '—' }} · {{ row.exam_type_name || '—' }} · Sem {{ row.semester ?? '—' }} · {{ row.exam_year ?? '—' }}
                <span v-if="row.packet_code"> · {{ row.packet_code }}</span>
              </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span class="inline-flex items-center rounded-full bg-soft text-brand-blue text-[12px] font-bold px-2.5 py-1">
                {{ row.sheet_count }} sheet{{ row.sheet_count === 1 ? '' : 's' }}
              </span>
              <svg class="w-4 h-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Step 2: current vs new timings for the picked course -->
  <div v-if="selectedRow" class="fixed inset-0 z-[210] flex items-center justify-center bg-black/50 p-4" @click.self="closeEditor">
    <!-- No overflow clipping here: DatePicker's calendar is absolutely
         positioned and has to be able to extend past this card's edge. -->
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl flex flex-col">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div class="min-w-0">
          <h2 class="text-lg font-bold text-black truncate">
            {{ selectedRow.course_name || 'This course' }}
            <span v-if="selectedRow.course_code" class="text-muted font-normal">({{ selectedRow.course_code }})</span><span v-if="selectedRow.course_type" class="text-muted font-normal">{{ typeSuffix(selectedRow.course_type) }}</span>
          </h2>
          <p class="text-[13px] text-muted mt-0.5">
            Applies to all {{ selectedRow.sheet_count }} of {{ teacher.name }}'s answer sheet{{ selectedRow.sheet_count === 1 ? '' : 's' }} in this course.
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="closeEditor">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="px-5 py-4 flex flex-col gap-4">
        <p v-if="windowsLoading" class="text-[13px] text-muted text-center py-6">Loading&hellip;</p>
        <p v-else-if="windowsError" class="text-[13px] text-brand text-center py-6">{{ windowsError }}</p>
        <template v-else>
          <div>
            <p class="text-[13px] font-semibold text-gray-900 mb-2">Current Timings</p>
            <div class="flex flex-col gap-2">
              <div
                v-for="(w, i) in windows"
                :key="i"
                class="rounded-xl bg-page-bg px-3.5 py-2.5 grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-[12.5px]"
              >
                <div><span class="text-label">Start:</span> <span class="font-semibold text-gray-900">{{ formatDateTime(w.evaluation_start_date) }}</span></div>
                <div><span class="text-label">End:</span> <span class="font-semibold text-gray-900">{{ formatDateTime(w.evaluation_end_date) }}</span></div>
                <div><span class="text-label">Eval. Time:</span> <span class="font-semibold text-gray-900">{{ formatTimeLimit(w.evaluation_time_per_sheet) }}</span></div>
                <div v-if="windows.length > 1"><span class="text-label">Sheets:</span> <span class="font-semibold text-gray-900">{{ w.sheet_count }}</span></div>
              </div>
            </div>
            <p v-if="windows.length > 1" class="text-[11.5px] text-muted mt-1.5">
              These sheets currently have different timings — updating sets the same new timing on all of them.
            </p>
          </div>

          <div>
            <p class="text-[13px] font-semibold text-gray-900 mb-2">New Timings</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="flex flex-col gap-1">
                <label for="timespan_start" class="text-[12px] text-label">Evaluation Start <span class="text-brand">*</span></label>
                <DatePicker id="timespan_start" v-model="form.start" dense with-time :error="!!errors.start" @change="errors.start = ''" />
                <p v-if="errors.start" class="text-[11px] text-brand">{{ errors.start }}</p>
              </div>
              <div class="flex flex-col gap-1">
                <label for="timespan_end" class="text-[12px] text-label">Evaluation End <span class="text-brand">*</span></label>
                <DatePicker id="timespan_end" v-model="form.end" dense with-time :min="form.start" :error="!!errors.end" @change="errors.end = ''" />
                <p v-if="errors.end" class="text-[11px] text-brand">{{ errors.end }}</p>
              </div>
              <div class="flex flex-col gap-1">
                <label for="timespan_time" class="text-[12px] text-label">Eval. Time (min)</label>
                <input
                  id="timespan_time"
                  v-model="form.timePerSheet"
                  type="text"
                  inputmode="numeric"
                  placeholder="No limit if blank"
                  class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[12px] text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="errors.timePerSheet ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                  @input="errors.timePerSheet = ''"
                />
                <p v-if="errors.timePerSheet" class="text-[11px] text-brand">{{ errors.timePerSheet }}</p>
              </div>
            </div>
          </div>
        </template>
      </div>

      <div class="flex justify-end gap-2 px-5 py-3.5 border-t border-soft">
        <button type="button" class="min-w-[96px] h-9 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="closeEditor">
          Cancel
        </button>
        <button
          type="button"
          :disabled="saving || windowsLoading || !!windowsError"
          class="min-w-[96px] h-9 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-opacity disabled:opacity-60"
          @click="save"
        >
          {{ saving ? 'Updating…' : 'Update' }}
        </button>
      </div>
    </div>
  </div>
</template>
