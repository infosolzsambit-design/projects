<script setup>
import { reactive, ref } from 'vue'
import api from '../../utils/api'

// Upload-then-crop modal for the e-signature field (Profile page and the
// admin Teacher form) — same modal chrome as FaceCaptureModal, but for a
// picked file instead of a live camera: choose an image, see the WHOLE image
// fitted in the stage, then drag / resize a free crop box over it (any shape
// — a long, thin signature fits as well as a short one) and POST the cropped
// PNG to whichever `endpoint` the caller passes. Hand-rolled (no cropping
// dependency — minimal-dependency precedent, see SearchableSelect.vue).
const props = defineProps({
  title: { type: String, default: 'Upload E-Signature' },
  endpoint: { type: String, required: true },
})
const emit = defineEmits(['saved', 'close'])

// Stage the image is shown in (CSS px; narrower on small screens).
const STAGE_MAX_W = 400
const STAGE_H = 220
// Margin around the fitted image, so the crop box's edge handles are never
// half-hidden by the stage's own edge.
const STAGE_PAD = 12
// Smallest crop box, CSS px — keeps it grabbable and the result legible.
const MIN_CROP_W = 40
const MIN_CROP_H = 20
// The saved PNG is scaled down to fit inside this (never up), so a huge
// photo of a signature doesn't become a huge stored image.
const MAX_OUT_W = 900
const MAX_OUT_H = 300

const state = reactive({
  // select | crop | processing | done | error
  status: 'select',
  errorMessage: '',
})

const fileInput = ref(null)
const imageEl = ref(null)
const outputCanvas = ref(null)

const stageW = ref(STAGE_MAX_W)
// The image's natural size and where it sits in the stage ("contain" fit,
// centred — the whole image is always visible).
const image = reactive({ src: '', naturalWidth: 0, naturalHeight: 0, x: 0, y: 0, w: 0, h: 0 })
// The crop box, stage px — always kept inside the image.
const crop = reactive({ x: 0, y: 0, w: 0, h: 0 })
// What's being dragged: 'move' or a handle ('n','s','e','w','ne','nw','se','sw').
const drag = reactive({ mode: '', startX: 0, startY: 0, start: { x: 0, y: 0, w: 0, h: 0 } })

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']

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
    // The crop stage's <img> (whose @load sets everything up — see
    // onImageLoad()) only mounts once status is 'crop', so flip it first.
    state.status = 'crop'
  }
  reader.onerror = () => {
    state.status = 'error'
    state.errorMessage = 'Could not read that file.'
  }
  reader.readAsDataURL(file)
}

function onImageLoad() {
  const el = imageEl.value
  stageW.value = Math.min(STAGE_MAX_W, window.innerWidth - 72)
  image.naturalWidth = el.naturalWidth
  image.naturalHeight = el.naturalHeight
  // "Contain" fit: the whole image visible, centred in the stage (inside
  // STAGE_PAD on every side). The stage's 1px border is outside the
  // positioning area, hence the extra 2.
  const scale = Math.min((stageW.value - 2 - 2 * STAGE_PAD) / el.naturalWidth, (STAGE_H - 2 - 2 * STAGE_PAD) / el.naturalHeight)
  image.w = el.naturalWidth * scale
  image.h = el.naturalHeight * scale
  image.x = (stageW.value - 2 - image.w) / 2
  image.y = (STAGE_H - 2 - image.h) / 2
  // Start with the crop box over the whole image; the teacher trims it.
  Object.assign(crop, { x: image.x, y: image.y, w: image.w, h: image.h })
  state.status = 'crop'
}

function onPointerDown(e, mode) {
  drag.mode = mode
  drag.startX = e.clientX
  drag.startY = e.clientY
  drag.start = { ...crop }
  e.currentTarget.setPointerCapture(e.pointerId)
}

