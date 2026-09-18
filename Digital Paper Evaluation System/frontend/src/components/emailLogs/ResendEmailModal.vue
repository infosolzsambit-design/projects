<script setup>
// Opened by EmailLogsView.vue's "Resend" row action — only ever offered on
// a log whose original attempt failed (is_sent = false). Subject/body are
// pre-filled from that failed attempt and freely editable; if left exactly
// as shown, POST /email-logs/{id}/resend sends the *same* subject/body the
// original attempt used (see EmailLogController::resend()'s own docblock)
// — there's no separate "did you change anything" toggle, the form's
// current values are simply what goes out either way.
import { ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

const props = defineProps({
  log: { type: Object, required: true },
})
const emit = defineEmits(['close', 'resent'])
const toast = useToast()

const subject = ref(props.log.subject)
const body = ref(props.log.body)
const errors = ref({ subject: '', body: '' })
const formError = ref('')
const sending = ref(false)

function close() {
  if (sending.value) return
  emit('close')
}

async function send() {
  errors.value.subject = subject.value.trim() ? '' : 'Required.'
  errors.value.body = body.value.trim() ? '' : 'Required.'
  if (errors.value.subject || errors.value.body) return

  formError.value = ''
  sending.value = true
  try {
    const res = await api.post(`/email-logs/${props.log.id}/resend`, {
      subject: subject.value.trim(),
      body: body.value,
    })
    toast.success(res.data.message || 'Email resent successfully.')
    emit('resent')
  } catch (err) {
    formError.value = err.response?.data?.message || 'Could not resend this email.'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-2xl px-6 py-7 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 mb-1">
        <h2 class="text-lg font-bold text-black">Resend Email</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" :disabled="sending" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>
      <p class="text-[12.5px] text-muted mb-4">
        To <strong class="text-gray-800">{{ log.receiver_name || log.receiver_email || 'this recipient' }}</strong><span v-if="log.receiver_email && log.receiver_name"> ({{ log.receiver_email }})</span>.
        Review or edit the subject and message below before sending — leave them as they are to resend exactly what was attempted before.
      </p>

      <p v-if="log.error_message" class="text-[12px] text-brand bg-brand/5 border border-brand/20 rounded-lg px-3 py-2 mb-4">
        Previous attempt failed: {{ log.error_message }}
      </p>

      <p v-if="formError" class="text-[13px] text-brand text-center mb-3">{{ formError }}</p>

      <div class="flex flex-col gap-1.5 mb-4">
        <label for="resend_email_subject" class="text-[12px] text-label">Subject <span class="text-brand">*</span></label>
        <input
          id="resend_email_subject"
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
        <label for="resend_email_body" class="text-[12px] text-label">Email Body (HTML) <span class="text-brand">*</span></label>
        <textarea
          id="resend_email_body"
          v-model="body"
          rows="12"
          class="w-full px-3 py-2.5 rounded-lg bg-input-bg text-[12px] font-mono text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition resize-y"
          :class="{ 'border-brand': errors.body }"
          @input="errors.body = ''"
        ></textarea>
        <p v-if="errors.body" class="text-[11px] text-brand">{{ errors.body }}</p>
        <p class="text-[11px] text-muted">This is the full HTML that was rendered for this email — edit it directly if it needs correcting.</p>
      </div>

      <div class="flex justify-center">
        <button
          type="button"
          class="min-w-[220px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.06em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="sending"
          @click="send"
        >
          {{ sending ? 'Sending…' : 'Resend Email' }}
        </button>
      </div>
    </div>
  </div>
</template>
