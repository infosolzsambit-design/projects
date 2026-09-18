<script setup>
// Opened by AssignTeacherView.vue's "Assign" button (POST /assign-teacher)
// and TeacherReassignModal.vue's "Reassign" button (POST /teachers/{id}
// /assignments/reassign) once each one's own validation passes — see
// assignPapers() / submitReassign() there. This modal is the one thing
// that actually calls the endpoint: subject/body are pre-filled here
// (editable), and clicking "Send Mail and {actionWord}" submits the whole
// request (the fields the parent already built, plus these two). The
// backend commits synchronously and only *then* sends the per-teacher
// emails, dispatched ->afterResponse() (see AssignTeacherController::store()
// / TeacherController::reassignAssignment()) — so this call resolves as
// soon as the DB work is done, not after mail delivery.
import { ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

const props = defineProps({
  endpoint: { type: String, required: true },
  payload: { type: Object, required: true }, // everything the parent already builds, minus email_subject/email_body
  actionWord: { type: String, default: 'Assign' }, // 'Assign' | 'Reassign' — drives the title/button/loading text
  courseName: { type: String, default: '' },
  evaluationStartDateDisplay: { type: String, default: '' },
  evaluationEndDateDisplay: { type: String, default: '' },
  siteTitle: { type: String, default: 'Paper Check' },
})
const emit = defineEmits(['close', 'assigned'])
const toast = useToast()

const defaultSubject = `Answer Sheets ${props.actionWord === 'Reassign' ? 'Reassigned' : 'Assigned'} — ${props.courseName || 'Evaluation'}`
// A single evaluation window only makes sense to state up front for a
// fresh Assign (one window is picked for the whole batch); a Reassign
// hands over sheets that may already carry different windows per teacher
// (see TeacherReassignMailService), so that block is only included when
// the caller actually has one shared window to show.
const windowBlock = props.evaluationStartDateDisplay || props.evaluationEndDateDisplay
  ? `\n\nEvaluation Window:\nStart: ${props.evaluationStartDateDisplay || '—'}\nEnd: ${props.evaluationEndDateDisplay || '—'}`
  : ''
const defaultBody = `You have been ${props.actionWord === 'Reassign' ? 'reassigned' : 'assigned'} answer sheets to evaluate for ${props.courseName || 'this course'}.${windowBlock}

Please log in to ${props.siteTitle} at your earliest convenience and complete your evaluations within the given window. If you have any questions, please reach out to the administration.

Thank you for your continued support.`

const subject = ref(defaultSubject)
const body = ref(defaultBody)
const errors = ref({ subject: '', body: '' })
const sending = ref(false)

function close() {
  if (sending.value) return
  emit('close')
}

async function send() {
  errors.value.subject = subject.value.trim() ? '' : 'Required.'
  errors.value.body = body.value.trim() ? '' : 'Required.'
  if (errors.value.subject || errors.value.body) return

  sending.value = true
  try {
    const res = await api.post(props.endpoint, {
      ...props.payload,
      email_subject: subject.value.trim(),
      email_body: body.value.trim(),
    })
    emit('assigned', res.data)
    emit('close')
  } catch (err) {
    toast.error(err.response?.data?.message || `Could not save this ${props.actionWord.toLowerCase()}ment.`)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl px-6 py-7 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 mb-1">
        <h2 class="text-lg font-bold text-black">Send Mail and {{ actionWord }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="sending" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>
      <p class="text-[12.5px] text-muted mb-4">
        Every {{ actionWord.toLowerCase() }}ed teacher will get this by email, with their own course, dates and sheet count. Review or edit it before sending.
      </p>

      <div class="flex flex-col gap-1.5 mb-4">
        <label for="assign_email_subject" class="text-[12px] text-label">Subject <span class="text-brand">*</span></label>
        <input
          id="assign_email_subject"
          v-model="subject"
          type="text"
          maxlength="255"
          class="h-10 px-3 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
          :class="{ 'border-brand': errors.subject }"
          @input="errors.subject = ''"
        />
        <p v-if="errors.subject" class="text-[11px] text-brand">{{ errors.subject }}</p>
      </div>

      <div class="flex flex-col gap-1.5 mb-5">
        <label for="assign_email_body" class="text-[12px] text-label">Body <span class="text-brand">*</span></label>
        <textarea
          id="assign_email_body"
          v-model="body"
          rows="9"
          class="w-full px-3 py-2.5 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition resize-none"
          :class="{ 'border-brand': errors.body }"
          @input="errors.body = ''"
        ></textarea>
        <p v-if="errors.body" class="text-[11px] text-brand">{{ errors.body }}</p>
        <p class="text-[11px] text-muted">Each teacher's own course, evaluation dates and assigned sheet count are added automatically below this message.</p>
      </div>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
          :disabled="sending"
          @click="close"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="sending"
          @click="send"
        >
          {{ sending ? `${actionWord}ing…` : `Send Mail and ${actionWord}` }}
        </button>
      </div>
    </div>
  </div>
</template>
