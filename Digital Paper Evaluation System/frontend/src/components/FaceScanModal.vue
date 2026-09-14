<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import api from '../utils/api'
import { loadFaceModels, analyzeFace, detectFaceLive } from '../utils/face'

defineProps({
  title: { type: String, default: 'Verify Your Face' },
})
const emit = defineEmits(['matched', 'cancel'])

const videoEl = ref(null)

// loading-model | requesting-camera | ready | scanning | success | no-face | no-match | error
const status = ref('loading-model')
const errorMessage = ref('')
// Position/quality issues only (centered, size, frontal, eyes open, …).
// Unlike FaceCaptureModal.vue's registration scan, this verify-only flow
// doesn't also require a left/right head turn — that liveness check
// belongs at setup time (Profile page), not at "start evaluation" time,
// where it's just "is this the same registered face or not".
const qualityIssues = ref([])
let stream = null
let liveLoopTimer = null

const SCAN_ENABLED_STATES = ['ready', 'no-face', 'no-match']
function readyToScan(currentStatus) {
  return SCAN_ENABLED_STATES.includes(currentStatus)
}

const positionOk = computed(() => readyToScan(status.value) && qualityIssues.value.length === 0)

function stopLiveLoop() {
  clearInterval(liveLoopTimer)
  liveLoopTimer = null
}

function startLiveLoop() {
  stopLiveLoop()
  liveLoopTimer = setInterval(async () => {
    if (!readyToScan(status.value) || !videoEl.value) return
    const result = await detectFaceLive(videoEl.value)
    if (!readyToScan(status.value)) return
    qualityIssues.value = result.issues
  }, 120)
}

async function setup() {
  try {
    await loadFaceModels()
  } catch (err) {
    status.value = 'error'
    errorMessage.value = 'Could not load the face-recognition model: ' + (err?.message || err)
    return
  }

  status.value = 'requesting-camera'
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    videoEl.value.srcObject = stream
    status.value = 'ready'
    startLiveLoop()
  } catch (err) {
    status.value = 'error'
    errorMessage.value = 'Could not access the camera: ' + (err?.message || err)
  }
}

async function scanFace() {
  if (status.value === 'scanning' || qualityIssues.value.length) return
  stopLiveLoop()
  status.value = 'scanning'

  const result = await analyzeFace(videoEl.value)
  if (result.state !== 'ok' || result.issues.length > 0) {
    status.value = 'no-face'
    errorMessage.value = result.issues[0] || 'No face detected. Center your face in the frame and try again.'
    startLiveLoop()
    return
  }

  // Comparison happens server-side against the profile's stored descriptor
  // (see ProfileController::verifyFace()) — the reference descriptor
  // itself is never sent down to the browser to compare locally.
  try {
    const res = await api.post('/profile/face/verify', { descriptor: result.descriptor })
    if (res.data.data.matched) {
      // Doesn't auto-proceed — stays on this "Matched" state until the
      // teacher actually clicks Start Evaluation below, same as the rest
      // of this flow's "no silent auto-advance" convention.
      status.value = 'success'
    } else {
      status.value = 'no-match'
      startLiveLoop()
    }
  } catch (err) {
    status.value = 'error'
    errorMessage.value = err.response?.data?.message || 'Could not verify your face right now.'
  }
}

function startEvaluation() {
  emit('matched')
}

function cancel() {
  emit('cancel')
}

function stopCamera() {
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

onMounted(setup)
onBeforeUnmount(() => {
  stopLiveLoop()
  stopCamera()
})
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-[420px] rounded-[28px] bg-white shadow-panel overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
        <h2 class="text-[18px] font-semibold text-gray-900">{{ title }}</h2>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors" aria-label="Cancel" @click="cancel">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="p-5">
        <div class="relative rounded-[24px] overflow-hidden bg-gray-900 aspect-[4/3] flex items-center justify-center">
          <video ref="videoEl" autoplay playsinline muted class="w-full h-full object-cover" style="transform: scaleX(-1)"></video>
          <div
            v-if="readyToScan(status)"
            class="absolute inset-x-[27%] inset-y-[6%] rounded-full border-2 pointer-events-none transition-colors"
            :class="positionOk ? 'border-green-400' : 'border-white/50'"
          ></div>
          <p v-if="status === 'loading-model' || status === 'requesting-camera'" class="text-white/80 text-sm px-4 text-center">
            {{ status === 'loading-model' ? 'Loading face-recognition model…' : 'Requesting camera access…' }}
          </p>
          <div v-if="status === 'scanning'" class="absolute inset-0 bg-black/50 flex items-center justify-center text-white text-sm font-medium">Scanning&hellip;</div>
          <div v-if="status === 'success'" class="absolute inset-0 bg-green-600/55 flex items-center justify-center text-white text-lg font-bold">&check; Matched</div>
          <div v-if="status === 'no-match'" class="absolute inset-0 bg-brand/55 flex items-center justify-center text-white text-lg font-bold">✗ Not Matched</div>
        </div>

        <p v-if="status === 'error'" class="mt-3 text-[13px] text-brand text-center">{{ errorMessage }}</p>
        <p v-else-if="status === 'no-face'" class="mt-3 text-[13px] text-brand text-center">{{ errorMessage }}</p>
        <p v-else-if="status === 'no-match'" class="mt-3 text-[13px] text-brand text-center">Face didn't match your registered profile. Try again.</p>
        <p v-else-if="status === 'scanning'" class="mt-3 text-[13px] text-muted text-center">Scanning&hellip;</p>
        <p v-else-if="status === 'success'" class="mt-3 text-[13px] text-green-700 text-center font-medium">Identity verified.</p>
        <template v-else-if="readyToScan(status)">
          <p v-if="qualityIssues.length" class="mt-3 text-[13px] text-brand text-center">{{ qualityIssues[0] }}</p>
          <p v-else class="mt-3 text-[13px] text-green-700 text-center font-medium">Looks good — click Scan Face.</p>
        </template>

        <div class="mt-5 flex items-center justify-center gap-3">
          <button type="button" class="min-w-[110px] px-5 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="cancel">
            Cancel
          </button>
          <button
            v-if="status === 'success'"
            type="button"
            class="min-w-[160px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all"
            @click="startEvaluation"
          >
            Start Evaluation
          </button>
          <button
            v-else
            type="button"
            class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="!readyToScan(status) || qualityIssues.length > 0"
            @click="scanFace"
          >
            Scan Face
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
