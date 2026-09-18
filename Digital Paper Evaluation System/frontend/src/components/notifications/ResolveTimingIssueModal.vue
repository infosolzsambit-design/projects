<script setup>
// NotificationsView.vue's "Resolve" action for a row whose issue_name is
// Timing Issue (see that view's own is_timing_issue check — resolved by
// id server-side, never by matching the name string). A Timing Issue
// never touched the teacher's marks/annotations/consumed time in the
// first place (see MyPendingCourseController::raiseIssue()'s own
// docblock) — resolving one is purely a reschedule, so this modal is just
// the new evaluation window (+ optional new per-sheet time budget) plus
// the admin's own note.
import { computed, reactive, ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'
import { formatDateTime } from '../../utils/date'
import DatePicker from '../common/DatePicker.vue'

const props = defineProps({
  notification: { type: Object, required: true },
})
const emit = defineEmits(['close', 'resolved'])
const toast = useToast()

const startDate = ref('')
const endDate = ref('')
const timePerSheet = ref(props.notification.evaluation_time_per_sheet ?? '')
const adminRemarks = ref('')
const errors = reactive({ evaluation_start_date: '', evaluation_end_date: '' })
const submitting = ref(false)

// The sheet's own *current* start date is the earliest a new one can be
// set to — never today's date — so a missed window gets pushed forward,
// never backdated (see NotificationController::resolveTimingIssue()'s own
// docblock). Only the date portion matters for DatePicker's min, same as
// every other usage of it in this app.
const minStartDate = computed(() => props.notification.evaluation_start_date || '')

// Same H:MM:SS/MM:SS convention as EvaluatePaperView.vue's own timer
// badges — how much of the old window this teacher had actually used
// before the issue was raised (see AnswerSheet's own consumed_time).
function formatConsumedTime(totalSeconds) {
  if (totalSeconds === null || totalSeconds === undefined) return '—'
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const s = Math.floor(totalSeconds % 60)
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

function close() {
  if (submitting.value) return
  emit('close')
}

async function submit() {
  errors.evaluation_start_date = startDate.value ? '' : 'Required.'
  errors.evaluation_end_date = endDate.value ? '' : 'Required.'
  if (startDate.value && endDate.value && endDate.value <= startDate.value) {
    errors.evaluation_end_date = 'End date must be after the start date.'
  }
  if (errors.evaluation_start_date || errors.evaluation_end_date) return

  submitting.value = true
  try {
    const res = await api.post(`/notifications/${props.notification.answer_sheet_id}/resolve-timing-issue`, {
      evaluation_start_date: startDate.value,
      evaluation_end_date: endDate.value,
      evaluation_time_per_sheet: timePerSheet.value || null,
      remarks: adminRemarks.value.trim() || undefined,
    })
    toast.success(res.data.message || 'Timing issue resolved successfully.')
    emit('resolved')
    emit('close')
  } catch (err) {
    const data = err.response?.data
    if (data?.errors?.evaluation_start_date) errors.evaluation_start_date = data.errors.evaluation_start_date[0]
    if (data?.errors?.evaluation_end_date) errors.evaluation_end_date = data.errors.evaluation_end_date[0]
    toast.error(data?.message || 'Could not resolve this issue.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl px-6 py-7 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 mb-4">
        <h2 class="text-lg font-bold text-black">Resolve Timing Issue</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="submitting" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="rounded-xl bg-input-bg p-3.5 mb-4">
        <p class="text-[11px] text-label mb-1">Teacher's Remarks</p>
        <p class="text-[13px] text-gray-800">{{ notification.remarks || '—' }}</p>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Current Start</p>
          <p class="text-[12px] font-semibold text-gray-900">{{ formatDateTime(notification.evaluation_start_date) }}</p>
        </div>
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Current End</p>
          <p class="text-[12px] font-semibold text-gray-900">{{ formatDateTime(notification.evaluation_end_date) }}</p>
        </div>
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Time / Sheet</p>
          <p class="text-[12px] font-semibold text-gray-900">{{ notification.evaluation_time_per_sheet ? `${notification.evaluation_time_per_sheet} min` : '—' }}</p>
        </div>
        <div class="rounded-lg bg-input-bg p-2">
          <p class="text-[10px] text-muted mb-0.5">Consumed Time</p>
          <p class="text-[12px] font-semibold text-gray-900">{{ formatConsumedTime(notification.consumed_time) }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div class="flex flex-col gap-1">
          <label class="text-[12px] text-label">New Start Date <span class="text-brand">*</span></label>
          <DatePicker v-model="startDate" with-time :min="minStartDate" :error="!!errors.evaluation_start_date" @change="errors.evaluation_start_date = ''" />
          <p v-if="errors.evaluation_start_date" class="text-[11px] text-brand">{{ errors.evaluation_start_date }}</p>
        </div>
        <div class="flex flex-col gap-1">
          <label class="text-[12px] text-label">New End Date <span class="text-brand">*</span></label>
          <DatePicker v-model="endDate" with-time :min="startDate" :error="!!errors.evaluation_end_date" @change="errors.evaluation_end_date = ''" />
          <p v-if="errors.evaluation_end_date" class="text-[11px] text-brand">{{ errors.evaluation_end_date }}</p>
        </div>
        <div class="flex flex-col gap-1">
          <label class="text-[12px] text-label">Evaluation Time / Sheet (min)</label>
          <input
            v-model="timePerSheet"
            type="number"
            min="1"
            placeholder="No limit if blank"
            class="h-10 px-3 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
          />
        </div>
      </div>

      <div class="flex flex-col gap-1.5 mb-5">
        <label for="timing_admin_remarks" class="text-[12px] text-label">Admin Remarks</label>
        <textarea
          id="timing_admin_remarks"
          v-model="adminRemarks"
          rows="3"
          placeholder="Optional note about this resolution…"
          class="w-full px-3 py-2.5 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition resize-none"
        ></textarea>
      </div>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
          :disabled="submitting"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="submitting"
          @click="submit"
        >
          {{ submitting ? 'Resolving…' : 'Resolve' }}
        </button>
      </div>
    </div>
  </div>
</template>
