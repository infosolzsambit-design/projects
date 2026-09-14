<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

// Native Vue equivalent of designed_files/form.html's "Date of Birth
// (Datepicker)" field — same look (readonly text field, calendar icon,
// click to open a popup calendar) as that jQuery UI datepicker, without
// pulling jQuery into a Vue-managed page (same reasoning as
// SearchableSelect.vue's own docblock).
//
// v-model stays a plain ISO 'YYYY-MM-DD' string (or '') — what every date
// column in this app already round-trips as — but always *displays* as
// dd-mm-yyyy, the format this whole project shows dates in.
//
// With `with-time`, v-model instead round-trips as 'YYYY-MM-DD HH:mm' (a
// plain space, zero-padded — sorts/compares correctly as a string, and
// Laravel's `date`/`after_or_equal` rules parse it as-is) and the popup
// grows a native <input type="time"> under the calendar so a hour:minute
// can be picked alongside the day (see AssignTeacherView.vue's evaluation
// start/end fields for the only current usage).
const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Select date' },
  // ISO 'YYYY-MM-DD' (or, with with-time, 'YYYY-MM-DD HH:mm' — only the
  // date portion is used for disabling calendar days; a min *time* on the
  // same day is never enforced by the calendar itself) — days before this
  // are shown but disabled, same purpose as a native <input type="date"
  // min="…">.
  min: { type: String, default: '' },
  withTime: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  dense: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'change'])

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

function parseIso(iso) {
  if (!iso) return null
  const [y, m, d] = iso.split(' ')[0].split('-').map(Number)
  if (!y || !m || !d) return null
  return { y, m, d }
}
// 'HH:mm' out of a 'YYYY-MM-DD HH:mm' value, or null if there's no time
// portion (a date-only value, or nothing picked yet).
function parseTime(iso) {
  const time = iso?.split(' ')[1]
  return time ? time.slice(0, 5) : null
}
function pad2(n) {
  return String(n).padStart(2, '0')
}
function toIso(y, m, d) {
  return `${y}-${pad2(m)}-${pad2(d)}`
}

const selected = computed(() => parseIso(props.modelValue))
const displayText = computed(() => {
  const s = selected.value
  if (!s) return ''
  const datePart = `${pad2(s.d)}-${pad2(s.m)}-${s.y}`
  return props.withTime ? `${datePart} ${parseTime(props.modelValue) || '00:00'}` : datePart
})
const minDate = computed(() => parseIso(props.min))
// Only the date portion of `min` — a same-day `min` (the end picker's own
// :min bound to the start picker's value, once that has a time on it)
// must never disable that whole day, only days strictly before it. Any
// same-day time ordering is enforced by the page's own submit-time
// validation instead (see AssignTeacherView.vue), not the calendar grid.
const minDateOnly = computed(() => props.min?.split(' ')[0] || '')

// Two-way binding straight onto modelValue's own time portion — so the
// <input type="time"> below can just `v-model` this directly. Setting it
// before a day has been picked is a no-op (there's no date yet to attach
// a time to).
const timeInputValue = computed({
  get: () => parseTime(props.modelValue) || '00:00',
  set(value) {
    if (!selected.value) return
    const combined = `${toIso(selected.value.y, selected.value.m, selected.value.d)} ${value || '00:00'}`
    emit('update:modelValue', combined)
    emit('change', combined)
  },
})

const isOpen = ref(false)
const rootEl = ref(null)
const toggleButtonEl = ref(null)
const popupEl = ref(null)
// Whether the popup renders above the toggle button instead of below it —
// recomputed against the real viewport every time it opens (and on
// scroll/resize while open) so a field near the bottom of the page opens
// upward instead of the calendar getting clipped/hidden behind whatever's
// below it (e.g. AssignTeacherView.vue's Evaluation Start/End fields,
// which sit just above the page footer).
const dropUp = ref(false)
// Hides the popup for the one frame between it mounting (at its default
// downward position) and updatePosition() measuring + correcting that —
// otherwise a field that needs to flip upward would visibly flash open
// downward first.
const positioned = ref(false)

const today = new Date()
const viewYear = ref(today.getFullYear())
const viewMonth = ref(today.getMonth() + 1) // 1-12

function openViewAt(iso) {
  const parsed = parseIso(iso) || selected.value
  if (parsed) {
    viewYear.value = parsed.y
    viewMonth.value = parsed.m
  } else {
    viewYear.value = today.getFullYear()
    viewMonth.value = today.getMonth() + 1
  }
}

