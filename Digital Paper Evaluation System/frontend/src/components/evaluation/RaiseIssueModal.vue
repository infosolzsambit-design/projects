<script setup>
// Two entry points share this modal:
//  1. EvaluatePaperView.vue's "Problem" action (`locked: true`, the
//     default) — for when the physical sheet itself can't be evaluated
//     (bad print, wrong timing window, ...) rather than the teacher just
//     not being done yet. The dropdown is locked to Printing Issue (only
//     one workflow exists from inside the marking screen) — pre-selected
//     by id (issues.value[0]?.id, the lowest/well-known id — see
//     IssueMasterController::index()'s own docblock), never by matching
//     the "Printing Issue" name string — and can't be changed; nothing is
//     even sent for issue_master_id, so the backend defaults it itself
//     (see MyPendingCourseController::raiseIssue()).
//  2. MyPendingCoursesView.vue's own "Raise Issue" row action
//     (`locked: false`) — the teacher genuinely picks the issue type
//     themselves; both the issue type and remarks are mandatory there
//     (no default selection — submit is blocked until one's chosen).
// Either way, submitting wipes this sheet's marks/annotations/consumed
// time back to a clean slate on the backend, so the confirm copy below
// says so up front rather than surprising the teacher afterwards.
import { onMounted, ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

const props = defineProps({
  answerSheetId: { type: [Number, String], required: true },
  locked: { type: Boolean, default: true },
})
const emit = defineEmits(['close', 'raised'])
const toast = useToast()

const issues = ref([])
const selectedIssueId = ref(null)
const issuesLoading = ref(true)
const remarks = ref('')
const submitting = ref(false)

async function loadIssues() {
  issuesLoading.value = true
  try {
    const res = await api.get('/issue-masters')
    issues.value = res.data.data
    // Locked mode always has exactly one real workflow to offer, so it
    // defaults straight to it; the unlocked picker deliberately starts
    // empty — the teacher has to actually choose, not just accept
    // whatever sorts first.
    if (props.locked) selectedIssueId.value = issues.value[0]?.id ?? null
  } catch {
    // Non-fatal — the dropdown just stays empty; the backend enforces a
    // sensible default (locked mode) or rejects a missing/invalid
    // selection (unlocked mode) regardless of what's sent.
  } finally {
    issuesLoading.value = false
  }
}
onMounted(loadIssues)

function close() {
  if (submitting.value) return
  emit('close')
}

async function submit() {
  if (!props.locked && !selectedIssueId.value) {
    toast.error('Please select an issue type.')
    return
  }
  if (!remarks.value.trim()) {
    toast.error('Please describe the problem before submitting.')
    return
  }

  submitting.value = true
  try {
    const res = await api.post(`/my-pending-courses/papers/${props.answerSheetId}/raise-issue`, {
      issue_master_id: selectedIssueId.value,
      remarks: remarks.value.trim(),
    })
    toast.success(res.data.message || 'Issue raised successfully.')
    emit('raised')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not raise this issue.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-md px-6 py-7">
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 rounded-full bg-soft flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
          </span>
          <h2 class="text-lg font-bold text-black">Raise Issue</h2>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="submitting" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex flex-col gap-1.5 mb-4">
        <label class="text-[12px] text-label">Issue Type <span v-if="!locked" class="text-brand">*</span></label>
        <select
          v-model="selectedIssueId"
          :disabled="locked"
          class="w-full h-10 px-3 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border transition"
          :class="locked ? 'text-gray-600 cursor-not-allowed' : 'focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15'"
        >
          <option v-if="issuesLoading" :value="null">Loading&hellip;</option>
          <option v-else-if="!locked" :value="null" disabled>Select an issue type&hellip;</option>
          <option v-for="issue in issues" :key="issue.id" :value="issue.id">{{ issue.name }}</option>
        </select>
      </div>

      <div class="flex flex-col gap-1.5 mb-2">
        <label for="issue_remarks" class="text-[12px] text-label">Remarks <span class="text-brand">*</span></label>
        <textarea
          id="issue_remarks"
          v-model="remarks"
          rows="4"
          placeholder="Describe the problem with this answer sheet…"
          class="w-full px-3 py-2.5 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition resize-none"
        ></textarea>
      </div>
      <p class="text-[12px] text-muted mb-5">Submitting this will clear any marks, annotations, and time spent on this sheet so far, and send it back to your pending list flagged with this issue.</p>

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
          {{ submitting ? 'Submitting…' : 'Submit' }}
        </button>
      </div>
    </div>
  </div>
</template>
