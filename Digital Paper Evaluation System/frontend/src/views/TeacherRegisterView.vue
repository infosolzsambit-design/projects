<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { useTeacherStore } from '../stores/teacher'
import { loadFaceModels, getFaceDescriptor } from '../utils/face'

const teacherStore = useTeacherStore()

const name = ref(teacherStore.name)
const photoUrl = ref(teacherStore.photoUrl)

const videoEl = ref(null)
const captureCanvas = ref(null)
let stream = null

// idle | starting | live | captured | camera-error
const cameraStatus = ref('idle')
const cameraError = ref('')

// idle | loading-model | processing | no-face | error | done
const status = ref('idle')
const errorMessage = ref('')

async function startCamera() {
  cameraStatus.value = 'starting'
  cameraError.value = ''
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    videoEl.value.srcObject = stream
    cameraStatus.value = 'live'
  } catch (err) {
    cameraStatus.value = 'camera-error'
    cameraError.value = err?.message || String(err)
  }
}

function stopCamera() {
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function capturePhoto() {
  const video = videoEl.value
  const canvas = captureCanvas.value
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
  photoUrl.value = canvas.toDataURL('image/jpeg', 0.92)
  stopCamera()
  cameraStatus.value = 'captured'
  status.value = 'idle'
}

function retakePhoto() {
  photoUrl.value = ''
  status.value = 'idle'
  startCamera()
}

async function register() {
  if (!name.value.trim() || !photoUrl.value) return

  errorMessage.value = ''
  status.value = 'loading-model'
  try {
    await loadFaceModels()
    status.value = 'processing'
    const descriptor = await getFaceDescriptor(captureCanvas.value)
    if (!descriptor) {
      status.value = 'no-face'
      return
    }
    teacherStore.register({ name: name.value.trim(), photoUrl: photoUrl.value, descriptor })
    status.value = 'done'
  } catch (err) {
    console.error('Face registration failed', err)
    status.value = 'error'
    errorMessage.value = err?.message || String(err)
  }
}

onBeforeUnmount(stopCamera)
</script>

<template>
  <section class="register-view">
    <h1>Teacher Registration</h1>
    <p class="hint">
      Capture a clear, front-facing photo with your camera. It's used to verify your identity before
      you can start or finish checking a paper.
    </p>

    <form class="register-form" @submit.prevent="register">
      <label>
        Name
        <input v-model="name" type="text" placeholder="e.g. Anita Sen" required />
      </label>

      <div class="camera-block">
        <span class="field-label">Profile Photo</span>

        <button v-if="cameraStatus === 'idle'" type="button" class="secondary-btn" @click="startCamera">
          Start Camera
        </button>
        <p v-else-if="cameraStatus === 'starting'" class="status-msg">Requesting camera access&hellip;</p>
        <p v-else-if="cameraStatus === 'camera-error'" class="status-msg error-text">
          Could not access the camera: {{ cameraError }}
          <button type="button" class="link-btn" @click="startCamera">Retry</button>
        </p>

        <div v-show="cameraStatus === 'live'" class="video-wrap">
          <video ref="videoEl" autoplay playsinline muted></video>
        </div>
        <button v-if="cameraStatus === 'live'" type="button" class="secondary-btn" @click="capturePhoto">
          Capture Photo
        </button>

        <div v-if="cameraStatus === 'captured'" class="photo-preview">
          <img :src="photoUrl" alt="Captured profile photo" />
        </div>
        <button v-if="cameraStatus === 'captured'" type="button" class="secondary-btn" @click="retakePhoto">
          Retake Photo
        </button>

        <canvas ref="captureCanvas" class="capture-canvas"></canvas>
      </div>

      <button
        type="submit"
        :disabled="!name.trim() || cameraStatus !== 'captured' || status === 'loading-model' || status === 'processing'"
      >
        {{ status === 'loading-model' ? 'Loading face model…' : status === 'processing' ? 'Processing…' : 'Register' }}
      </button>

      <p v-if="status === 'no-face'" class="status-msg error-text">
        No face detected in this photo. Please retake with your face clearly visible.
      </p>
      <p v-else-if="status === 'error'" class="status-msg error-text">{{ errorMessage }}</p>
      <p v-else-if="status === 'done'" class="status-msg success-text">
        ✅ Registered. You can now
        <RouterLink to="/review">go to Teacher Review</RouterLink>.
      </p>
    </form>
  </section>
</template>

<style scoped>
.hint {
  color: var(--color-text);
  opacity: 0.8;
  max-width: 640px;
  margin-bottom: 2rem;
}

.register-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 420px;
}

.register-form label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-weight: 600;
  color: var(--color-heading);
}

.register-form input[type='text'] {
  font-weight: normal;
  padding: 0.5rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

.camera-block {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.field-label {
  font-weight: 600;
  color: var(--color-heading);
}

.video-wrap {
  width: 240px;
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

.capture-canvas {
  display: none;
}

.photo-preview {
  width: 160px;
  height: 160px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--color-border);
}

.photo-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.register-form button:not(.link-btn) {
  align-self: flex-start;
  padding: 0.6rem 1.5rem;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
}

.register-form button[type='submit'] {
  background: hsla(160, 100%, 37%, 1);
  color: white;
}

.register-form button[type='submit']:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.secondary-btn {
  background: var(--color-background-soft);
  border: 1px solid var(--color-border) !important;
  color: var(--color-text);
}

.link-btn {
  background: none;
  border: none;
  color: hsla(160, 100%, 45%, 1);
  cursor: pointer;
  text-decoration: underline;
  padding: 0;
  font-size: inherit;
}

.status-msg {
  font-size: 0.9rem;
}

.error-text {
  color: #d33;
}

.success-text {
  color: hsla(160, 100%, 30%, 1);
}

.success-text a {
  color: inherit;
  font-weight: 600;
}
</style>
