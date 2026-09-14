<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../../utils/api'
import { loadFaceModels, analyzeFace, detectFaceLive, createTurnTracker } from '../../utils/face'

// Shared face-scan CAPTURE modal — used both by the Profile page (capturing
// your own face) and the Teachers list (an admin capturing/retaking a
// specific teacher's face on their behalf, see TeachersView.vue). POSTs
// {photo, descriptor} to whichever `endpoint` the caller passes and emits
// `saved` with the response payload on success — this component doesn't
// know or care whose face it's saving.
//
// While the camera is live, a background loop (~8/sec) runs the same
// capture-quality checks a real ID-verification flow would — exactly one
// person in frame, centered, a sensible size, fully inside the frame,
// roughly front-facing, eyes open — and Capture only unlocks once all of
// that passes AND the head has been turned to both sides (see
// createTurnTracker in utils/face.js for exactly what that liveness check
// does and doesn't defend against).
const props = defineProps({
  title: { type: String, default: 'Scan Your Face' },
  endpoint: { type: String, required: true },
})
const emit = defineEmits(['saved', 'close'])

const state = reactive({
  // loading-model | starting-camera | live | captured | processing | no-face | error | done
  status: 'loading-model',
  errorMessage: '',
  photoDataUrl: '',
  // Position/quality issues only (centered, size, frontal, eyes open, …) —
  // kept separate from the turn-liveness check below so the oval guide can
  // turn green purely on correct positioning, independent of the turn.
  qualityIssues: [],
  turnedRight: false,
  turnedLeft: false,
  yaw: 0, // live signed head-turn value, drives the 3D indicator below
})
const videoEl = ref(null)
const captureCanvas = ref(null)
let stream = null
let liveLoopTimer = null
const turnTracker = createTurnTracker()

// Oval guide turns green as soon as positioning is correct — the head-turn
// is still required before Capture itself unlocks (see readyToCapture).
const positionOk = computed(() => state.status === 'live' && state.qualityIssues.length === 0)
const readyToCapture = computed(() => positionOk.value && state.turnedRight && state.turnedLeft)
// Drives the 3D head icon's rotateY() — capped so it doesn't flip past a
// believable head-turn angle even if the estimate spikes.
const yawDeg = computed(() => Math.max(-45, Math.min(45, state.yaw * 90)))

function stopCamera() {
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function stopLiveLoop() {
  clearInterval(liveLoopTimer)
  liveLoopTimer = null
}

function startLiveLoop() {
  stopLiveLoop()
  liveLoopTimer = setInterval(async () => {
    if (state.status !== 'live' || !videoEl.value) return
    const result = await detectFaceLive(videoEl.value)
    if (state.status !== 'live') return // modal moved on while awaiting
    state.qualityIssues = result.issues
    if (result.state === 'ok') {
      state.yaw = result.yaw
      turnTracker.update(result.yaw)
      state.turnedRight = turnTracker.turnedRight
      state.turnedLeft = turnTracker.turnedLeft
    } else {
      state.yaw = 0
    }
  }, 120)
}

async function startCameraStage() {
  state.status = 'starting-camera'
  turnTracker.reset()
  state.turnedRight = false
  state.turnedLeft = false
  state.yaw = 0
  state.qualityIssues = []
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    await nextTick()
    if (videoEl.value) videoEl.value.srcObject = stream
    state.status = 'live'
    startLiveLoop()
  } catch (err) {
    state.status = 'error'
    state.errorMessage = 'Could not access the camera: ' + (err?.message || err)
  }
}

async function start() {
  state.status = 'loading-model'
  state.errorMessage = ''
  state.photoDataUrl = ''
  try {
    await loadFaceModels()
  } catch (err) {
    state.status = 'error'
    state.errorMessage = 'Could not load the face-recognition model: ' + (err?.message || err)
    return
  }
  await startCameraStage()
}

function capturePhoto() {
  if (!readyToCapture.value) return
  stopLiveLoop()
  const video = videoEl.value
  const canvas = captureCanvas.value
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
  state.photoDataUrl = canvas.toDataURL('image/jpeg', 0.9)
  stopCamera()
  state.status = 'captured'
}

function retakePhoto() {
  state.photoDataUrl = ''
  startCameraStage()
}

async function confirmPhoto() {
  state.status = 'processing'
  state.errorMessage = ''
  try {
    // Re-checked against the actual frozen frame, not just trusted from
    // the live loop's last reading a moment earlier.
    const result = await analyzeFace(captureCanvas.value)
    if (result.state !== 'ok' || result.issues.length > 0) {
      state.status = 'no-face'
      state.errorMessage = result.issues[0] || 'Could not verify this photo. Please retake it.'
      return
    }
    const res = await api.post(props.endpoint, { photo: state.photoDataUrl, descriptor: result.descriptor })
    state.status = 'done'
    emit('saved', res.data.data)
    setTimeout(() => emit('close'), 900)
  } catch (err) {
    state.status = 'error'
    state.errorMessage = err.response?.data?.message || 'Could not save this photo.'
  }
}

function close() {
  emit('close')
}

