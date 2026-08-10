<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useTeacherStore } from '../stores/teacher'
import { loadFaceModels, getFaceDescriptor, faceDistance, MATCH_THRESHOLD } from '../utils/face'

defineProps({
  title: { type: String, default: 'Verify Your Face' },
})
const emit = defineEmits(['matched', 'cancel'])

const teacherStore = useTeacherStore()
const videoEl = ref(null)

// loading-model | requesting-camera | ready | scanning | success | no-face | no-match | error
const status = ref('loading-model')
const errorMessage = ref('')
let stream = null

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
  } catch (err) {
    status.value = 'error'
    errorMessage.value = 'Could not access the camera: ' + (err?.message || err)
  }
}

async function scanFace() {
  if (status.value === 'scanning') return
  status.value = 'scanning'
  const descriptor = await getFaceDescriptor(videoEl.value)
  if (!descriptor) {
    status.value = 'no-face'
    return
  }
  const distance = faceDistance(descriptor, teacherStore.descriptor)
  console.debug('[face-scan] distance', distance, 'threshold', MATCH_THRESHOLD)
  if (distance <= MATCH_THRESHOLD) {
    status.value = 'success'
    setTimeout(() => emit('matched'), 600)
  } else {
    status.value = 'no-match'
  }
}

function cancel() {
  emit('cancel')
}

function stopCamera() {
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

const scanEnabledStates = ['ready', 'no-face', 'no-match']

onMounted(setup)
onBeforeUnmount(stopCamera)
</script>

<template>
  <div class="modal-backdrop">
    <div class="modal-card">
      <h2>{{ title }}</h2>

      <div class="video-wrap">
        <video ref="videoEl" autoplay playsinline muted></video>
        <div v-if="status === 'success'" class="scan-overlay success">✅ Matched</div>
        <div v-else-if="status === 'no-match'" class="scan-overlay error">✗ Not Matched</div>
      </div>

      <p v-if="status === 'loading-model'" class="status-msg">Loading face-recognition model&hellip;</p>
      <p v-else-if="status === 'requesting-camera'" class="status-msg">Requesting camera access&hellip;</p>
      <p v-else-if="status === 'error'" class="status-msg error-text">{{ errorMessage }}</p>
      <p v-else-if="status === 'no-face'" class="status-msg error-text">
        No face detected. Center your face in the frame and try again.
      </p>
      <p v-else-if="status === 'no-match'" class="status-msg error-text">
        Face didn't match the registered profile. Try again.
      </p>
      <p v-else-if="status === 'scanning'" class="status-msg">Scanning&hellip;</p>
      <p v-else-if="status === 'success'" class="status-msg success-text">Identity verified.</p>
      <p v-else class="status-msg">Position your face in the frame and click Scan.</p>

      <div class="modal-actions">
        <button class="btn-secondary" @click="cancel">Cancel</button>
        <button class="btn-primary" :disabled="!scanEnabledStates.includes(status)" @click="scanFace">
          Scan Face
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.modal-card {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 1.5rem;
  width: 380px;
  max-width: 90vw;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
}

.modal-card h2 {
  color: var(--color-heading);
  font-size: 1.1rem;
}

.video-wrap {
  position: relative;
  width: 100%;
  aspect-ratio: 4 / 3;
  background: #000;
  border-radius: 8px;
  overflow: hidden;
}

.video-wrap video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scaleX(-1);
}

.scan-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  font-weight: 700;
  color: white;
}

.scan-overlay.success {
  background: rgba(16, 163, 74, 0.55);
}

.scan-overlay.error {
  background: rgba(220, 38, 38, 0.55);
}

.status-msg {
  font-size: 0.85rem;
  min-height: 1.2em;
}

.error-text {
  color: #e57373;
}

.success-text {
  color: hsla(160, 100%, 55%, 1);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 0.25rem;
}

.btn-secondary,
.btn-primary {
  padding: 0.5rem 1.2rem;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--color-border);
}

.btn-secondary {
  background: transparent;
  color: var(--color-text);
}

.btn-primary {
  background: hsla(160, 100%, 37%, 1);
  border-color: hsla(160, 100%, 37%, 1);
  color: white;
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
