<script setup>
import { reactive, ref } from 'vue'
import api from '../../utils/api'

// Upload-then-crop modal for the Profile page's e-signature field — same
// modal chrome as FaceCaptureModal, but for a picked file instead of a
// live camera: choose an image, pan/zoom it inside a fixed 2:1 frame (a
// signature strip's natural shape), then POST the cropped PNG to whichever
// `endpoint` the caller passes. This app has no dependency for cropping
// (jQuery-free, minimal-dependency precedent — see SearchableSelect.vue),
// so it's a small hand-rolled canvas cropper rather than a new package.
const props = defineProps({
  title: { type: String, default: 'Upload E-Signature' },
  endpoint: { type: String, required: true },
})
const emit = defineEmits(['saved', 'close'])

// Fixed on-screen crop frame, CSS px — a wide, short strip matching a
// signature's natural shape. Output is rendered at OUTPUT_SCALE× this for
// a crisper saved image than the frame's own display size.
const FRAME_W = 300
const FRAME_H = 150
const OUTPUT_SCALE = 2
const MAX_ZOOM = 3

const state = reactive({
  // select | crop | processing | done | error
  status: 'select',
  errorMessage: '',
})

const fileInput = ref(null)
const imageEl = ref(null)
const outputCanvas = ref(null)

const image = reactive({
  src: '',
  naturalWidth: 0,
  naturalHeight: 0,
  // Scale that makes the image "cover" the frame at zoom=1 (no gaps).
  baseScale: 1,
  zoom: 1,
  // Displayed image's top-left offset within the frame, CSS px.
  panX: 0,
  panY: 0,
})

const drag = reactive({ active: false, startX: 0, startY: 0, startPanX: 0, startPanY: 0 })

function openFilePicker() {
  fileInput.value?.click()
}

function onFileChange(e) {
  const file = e.target.files?.[0]
  e.target.value = '' // lets picking the exact same file again still fire change
  if (!file) return

  if (!file.type.startsWith('image/')) {
    state.status = 'error'
    state.errorMessage = 'Please choose an image file.'
    return
  }
  if (file.size > 8 * 1024 * 1024) {
    state.status = 'error'
    state.errorMessage = 'Image must be smaller than 8MB.'
    return
  }

  const reader = new FileReader()
  reader.onload = () => {
    image.src = String(reader.result)
    // The crop stage's own <img> (whose @load handler computes
    // naturalWidth/baseScale/centering — see onImageLoad() below) only
    // exists in the DOM once state.status is already 'crop', so this has
    // to flip first — leaving it to onImageLoad() alone would mean that
    // <img> never mounts in the first place and picking an image would
    // silently do nothing.
    state.status = 'crop'
  }
  reader.onerror = () => {
    state.status = 'error'
    state.errorMessage = 'Could not read that file.'
  }
  reader.readAsDataURL(file)
}

function displayedWidth() {
  return image.naturalWidth * image.baseScale * image.zoom
}
function displayedHeight() {
  return image.naturalHeight * image.baseScale * image.zoom
}

// Keeps the image covering the frame — no empty gaps at any pan position.
function clampPan() {
  const dispW = displayedWidth()
  const dispH = displayedHeight()
  image.panX = Math.min(0, Math.max(FRAME_W - dispW, image.panX))
  image.panY = Math.min(0, Math.max(FRAME_H - dispH, image.panY))
}

function onImageLoad() {
  const el = imageEl.value
  image.naturalWidth = el.naturalWidth
  image.naturalHeight = el.naturalHeight
  // "Cover" fit: the smaller of the two ratios would letterbox, so use the
  // larger — the image then fully fills the frame with excess cropped.
  image.baseScale = Math.max(FRAME_W / image.naturalWidth, FRAME_H / image.naturalHeight)
  image.zoom = 1
  // Centered by default.
  image.panX = (FRAME_W - displayedWidth()) / 2
  image.panY = (FRAME_H - displayedHeight()) / 2
  state.status = 'crop'
}

function onZoomInput(e) {
  const newZoom = Number(e.target.value)
  const oldZoom = image.zoom
  // Anchor the zoom on the frame's center so zooming in/out feels like
  // it's zooming toward what's already in view, not toward the image's
  // top-left corner.
  const cx = FRAME_W / 2
  const cy = FRAME_H / 2
  const ratio = newZoom / oldZoom
  image.panX = cx - (cx - image.panX) * ratio
  image.panY = cy - (cy - image.panY) * ratio
  image.zoom = newZoom
  clampPan()
}