function onPointerMove(e) {
  if (!drag.mode) return
  const dx = e.clientX - drag.startX
  const dy = e.clientY - drag.startY
  const s = drag.start
  const minX = image.x
  const minY = image.y
  const maxX = image.x + image.w
  const maxY = image.y + image.h

  if (drag.mode === 'move') {
    crop.x = Math.min(Math.max(s.x + dx, minX), maxX - s.w)
    crop.y = Math.min(Math.max(s.y + dy, minY), maxY - s.h)
    return
  }

  let left = s.x
  let top = s.y
  let right = s.x + s.w
  let bottom = s.y + s.h
  const minW = Math.min(MIN_CROP_W, image.w)
  const minH = Math.min(MIN_CROP_H, image.h)
  if (drag.mode.includes('w')) left = Math.min(Math.max(s.x + dx, minX), right - minW)
  if (drag.mode.includes('e')) right = Math.max(Math.min(s.x + s.w + dx, maxX), left + minW)
  if (drag.mode.includes('n')) top = Math.min(Math.max(s.y + dy, minY), bottom - minH)
  if (drag.mode.includes('s')) bottom = Math.max(Math.min(s.y + s.h + dy, maxY), top + minH)
  Object.assign(crop, { x: left, y: top, w: right - left, h: bottom - top })
}

function onPointerUp() {
  drag.mode = ''
}

function handleStyle(handle) {
  const pos = {}
  pos.left = handle.includes('w') ? '0%' : handle.includes('e') ? '100%' : '50%'
  pos.top = handle.includes('n') ? '0%' : handle.includes('s') ? '100%' : '50%'
  const cursor = { n: 'ns', s: 'ns', e: 'ew', w: 'ew', ne: 'nesw', sw: 'nesw', nw: 'nwse', se: 'nwse' }[handle]
  return { ...pos, cursor: `${cursor}-resize` }
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
    // Stage px → natural image px.
    const toNatural = image.naturalWidth / image.w
    const sx = (crop.x - image.x) * toNatural
    const sy = (crop.y - image.y) * toNatural
    const sw = crop.w * toNatural
    const sh = crop.h * toNatural
    // Natural resolution, scaled down (never up) to fit MAX_OUT_W × MAX_OUT_H.
    const fit = Math.min(1, MAX_OUT_W / sw, MAX_OUT_H / sh)
    canvas.width = Math.max(1, Math.round(sw * fit))
    canvas.height = Math.max(1, Math.round(sh * fit))
    const ctx = canvas.getContext('2d')
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
            class="relative mx-auto rounded-2xl overflow-hidden bg-gray-100 border border-input-border touch-none select-none"
            :style="{ width: stageW + 'px', height: STAGE_H + 'px' }"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
          >
            <img
              ref="imageEl"
              :src="image.src"
              alt="Signature to crop"
              class="absolute max-w-none pointer-events-none"
              :style="{ left: image.x + 'px', top: image.y + 'px', width: image.w + 'px', height: image.h + 'px' }"
              @load="onImageLoad"
            />
            <!-- Crop box: drag inside to move, drag a handle to resize. The
                 big shadow dims everything outside it. -->
            <div
              v-if="state.status === 'crop' && crop.w"
              class="absolute border-2 border-brand-blue cursor-move"
              :style="{ left: crop.x + 'px', top: crop.y + 'px', width: crop.w + 'px', height: crop.h + 'px', boxShadow: '0 0 0 9999px rgba(0,0,0,0.45)' }"
              @pointerdown.stop="onPointerDown($event, 'move')"
            >
              <span
                v-for="handle in HANDLES"
                :key="handle"
                class="absolute w-3 h-3 -ml-1.5 -mt-1.5 rounded-sm bg-white border-2 border-brand-blue"
                :style="handleStyle(handle)"
                @pointerdown.stop="onPointerDown($event, handle)"
              ></span>
            </div>
            <div v-if="state.status === 'processing'" class="absolute inset-0 bg-black/50 flex items-center justify-center text-white text-sm font-medium">Saving&hellip;</div>
            <div v-if="state.status === 'done'" class="absolute inset-0 bg-green-600/55 flex items-center justify-center text-white text-lg font-bold">&check; Saved</div>
          </div>

          <p v-if="state.status === 'crop'" class="mt-3 text-[12px] text-muted text-center">Drag the box to move it, drag its corners or edges to resize it around your signature.</p>
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
