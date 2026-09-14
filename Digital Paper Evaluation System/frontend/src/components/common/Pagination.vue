<script setup>
import { computed } from 'vue'

const props = defineProps({
  currentPage: { type: Number, required: true },
  lastPage: { type: Number, required: true },
  total: { type: Number, default: 0 },
})
const emit = defineEmits(['change'])

// Windowed page-number list with "…" gaps — always shows page 1 and the
// last page, plus one sibling on each side of the current page. Small page
// counts (≤ 7) just list every page, no ellipsis needed.
const pageNumbers = computed(() => {
  const total = props.lastPage
  const current = props.currentPage

  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  const delta = 1
  const middle = []
  for (let i = Math.max(2, current - delta); i <= Math.min(total - 1, current + delta); i++) {
    middle.push(i)
  }

  const pages = [1]
  if (middle[0] > 2) pages.push('…')
  pages.push(...middle)
  if (middle[middle.length - 1] < total - 1) pages.push('…')
  pages.push(total)

  return pages
})

function go(page) {
  if (page < 1 || page > props.lastPage || page === props.currentPage) return
  emit('change', page)
}
</script>

<template>
  <div v-if="lastPage > 1" class="mt-6 flex flex-col items-center gap-2">
    <nav class="flex items-center justify-center gap-1.5 flex-wrap" aria-label="Pagination">
      <button
        type="button"
        class="px-4 h-9 rounded-full bg-white shadow-md text-[13px] text-muted disabled:opacity-40 disabled:cursor-not-allowed hover:text-brand-blue transition-colors"
        :disabled="currentPage <= 1"
        @click="go(currentPage - 1)"
      >
        &larr; Prev
      </button>

      <template v-for="(page, idx) in pageNumbers" :key="idx">
        <span v-if="page === '…'" class="px-1.5 text-[13px] text-muted select-none">&hellip;</span>
        <button
          v-else
          type="button"
          class="min-w-[36px] h-9 px-2.5 rounded-full text-[13px] font-medium transition-colors"
          :class="
            page === currentPage
              ? 'bg-btn-gradient text-white shadow-md'
              : 'bg-white text-muted shadow-sm hover:text-brand-blue'
          "
          :aria-current="page === currentPage ? 'page' : undefined"
          @click="go(page)"
        >
          {{ page }}
        </button>
      </template>

      <button
        type="button"
        class="px-4 h-9 rounded-full bg-white shadow-md text-[13px] text-muted disabled:opacity-40 disabled:cursor-not-allowed hover:text-brand-blue transition-colors"
        :disabled="currentPage >= lastPage"
        @click="go(currentPage + 1)"
      >
        Next &rarr;
      </button>
    </nav>
    <p class="text-[12px] text-muted">Page {{ currentPage }} of {{ lastPage }} &middot; {{ total }} total</p>
  </div>
</template>
