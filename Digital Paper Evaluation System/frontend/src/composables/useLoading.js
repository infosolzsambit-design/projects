import { computed, ref } from 'vue'

// Shared singleton state for one app-wide loading overlay — mirrors
// useConfirm.js/useToast.js's own shared-singleton-composable shape.
// Two independent sources feed the same overlay:
//
// 1. Every API call, automatically — see utils/api.js's request/response
//    interceptors, which call startRequest()/endRequest() around every
//    single axios call app-wide with no per-call-site opt-in needed
//    ("when hit any api loader should work"). requestCount is a counter
//    rather than a boolean so overlapping in-flight requests (e.g. a page
//    that fires a few GETs at once on mount) don't have one's finish hide
//    the overlay while another is still running. Debounced by DEBOUNCE_MS
//    before actually showing anything, so a near-instant call doesn't
//    flash the overlay for one frame — genuinely slow calls still show it,
//    fast ones never do.
// 2. AnswerSheetUploadView.vue's chunked submit, explicitly, via
//    startProgress()/setProgress()/endProgress() — a real 3000-5000-row
//    submit is many sequential POSTs (see that view's own submitToServer()
//    docblock), each of which *also* trips startRequest()/endRequest()
//    above; progress being non-null just means "show the determinate bar
//    instead of the plain spinner", not a second/competing overlay.
const requestCount = ref(0)
const progress = ref(null) // null = indeterminate spinner; 0-100 = determinate bar
const progressLabel = ref('')
const visible = ref(false)

const DEBOUNCE_MS = 150
let showTimer = null

function startRequest() {
  requestCount.value += 1
  if (progress.value !== null) return // already showing the determinate bar
  if (showTimer || visible.value) return
  showTimer = setTimeout(() => {
    showTimer = null
    if (requestCount.value > 0) visible.value = true
  }, DEBOUNCE_MS)
}

function endRequest() {
  requestCount.value = Math.max(0, requestCount.value - 1)
  if (requestCount.value === 0 && progress.value === null) {
    if (showTimer) {
      clearTimeout(showTimer)
      showTimer = null
    }
    visible.value = false
  }
}

function startProgress(label = '') {
  if (showTimer) {
    clearTimeout(showTimer)
    showTimer = null
  }
  progress.value = 0
  progressLabel.value = label
  visible.value = true
}

function setProgress(percent, label) {
  progress.value = Math.max(0, Math.min(100, percent))
  if (label !== undefined) progressLabel.value = label
}

function endProgress() {
  progress.value = null
  progressLabel.value = ''
  visible.value = requestCount.value > 0
}

const isDeterminate = computed(() => progress.value !== null)

export function useLoading() {
  return {
    isActive: visible,
    isDeterminate,
    progress,
    progressLabel,
    startRequest,
    endRequest,
    startProgress,
    setProgress,
    endProgress,
  }
}
