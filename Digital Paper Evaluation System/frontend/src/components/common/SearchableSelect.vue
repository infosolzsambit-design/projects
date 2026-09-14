<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

// Native Vue equivalent of the jQuery Select2 widget shown in
// designed_files/form.html's "Subjects (Select2 Multiselect)" field —
// same look/feel (click to open, type to search, click to pick) without
// pulling jQuery into a Vue-managed page. See TeacherFormView.vue's
// Department field for the current use.
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  // Each option needs an `id` and a `name` — matches the shape already
  // returned by the master-data list endpoints (departments, courses, …),
  // so callers can pass that array straight through with no mapping.
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Select an option' },
  searchPlaceholder: { type: String, default: 'Search…' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  // Compact size for tight layouts (e.g. one per row in the Bulk Upload
  // review table) — shorter toggle, smaller text, tighter list rows.
  // Default (false) is unchanged for the Teacher/Program forms.
  dense: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'change'])

const isOpen = ref(false)
const search = ref('')
const highlightedIndex = ref(-1)
const rootEl = ref(null)
const searchInputEl = ref(null)
const toggleButtonEl = ref(null)

const selectedOption = computed(() => props.options.find((o) => String(o.id) === String(props.modelValue)) || null)

const filteredOptions = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) => o.name.toLowerCase().includes(q))
})

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}
function escapeRegExp(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
function highlight(name) {
  const safe = escapeHtml(name)
  const q = search.value.trim()
  if (!q) return safe
  return safe.replace(new RegExp(escapeRegExp(escapeHtml(q)), 'gi'), (m) => `<mark class="bg-yellow-300 rounded-sm px-0.5">${m}</mark>`)
}

async function open() {
  if (props.disabled || props.loading) return
  isOpen.value = true
  search.value = ''
  highlightedIndex.value = Math.max(
    0,
    filteredOptions.value.findIndex((o) => String(o.id) === String(props.modelValue)),
  )
  await nextTick()
  searchInputEl.value?.focus()
}

function close() {
  isOpen.value = false
  search.value = ''
}

function select(option) {
  emit('update:modelValue', option.id)
  emit('change', option.id)
  close()
  toggleButtonEl.value?.focus()
}

function onSearchKeydown(e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    highlightedIndex.value = Math.min(highlightedIndex.value + 1, filteredOptions.value.length - 1)
    scrollHighlightedIntoView()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
    scrollHighlightedIntoView()
  } else if (e.key === 'Enter') {
    e.preventDefault()
    const option = filteredOptions.value[highlightedIndex.value]
    if (option) select(option)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    close()
    toggleButtonEl.value?.focus()
  }
}

function scrollHighlightedIntoView() {
  nextTick(() => {
    const list = rootEl.value?.querySelector('[data-select-list]')
    const el = list?.children[highlightedIndex.value]
    el?.scrollIntoView({ block: 'nearest' })
  })
}

function onDocumentClick(e) {
  if (isOpen.value && rootEl.value && !rootEl.value.contains(e.target)) close()
}
onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

function onToggleKeydown(e) {
  if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
    e.preventDefault()
    open()
  }
}

// Lets the parent's fieldRefs.xxx.value.focus() pattern (used everywhere
// else in this app for focusing the first invalid field) keep working
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
      :disabled="disabled || loading"
      class="w-full text-left outline-none border focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-60 relative rounded-xl bg-input-bg"
      :class="[error ? 'border-brand' : 'border-input-border focus:border-brand-blue', dense ? 'h-8 pl-2.5 pr-7 text-[11px]' : 'h-10 pl-3 pr-9 text-[13px]']"
      @click="isOpen ? close() : open()"
      @keydown="onToggleKeydown"
    >
      <span class="block truncate" :class="selectedOption ? 'text-gray-800' : 'text-gray-400'">
        {{ loading ? 'Loading…' : selectedOption ? selectedOption.name : placeholder }}
      </span>
      <svg
        class="absolute top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none transition-transform duration-150"
        :class="[isOpen ? 'rotate-180' : '', dense ? 'right-2.5 w-3.5 h-3.5' : 'right-3.5 w-4 h-4']"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <polyline points="6 9 12 15 18 9" />
      </svg>
    </button>

    <div
      v-if="isOpen"
      class="absolute left-0 right-0 top-full mt-1.5 bg-white rounded-xl shadow-panel border border-soft z-30 overflow-hidden"
    >
      <div class="p-2 border-b border-soft">
        <input
          ref="searchInputEl"
          v-model="search"
          type="text"
          :placeholder="searchPlaceholder"
          class="w-full rounded-lg bg-input-bg text-gray-800 outline-none border border-input-border focus:border-brand-blue transition"
          :class="dense ? 'h-8 px-2.5 text-[11px]' : 'h-9 px-3 text-[13px]'"
          @keydown="onSearchKeydown"
        />
      </div>
      <ul data-select-list class="max-h-56 overflow-y-auto py-1">
        <li v-if="!filteredOptions.length" class="text-muted" :class="dense ? 'px-2.5 py-1.5 text-[11px]' : 'px-3.5 py-2.5 text-[12px]'">No results found.</li>
        <li
          v-for="(option, idx) in filteredOptions"
          :key="option.id"
          class="cursor-pointer transition-colors truncate"
          :class="[
            dense ? 'px-2.5 py-1.5 text-[11px]' : 'px-3.5 py-2.5 text-[12px]',
            String(option.id) === String(modelValue) ? 'text-brand-blue font-semibold' : 'text-gray-700',
            idx === highlightedIndex ? 'bg-soft' : 'hover:bg-soft',
          ]"
          @mouseenter="highlightedIndex = idx"
          @mousedown.prevent="select(option)"
        >
          <span v-html="highlight(option.name)"></span>
        </li>
      </ul>
    </div>
  </div>
</template>