// Flips the popup to open upward when there isn't enough room below the
// toggle button in the viewport but there is enough above it — otherwise
// leaves it opening downward (the natural default) even if that means
// some scrolling, since flipping when there's *more* room below than
// above would be a worse trade.
function updatePosition() {
  const buttonRect = toggleButtonEl.value?.getBoundingClientRect()
  const popupHeight = popupEl.value?.offsetHeight
  if (!buttonRect || !popupHeight) return
  const spaceBelow = window.innerHeight - buttonRect.bottom
  const spaceAbove = buttonRect.top
  const gap = 8 // the mt-1.5/mb-1.5 gap, in px, plus a little breathing room
  dropUp.value = spaceBelow < popupHeight + gap && spaceAbove > spaceBelow
  positioned.value = true
}

async function open() {
  if (props.disabled) return
  openViewAt(props.modelValue)
  positioned.value = false
  isOpen.value = true
  await nextTick()
  updatePosition()
  window.addEventListener('scroll', updatePosition, true)
  window.addEventListener('resize', updatePosition)
}
function close() {
  isOpen.value = false
  window.removeEventListener('scroll', updatePosition, true)
  window.removeEventListener('resize', updatePosition)
}
function toggle() {
  isOpen.value ? close() : open()
}

function prevMonth() {
  if (viewMonth.value === 1) {
    viewMonth.value = 12
    viewYear.value -= 1
  } else {
    viewMonth.value -= 1
  }
}
function nextMonth() {
  if (viewMonth.value === 12) {
    viewMonth.value = 1
    viewYear.value += 1
  } else {
    viewMonth.value += 1
  }
}

function daysInMonth(y, m) {
  return new Date(y, m, 0).getDate()
}

// One flat list of {day, iso, inMonth, disabled} cells covering the whole
// visible 6-week grid — leading/trailing cells borrow from the adjacent
// month so the grid never has a ragged first/last row.
const calendarCells = computed(() => {
  const y = viewYear.value
  const m = viewMonth.value
  const firstWeekday = new Date(y, m - 1, 1).getDay() // 0=Sun
  const total = daysInMonth(y, m)
  const prevTotal = daysInMonth(m === 1 ? y - 1 : y, m === 1 ? 12 : m - 1)

  const cells = []
  for (let i = 0; i < firstWeekday; i++) {
    const d = prevTotal - firstWeekday + 1 + i
    const py = m === 1 ? y - 1 : y
    const pm = m === 1 ? 12 : m - 1
    cells.push({ day: d, iso: toIso(py, pm, d), inMonth: false })
  }
  for (let d = 1; d <= total; d++) {
    cells.push({ day: d, iso: toIso(y, m, d), inMonth: true })
  }
  let next = 1
  while (cells.length % 7 !== 0 || cells.length < 42) {
    const ny = m === 12 ? y + 1 : y
    const nm = m === 12 ? 1 : m + 1
    cells.push({ day: next, iso: toIso(ny, nm, next), inMonth: false })
    next++
    if (cells.length >= 42) break
  }
  return cells.map((c) => ({ ...c, disabled: minDateOnly.value ? c.iso < minDateOnly.value : false }))
})

// Combines a picked day with whatever time is currently set (defaulting
// to 00:00 the first time a day's ever picked on a with-time field).
function withCurrentTime(iso) {
  return props.withTime ? `${iso} ${parseTime(props.modelValue) || '00:00'}` : iso
}

function selectDay(cell) {
  if (cell.disabled) return
  const combined = withCurrentTime(cell.iso)
  emit('update:modelValue', combined)
  emit('change', combined)
  // With a time to pick too, keep the popup open so that's not a second
  // separate click to reopen it — closes on an outside click instead (see
  // onDocumentClick below), or the explicit Done button.
  if (!props.withTime) {
    close()
    toggleButtonEl.value?.focus()
  }
}

function goToday() {
  const iso = toIso(today.getFullYear(), today.getMonth() + 1, today.getDate())
  if (minDateOnly.value && iso < minDateOnly.value) return
  const combined = withCurrentTime(iso)
  emit('update:modelValue', combined)
  emit('change', combined)
  if (!props.withTime) {
    close()
    toggleButtonEl.value?.focus()
  }
}

function clear() {
  emit('update:modelValue', '')
  emit('change', '')
  close()
  toggleButtonEl.value?.focus()
}

function onToggleKeydown(e) {
  if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
    e.preventDefault()
    open()
  } else if (e.key === 'Escape') {
    close()
  }
}

