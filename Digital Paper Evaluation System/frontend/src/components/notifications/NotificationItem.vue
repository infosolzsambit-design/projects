<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatDateTime } from '../../utils/date'

// One notification row — shared by the header bell's dropdown and the
// "View all" modal so both look and behave the same.
const props = defineProps({
  notification: { type: Object, required: true },
  compact: { type: Boolean, default: false },
})
defineEmits(['open'])

// Per notification type: icon + tint. Unknown types fall back to a
// neutral bell so a new backend type never breaks the list.
const TYPES = {
  answer_sheets_assigned: { tone: 'bg-brand-blue/10 text-brand-blue', icon: 'assigned' },
  answer_sheets_reassigned: { tone: 'bg-violet-100 text-violet-600', icon: 'reassigned' },
  issue_raised: { tone: 'bg-amber-100 text-amber-600', icon: 'issue' },
  issue_resolved: { tone: 'bg-success/15 text-green-600', icon: 'resolved' },
  evaluation_reset: { tone: 'bg-sky-100 text-sky-600', icon: 'reset' },
}
const meta = computed(() => TYPES[props.notification.type] || { tone: 'bg-gray-100 text-gray-500', icon: 'bell' })

// Relative time ("5 min ago") re-evaluated every minute while shown; the
// exact timestamp is on hover.
const now = ref(Date.now())
let tick = null
onMounted(() => (tick = setInterval(() => (now.value = Date.now()), 60_000)))
onBeforeUnmount(() => clearInterval(tick))

const createdAt = computed(() => (props.notification.created_at ? new Date(props.notification.created_at) : null))
const relative = computed(() => {
  if (!createdAt.value || Number.isNaN(createdAt.value.getTime())) return ''
  const seconds = Math.max(0, Math.floor((now.value - createdAt.value.getTime()) / 1000))
  if (seconds < 60) return 'Just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} hr${hours > 1 ? 's' : ''} ago`
  const days = Math.floor(hours / 24)
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days} days ago`
  return formatDateTime(props.notification.created_at).split(' ')[0]
})
const exact = computed(() => formatDateTime(props.notification.created_at, { fallback: '' }))
</script>

<template>
  <button
    type="button"
    class="group w-full flex items-start gap-3 text-left transition-colors"
    :class="[
      compact ? 'px-4 py-3' : 'px-4 sm:px-5 py-3.5',
      notification.is_read ? 'bg-white hover:bg-gray-50' : 'bg-soft/60 hover:bg-soft',
    ]"
    @click="$emit('open', notification)"
  >
    <span class="mt-0.5 w-9 h-9 rounded-full flex items-center justify-center shrink-0" :class="meta.tone" aria-hidden="true">
      <svg v-if="meta.icon === 'assigned'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="12" y1="18" x2="12" y2="12" /><line x1="9" y1="15" x2="15" y2="15" />
      </svg>
      <svg v-else-if="meta.icon === 'reassigned'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="17 1 21 5 17 9" /><path d="M3 11V9a4 4 0 0 1 4-4h14" /><polyline points="7 23 3 19 7 15" /><path d="M21 13v2a4 4 0 0 1-4 4H3" />
      </svg>
      <svg v-else-if="meta.icon === 'issue'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
      </svg>
      <svg v-else-if="meta.icon === 'resolved'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
      </svg>
      <svg v-else-if="meta.icon === 'reset'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
      </svg>
      <svg v-else class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
      </svg>
    </span>

    <span class="flex-1 min-w-0">
      <span class="flex items-start justify-between gap-2">
        <span class="text-[13px] leading-5" :class="notification.is_read ? 'font-medium text-gray-700' : 'font-semibold text-gray-900'">
          {{ notification.title }}
        </span>
        <span class="text-[11px] leading-5 text-label whitespace-nowrap shrink-0" :title="exact">{{ relative }}</span>
      </span>
      <span class="block mt-0.5 text-[12px] leading-[18px] text-muted" :class="compact ? 'line-clamp-2' : ''">
        {{ notification.message }}
      </span>
    </span>

    <span class="mt-2 w-2 h-2 rounded-full shrink-0" :class="notification.is_read ? 'bg-transparent' : 'bg-brand'" :aria-label="notification.is_read ? undefined : 'Unread'"></span>
  </button>
</template>