onMounted(start)
onBeforeUnmount(() => {
  stopLiveLoop()
  stopCamera()
})
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="close">
    <div class="w-full max-w-[460px] rounded-[28px] bg-white shadow-panel overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
        <h2 class="text-[18px] font-semibold text-gray-900">{{ title }}</h2>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors" aria-label="Close" @click="close">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>
      <div class="p-5">
        <div class="relative rounded-[24px] overflow-hidden bg-gray-900 aspect-[4/3] flex items-center justify-center">
          <video
            v-show="state.status === 'live'"
            ref="videoEl"
            autoplay
            playsinline
            muted
            class="w-full h-full object-cover"
            style="transform: scaleX(-1)"
          ></video>
          <!-- Face-shaped guide — narrower than tall (an oval, not a
               circle), like a real face outline rather than the video
               frame's own 4:3 landscape shape. -->
          <div
            v-if="state.status === 'live'"
            class="absolute inset-x-[27%] inset-y-[6%] rounded-full border-2 pointer-events-none transition-colors"
            :class="positionOk ? 'border-green-400' : 'border-white/50'"
          ></div>
          <img v-if="state.photoDataUrl && state.status !== 'live'" :src="state.photoDataUrl" alt="Captured preview" class="absolute inset-0 w-full h-full object-cover" />
          <p v-if="['loading-model', 'starting-camera'].includes(state.status)" class="text-white/80 text-sm px-4 text-center">
            {{ state.status === 'loading-model' ? 'Loading face-recognition model…' : 'Requesting camera access…' }}
          </p>
          <div v-if="state.status === 'processing'" class="absolute inset-0 bg-black/50 flex items-center justify-center text-white text-sm font-medium">Verifying face&hellip;</div>
          <div v-if="state.status === 'done'" class="absolute inset-0 bg-green-600/55 flex items-center justify-center text-white text-lg font-bold">&check; Saved</div>
        </div>
        <canvas ref="captureCanvas" class="hidden"></canvas>

        <!-- 3D head-turn liveness indicator — the head icon rotates live
             with your actual head turn (perspective + rotateY), and each
             side-checkpoint lights up once you've turned far enough that
             way. Order doesn't matter, just turn to both sides. -->
        <div v-if="state.status === 'live'" class="mt-4 flex items-center justify-center gap-6">
          <div class="flex flex-col items-center gap-1.5">
            <span class="w-3 h-3 rounded-full transition-colors" :class="state.turnedLeft ? 'bg-green-400' : 'bg-gray-300'"></span>
            <span class="text-[10px] text-muted">Left</span>
          </div>
          <div style="perspective: 240px" class="shrink-0">
            <div
              class="w-14 h-14 rounded-full bg-page-bg border-2 flex items-center justify-center transition-transform duration-100"
              :class="positionOk ? 'border-green-400' : 'border-input-border'"
              :style="{ transform: `rotateY(${yawDeg}deg)` }"
            >
              <svg class="w-7 h-7 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 20c0-4 4-6 8-6s8 2 8 6" />
              </svg>
            </div>
          </div>
          <div class="flex flex-col items-center gap-1.5">
            <span class="w-3 h-3 rounded-full transition-colors" :class="state.turnedRight ? 'bg-green-400' : 'bg-gray-300'"></span>
            <span class="text-[10px] text-muted">Right</span>
          </div>
        </div>

        <p v-if="state.status === 'error'" class="mt-3 text-[13px] text-brand text-center">{{ state.errorMessage }}</p>
        <p v-else-if="state.status === 'no-face'" class="mt-3 text-[13px] text-brand text-center">{{ state.errorMessage || 'Could not verify this photo. Please retake it.' }}</p>
        <template v-else-if="state.status === 'live'">
          <p v-if="state.qualityIssues.length" class="mt-3 text-[13px] text-brand text-center">{{ state.qualityIssues[0] }}</p>
          <p v-else-if="state.turnedRight && !state.turnedLeft" class="mt-3 text-[13px] text-green-700 text-center font-medium">Now turn your head to the left.</p>
          <p v-else-if="state.turnedLeft && !state.turnedRight" class="mt-3 text-[13px] text-green-700 text-center font-medium">Now turn your head to the right.</p>
          <p v-else-if="!state.turnedRight && !state.turnedLeft" class="mt-3 text-[13px] text-green-700 text-center font-medium">Position looks good — slowly turn your head to each side.</p>
          <p v-else class="mt-3 text-[13px] text-green-700 text-center font-medium">Looks good — click Capture.</p>
        </template>
        <p v-else-if="state.status === 'captured'" class="mt-3 text-[13px] text-muted text-center">Looks good? Confirm to save, or retake.</p>

        <div class="mt-5 flex items-center justify-center gap-3">
          <template v-if="state.status === 'live'">
            <button
              type="button"
              class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="!readyToCapture"
              @click="capturePhoto"
            >
              Capture
            </button>
          </template>
          <template v-else-if="state.status === 'captured' || state.status === 'no-face'">
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="retakePhoto">
              Retake
            </button>
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="confirmPhoto">
              Confirm
            </button>
          </template>
          <template v-else-if="state.status === 'error'">
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="start">
              Retry
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
