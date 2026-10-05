<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

// Multi-select twin of SearchableSelect.vue — same look (input-bg box,
// search box, highlighted matches, opens upward near the footer), but
// v-model is an array of option ids. Picked options show as removable
// chips in the box; the list stays open while picking so several can be
// chosen in a row.
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] }, // [{ id, name }]
  placeholder: { type: String, default: 'Select' },
  searchPlaceholder: { type: String, default: 'Search…' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'change'])

const isOpen = ref(false)
const search = ref('')
const highlightedIndex = ref(-1)
const rootEl = ref(null)
const searchInputEl = ref(null)
const toggleEl = ref(null)
const dropUp = ref(false)

const selectedIds = computed(() => (props.modelValue || []).map(String))
const selectedOptions = computed(() => selectedIds.value.map((id) => props.options.find((o) => String(o.id) === id)).filter(Boolean))
const filteredOptions = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q ? props.options.filter((o) => String(o.name).toLowerCase().includes(q)) : props.options
})

function isSelected(option) {
  return selectedIds.value.includes(String(option.id))
}

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}
function highlight(name) {
  const safe = escapeHtml(name ?? '')
  const q = search.value.trim()
  if (!q) return safe
  const pattern = escapeHtml(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return safe.replace(new RegExp(pattern, 'gi'), (m) => `<mark class="bg-yellow-300 rounded-sm px-0.5">${m}</mark>`)
}

function emitValue(ids) {
  emit('update:modelValue', ids)
  emit('change', ids)
}
function toggleOption(option) {
  const id = option.id
  const next = isSelected(option) ? props.modelValue.filter((v) => String(v) !== String(id)) : [...(props.modelValue || []), id]
  emitValue(next)
}
function removeOption(option) {
  emitValue(props.modelValue.filter((v) => String(v) !== String(option.id)))
}

// Same rule as SearchableSelect: open upward when there isn't room below
// (e.g. under the page footer) and there's more room above.
function decideDirection() {
  const rect = rootEl.value?.getBoundingClientRect()
  if (!rect) return
  const footerTop = document.getElementById('site-footer')?.getBoundingClientRect().top ?? Infinity
  const spaceBelow = Math.min(window.innerHeight, footerTop) - rect.bottom
  dropUp.value = spaceBelow < 300 && rect.top > spaceBelow
}

async function open() {
  if (props.disabled || props.loading) return
  decideDirection()
  isOpen.value = true
  search.value = ''
  highlightedIndex.value = 0
  await nextTick()
  searchInputEl.value?.focus()
}
function close() {
  isOpen.value = false
  search.value = ''
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
    if (option) toggleOption(option)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    close()
    toggleEl.value?.focus()
  }
}
function scrollHighlightedIntoView() {
  nextTick(() => rootEl.value?.querySelector('[data-select-list]')?.children[highlightedIndex.value]?.scrollIntoView({ block: 'nearest' }))
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

// Parent's fieldRefs.xxx.value.focus() pattern (focus the first invalid field).
function focus() {
  toggleEl.value?.focus()
}
defineExpose({ focus })
</script>

<template>
  <div ref="rootEl" class="relative">
    <div
      ref="toggleEl"
      role="button"
      tabindex="0"
      :aria-disabled="disabled || loading"
      :aria-expanded="isOpen"
      class="w-full min-h-10 text-left outline-none border focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer relative rounded-xl bg-input-bg pl-2 pr-9 py-1.5 flex flex-wrap items-center gap-1.5"
      :class="[error ? 'border-brand' : 'border-input-border focus:border-brand-blue', disabled || loading ? 'opacity-60 cursor-not-allowed' : '']"
      @click="isOpen ? close() : open()"
      @keydown="onToggleKeydown"
    >
      <span v-if="loading" class="px-1 text-[13px] text-gray-400">Loading…</span>
      <span v-else-if="!selectedOptions.length" class="px-1 text-[13px] text-gray-400">{{ placeholder }}</span>
      <span
        v-for="option in selectedOptions"
        v-else
        :key="option.id"
        class="inline-flex items-center gap-1 max-w-full rounded-lg bg-white border border-soft pl-2 pr-1 py-0.5 text-[12px] text-gray-800 shadow-sm"
      >
        <span class="truncate">{{ option.name }}</span>
        <button
          type="button"
          class="w-4 h-4 shrink-0 rounded flex items-center justify-center text-gray-400 hover:text-brand hover:bg-brand/10"
          :aria-label="`Remove ${option.name}`"
          @click.stop="removeOption(option)"
        >
          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </span>
      <svg
        class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none transition-transform duration-150"
        :class="isOpen ? 'rotate-180' : ''"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <polyline points="6 9 12 15 18 9" />
      </svg>
    </div>

    <div
      v-if="isOpen"
      class="absolute left-0 right-0 bg-white rounded-xl shadow-panel border border-soft z-30 overflow-hidden"
      :class="dropUp ? 'bottom-full mb-1.5' : 'top-full mt-1.5'"
    >
      <div class="p-2 border-b border-soft">
        <input
          ref="searchInputEl"
          v-model="search"
          type="text"
          :placeholder="searchPlaceholder"
          class="w-full h-9 px-3 rounded-lg bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue transition"
          @keydown="onSearchKeydown"
        />
      </div>
      <ul data-select-list class="max-h-56 overflow-y-auto py-1" role="listbox" aria-multiselectable="true">
        <li v-if="!filteredOptions.length" class="px-3.5 py-2.5 text-[12px] text-muted">No results found.</li>
        <li
          v-for="(option, idx) in filteredOptions"
          :key="option.id"
          role="option"
          :aria-selected="isSelected(option)"
          class="flex items-center gap-2.5 px-3.5 py-2.5 text-[12px] cursor-pointer transition-colors"
          :class="[isSelected(option) ? 'text-brand-blue font-semibold' : 'text-gray-700', idx === highlightedIndex ? 'bg-soft' : 'hover:bg-soft']"
          @mouseenter="highlightedIndex = idx"
          @mousedown.prevent="toggleOption(option)"
        >
          <span
            class="w-4 h-4 shrink-0 rounded border flex items-center justify-center"
            :class="isSelected(option) ? 'bg-brand-blue border-brand-blue text-white' : 'border-input-border bg-white'"
          >
            <svg v-if="isSelected(option)" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
          </span>
          <span class="truncate" v-html="highlight(option.name)"></span>
        </li>
      </ul>
      <div v-if="selectedOptions.length" class="flex items-center justify-between px-3.5 py-2 border-t border-soft text-[11.5px]">
        <span class="text-muted">{{ selectedOptions.length }} selected</span>
        <button type="button" class="font-medium text-brand-blue hover:underline" @mousedown.prevent="close">Done</button>
      </div>
    </div>
  </div>
</template>