function onDocumentClick(e) {
  if (isOpen.value && rootEl.value && !rootEl.value.contains(e.target)) close()
}
onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('scroll', updatePosition, true)
  window.removeEventListener('resize', updatePosition)
})

// Lets the same fieldRefs.xxx.value.focus() pattern used everywhere else
// in this app for focusing the first invalid field keep working
// unmodified — focusing this component focuses its toggle button.
function focus() {
  toggleButtonEl.value?.focus()
}
defineExpose({ focus })
</script>

<template>
  <div ref="rootEl" class="relative">
    <button
      ref="toggleButtonEl"
      type="button"
      :disabled="disabled"
      class="w-full text-left outline-none border focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-60 relative rounded-xl bg-input-bg"
      :class="[error ? 'border-brand' : 'border-input-border focus:border-brand-blue', dense ? 'h-8 pl-2.5 pr-8 text-[11px]' : 'h-10 pl-3 pr-9 text-[13px]']"
      @click="toggle"
      @keydown="onToggleKeydown"
    >
      <span class="block truncate" :class="displayText ? 'text-gray-800' : 'text-gray-400'">{{ displayText || placeholder }}</span>
      <svg
        class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-gray-400"
        :class="dense ? 'right-2.5 w-3.5 h-3.5' : 'right-3 w-4 h-4'"
        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      >
        <rect x="3" y="4" width="18" height="18" rx="2" />
        <path d="M16 2v4M8 2v4M3 10h18" />
      </svg>
    </button>

    <div
      v-if="isOpen"
      ref="popupEl"
      class="absolute left-0 bg-white rounded-xl shadow-panel border border-soft z-[60] p-3 w-[260px]"
      :class="[dropUp ? 'bottom-full mb-1.5' : 'top-full mt-1.5', positioned ? '' : 'invisible']"
    >
      <div class="flex items-center justify-between mb-2">
        <button type="button" class="w-7 h-7 rounded-lg hover:bg-soft text-gray-500 hover:text-brand-blue flex items-center justify-center transition-colors" aria-label="Previous month" @click="prevMonth">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6" /></svg>
        </button>
        <span class="text-[13px] font-semibold text-gray-900">{{ MONTHS[viewMonth - 1] }} {{ viewYear }}</span>
        <button type="button" class="w-7 h-7 rounded-lg hover:bg-soft text-gray-500 hover:text-brand-blue flex items-center justify-center transition-colors" aria-label="Next month" @click="nextMonth">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
        </button>
      </div>

      <div class="grid grid-cols-7 gap-y-1 text-center">
        <span v-for="wd in WEEKDAYS" :key="wd" class="text-[10.5px] font-semibold text-muted py-1">{{ wd }}</span>
        <button
          v-for="cell in calendarCells"
          :key="cell.iso"
          type="button"
          :disabled="cell.disabled"
          class="h-7 w-7 mx-auto rounded-lg text-[12px] transition-colors"
          :class="[
            !cell.inMonth ? 'text-gray-300' : 'text-gray-700',
            cell.disabled ? 'cursor-not-allowed opacity-40' : 'hover:bg-soft cursor-pointer',
            selected && cell.iso === toIso(selected.y, selected.m, selected.d) ? 'bg-brand-blue text-white font-semibold hover:bg-brand-blue' : '',
          ]"
          @click="selectDay(cell)"
        >
          {{ cell.day }}
        </button>
      </div>

      <div v-if="withTime" class="flex items-center gap-2 mt-2.5 pt-2.5 border-t border-soft">
        <svg class="w-3.5 h-3.5 text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
        <input
          type="time"
          v-model="timeInputValue"
          :disabled="!selected"
          class="flex-1 h-7 px-2 rounded-lg bg-input-bg text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue transition disabled:opacity-50 disabled:cursor-not-allowed"
        />
      </div>

      <div class="flex items-center justify-between mt-2 pt-2 border-t border-soft">
        <button type="button" class="text-[11.5px] font-medium text-brand-blue hover:underline" @click="goToday">Today</button>
        <div class="flex items-center gap-3">
          <button v-if="modelValue" type="button" class="text-[11.5px] font-medium text-muted hover:text-brand" @click="clear">Clear</button>
          <button v-if="withTime" type="button" class="text-[11.5px] font-medium text-brand-blue hover:underline" @click="close">Done</button>
        </div>
      </div>
    </div>
  </div>
</template>
