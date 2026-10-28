<script setup>
// Configurations → Email Logs: clicking a subject opens the email the way
// its recipient saw it — subject, recipient, date, status, then the exact
// HTML that was sent (email_logs.body, the rendered mailable).
//
// The HTML is shown in a sandboxed <iframe> (srcdoc): its own styles can't
// leak into the app, and no script inside it can run (no allow-scripts).
// allow-same-origin only so the frame can be sized to the email's height;
// links open in a new tab.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { resolveStorageUrl } from '../../utils/api'
import { formatDateTime } from '../../utils/date'

const props = defineProps({
  log: { type: Object, required: true }, // EmailLogResource
  typeLabel: { type: String, default: '' },
})
const emit = defineEmits(['close'])

const frame = ref(null)
const frameHeight = ref(420)

// The logos in a sent email are full links to the server that sent it
// (MailBrandingService) — e.g. "http://localhost:8001/storage/…" for a mail
// sent from a local copy whose database was later moved to live. Those
// can't load here, so our own logo paths are pointed at this deployment:
// "/storage/…" uploads at the backend, the "/images/…" defaults at this
// frontend. Only for display — the stored email is not changed.
function withCurrentImageUrls(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html')
  doc.querySelectorAll('img[src]').forEach((img) => {
    let path
    try {
      path = new URL(img.getAttribute('src'), window.location.origin).pathname
    } catch {
      return
    }
    if (path.startsWith('/storage/')) img.setAttribute('src', resolveStorageUrl(path))
    else if (path.startsWith('/images/')) img.setAttribute('src', window.location.origin + path)
  })
  return doc.documentElement.outerHTML
}

// Links open in a new tab instead of inside the frame.
const srcdoc = `<base target="_blank">${props.log.body ? withCurrentImageUrls(props.log.body) : '<p style="font-family:sans-serif;color:#888">This email has no body.</p>'}`

function fitFrame() {
  try {
    const doc = frame.value?.contentDocument
    if (doc) frameHeight.value = Math.max(200, doc.documentElement.scrollHeight + 8)
  } catch {
    // keep the default height
  }
}

function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-3xl max-h-[92vh] flex flex-col overflow-hidden" role="dialog" aria-labelledby="email-view-subject">
      <!-- Header: subject + who / when, like a mail client -->
      <div class="flex items-start justify-between gap-3 px-6 pt-5 pb-4 border-b border-soft">
        <div class="min-w-0">
          <h2 id="email-view-subject" class="text-[17px] font-bold text-black leading-snug break-words">{{ log.subject || '(no subject)' }}</h2>
          <dl class="mt-2 grid grid-cols-[64px_1fr] gap-x-2 gap-y-0.5 text-[12.5px]">
            <dt class="text-muted">To</dt>
            <dd class="text-gray-800 break-words">
              <span class="font-semibold">{{ log.receiver_name || '—' }}</span>
              <span v-if="log.receiver_email" class="text-muted"> &lt;{{ log.receiver_email }}&gt;</span>
            </dd>
            <template v-if="log.sender_name">
              <dt class="text-muted">Sent by</dt>
              <dd class="text-gray-800">{{ log.sender_name }}</dd>
            </template>
            <dt class="text-muted">Date</dt>
            <dd class="text-gray-800">{{ formatDateTime(log.sent_at || log.created_at) }}</dd>
            <template v-if="typeLabel">
              <dt class="text-muted">Type</dt>
              <dd class="text-gray-800">{{ typeLabel }}</dd>
            </template>
            <dt class="text-muted">Status</dt>
            <dd>
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                :class="log.is_sent ? 'bg-green-50 text-green-700' : 'bg-red-50 text-brand'"
              >
                {{ log.is_sent ? 'Sent' : 'Not sent' }}
              </span>
              <span v-if="!log.is_sent && log.error_message" class="ml-2 text-[12px] text-brand break-words">{{ log.error_message }}</span>
            </dd>
          </dl>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="emit('close')">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <!-- The email itself -->
      <div class="flex-1 overflow-y-auto bg-page-bg p-3 sm:p-4">
        <iframe
          ref="frame"
          :srcdoc="srcdoc"
          sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
          title="Email content"
          class="w-full rounded-xl bg-white border border-soft"
          :style="{ height: `${frameHeight}px` }"
          @load="fitFrame"
        ></iframe>
      </div>

      <div class="flex justify-end px-6 py-3 border-t border-soft">
        <button
          type="button"
          class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-5 hover:opacity-90 transition-opacity"
          @click="emit('close')"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>