function onPointerDown(e) {
  drag.active = true
  drag.startX = e.clientX
  drag.startY = e.clientY
  drag.startPanX = image.panX
  drag.startPanY = image.panY
  e.currentTarget.setPointerCapture(e.pointerId)
}
function onPointerMove(e) {
  if (!drag.active) return
  image.panX = drag.startPanX + (e.clientX - drag.startX)
  image.panY = drag.startPanY + (e.clientY - drag.startY)
  clampPan()
}
function onPointerUp() {
  drag.active = false
}

function chooseDifferentImage() {
  image.src = ''
  state.status = 'select'
}

async function save() {
  state.status = 'processing'
  state.errorMessage = ''
  try {
    const canvas = outputCanvas.value
    canvas.width = FRAME_W * OUTPUT_SCALE
    canvas.height = FRAME_H * OUTPUT_SCALE
    const ctx = canvas.getContext('2d')

    // Map the visible frame back to natural image pixels: the inverse of
    // the display transform (translate panX/panY, scale baseScale*zoom).
    const scaleDisplayToNatural = 1 / (image.baseScale * image.zoom)
    const sx = -image.panX * scaleDisplayToNatural
    const sy = -image.panY * scaleDisplayToNatural
    const sw = FRAME_W * scaleDisplayToNatural
    const sh = FRAME_H * scaleDisplayToNatural

    ctx.drawImage(imageEl.value, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height)
    const dataUrl = canvas.toDataURL('image/png')

    const res = await api.post(props.endpoint, { esign: dataUrl })
    state.status = 'done'
    emit('saved', res.data.data)
    setTimeout(() => emit('close'), 900)
  } catch (err) {
    state.status = 'error'
    state.errorMessage = err.response?.data?.message || 'Could not save this signature.'
  }
}

function retryAfterError() {
  state.status = image.src ? 'crop' : 'select'
  state.errorMessage = ''
}

function close() {
  emit('close')
}
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
        <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileChange" />

        <!-- Select stage -->
        <div v-if="state.status === 'select'" class="flex flex-col items-center gap-3 py-6">
          <div class="w-16 h-16 rounded-full bg-page-bg flex items-center justify-center">
            <svg class="w-7 h-7 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" />
            </svg>
          </div>
          <p class="text-[13px] text-muted text-center">Choose an image of your signature — you'll be able to crop it next.</p>
          <button type="button" class="min-w-[160px] px-6 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="openFilePicker">
            Choose Image
          </button>
        </div>

        <!-- Crop stage -->
        <template v-else-if="state.status === 'crop' || state.status === 'processing' || state.status === 'done'">
          <div
            class="relative mx-auto rounded-2xl overflow-hidden bg-gray-100 border border-input-border touch-none select-none cursor-grab active:cursor-grabbing"
            :style="{ width: FRAME_W + 'px', height: FRAME_H + 'px' }"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
          >
            <img
              ref="imageEl"
              :src="image.src"
              alt="Signature to crop"
              class="absolute top-0 left-0 max-w-none pointer-events-none"
              :style="{
                width: image.naturalWidth * image.baseScale * image.zoom + 'px',
                height: image.naturalHeight * image.baseScale * image.zoom + 'px',
                transform: `translate(${image.panX}px, ${image.panY}px)`,
              }"
              @load="onImageLoad"
            />
            <div v-if="state.status === 'processing'" class="absolute inset-0 bg-black/50 flex items-center justify-center text-white text-sm font-medium">Saving&hellip;</div>
            <div v-if="state.status === 'done'" class="absolute inset-0 bg-green-600/55 flex items-center justify-center text-white text-lg font-bold">&check; Saved</div>
          </div>

          <div v-if="state.status === 'crop'" class="mt-4 flex items-center gap-3">
            <svg class="w-4 h-4 text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
            <input type="range" min="1" :max="MAX_ZOOM" step="0.01" :value="image.zoom" class="w-full accent-brand-blue" @input="onZoomInput" />
            <svg class="w-5 h-5 text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /><line x1="8" y1="11" x2="14" y2="11" /><line x1="11" y1="8" x2="11" y2="14" /></svg>
          </div>
          <p v-if="state.status === 'crop'" class="mt-2 text-[12px] text-muted text-center">Drag to reposition, use the slider to zoom.</p>
        </template>

        <p v-if="state.status === 'error'" class="mt-3 text-[13px] text-brand text-center">{{ state.errorMessage }}</p>

        <canvas ref="outputCanvas" class="hidden"></canvas>

        <div class="mt-5 flex items-center justify-center gap-3">
          <template v-if="state.status === 'crop'">
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="chooseDifferentImage">
              Choose Different
            </button>
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="save">
              Save
            </button>
          </template>
          <template v-else-if="state.status === 'error'">
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="retryAfterError">
              {{ image.src ? 'Back to Crop' : 'Try Again' }}
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
