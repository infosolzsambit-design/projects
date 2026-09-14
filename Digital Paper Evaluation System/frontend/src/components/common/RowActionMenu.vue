<script setup>
// Self-contained "⋮" row-action dropdown for list tables (Courses,
// Departments, Programs, Students, Teachers, Question Papers, …).
//
// Why this exists instead of the old per-view `absolute` + `v-show` div:
// every list table sits inside a `overflow-x-auto` wrapper (for horizontal
// scroll on narrow screens) nested inside a `overflow-hidden` section (for
// its own rounded corners). The moment an ancestor sets overflow to
// anything but `visible` on either axis, browsers clip content on BOTH
// axes for that ancestor — so an `absolute`-positioned dropdown anchored
// inside a table cell gets cut off by the row above/below it, no matter
// how high its z-index is. z-index only orders elements that are already
// visible in the same stacking context; it can't rescue an element from
// being clipped by an ancestor's overflow. The only reliable fix is to
// stop rendering the dropdown *inside* that clipped DOM subtree at all —
// hence the Teleport to <body> here, with its position computed from the
// trigger button's own bounding box instead of relying on `absolute`.
import { nextTick, onBeforeUnmount, ref } from 'vue'

defineProps({
  // Matches each view's own dropdown width today (Courses/Departments/
  // Programs/Students/Teachers use w-36; Question Papers uses w-44).
  width: { type: String, default: 'w-36' },
})

const open = ref(false)
const triggerEl = ref(null)
const menuStyle = ref({})

function updatePosition() {
  const rect = triggerEl.value.getBoundingClientRect()
  menuStyle.value = {
    top: `${rect.bottom + 4}px`,
    right: `${window.innerWidth - rect.right}px`,
  }
}

function onOutsideClick(event) {
  if (triggerEl.value?.contains(event.target)) return
  close()
}

async function toggle() {
  if (open.value) {
    close()
    return
  }
  open.value = true
  await nextTick()
  updatePosition()
  // A scroll (the page, or the table's own horizontal scroll container)
  // would leave a `fixed`-position menu no longer lined up with its
  // trigger button — closing is simpler and safer than re-tracking it.
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
  document.addEventListener('click', onOutsideClick)
}

function close() {
  if (!open.value) return
  open.value = false
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
  document.removeEventListener('click', onOutsideClick)
}

onBeforeUnmount(close)
</script>

<template>
  <button
    ref="triggerEl"
    type="button"
    class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-500 hover:text-gray-800 transition-colors inline-flex items-center justify-center"
    aria-label="Open actions"
    :aria-expanded="open"
    @click.stop="toggle"
  >
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
      <circle cx="12" cy="5" r="1.75" />
      <circle cx="12" cy="12" r="1.75" />
      <circle cx="12" cy="19" r="1.75" />
    </svg>
  </button>
  <Teleport to="body">
    <div
      v-if="open"
      :style="menuStyle"
      class="fixed bg-white rounded-xl shadow-panel border border-soft py-1.5 z-50 text-left"
      :class="width"
      @click="close"
    >
      <slot />
    </div>
  </Teleport>
</template>
