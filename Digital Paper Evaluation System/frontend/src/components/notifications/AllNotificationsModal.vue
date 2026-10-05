<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useUserNotificationsStore } from '../../stores/userNotifications'
import { useToast } from '../../composables/useToast'
import Pagination from '../common/Pagination.vue'
import NotificationItem from './NotificationItem.vue'

// "View all" from the header bell: every notification, newest first,
// paged, with an All / Unread switch and Mark all as read.
const emit = defineEmits(['close', 'open'])

const store = useUserNotificationsStore()
const toast = useToast()

const filter = ref('all')
const items = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const failed = ref(false)
const markingAll = ref(false)

async function load(page = 1) {
  loading.value = true
  failed.value = false
  try {
    const data = await store.fetchPage(page, filter.value)
    items.value = data.items
    pagination.value = data.pagination
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function setFilter(value) {
  if (filter.value === value) return
  filter.value = value
  load(1)
}

async function markAll() {
  markingAll.value = true
  try {
    await store.markAllRead()
    toast.success('All notifications marked as read.')
    if (filter.value === 'unread') await load(1)
    else items.value.forEach((n) => (n.is_read = true))
  } catch {
    toast.error('Could not mark notifications as read. Please try again.')
  } finally {
    markingAll.value = false
  }
}

function onKey(event) {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => {
  load(1)
  document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
    <div class="w-full max-w-[640px] max-h-[88vh] flex flex-col rounded-[24px] bg-white shadow-panel overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="all-notifications-title">
      <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-soft">
        <div class="min-w-0">
          <h2 id="all-notifications-title" class="text-[18px] font-semibold text-gray-900">Notifications</h2>
          <p class="text-[12px] text-muted mt-0.5">
            <template v-if="store.unreadCount > 0">You have <span class="font-semibold text-brand">{{ store.unreadCount }}</span> unread notification{{ store.unreadCount === 1 ? '' : 's' }}</template>
            <template v-else>You're all caught up</template>
          </p>
        </div>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="emit('close')">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex items-center justify-between gap-3 px-5 py-2.5 border-b border-soft bg-gray-50/60">
        <div class="inline-flex p-0.5 rounded-lg bg-input-bg" role="tablist">
          <button
            v-for="tab in [{ key: 'all', label: 'All' }, { key: 'unread', label: 'Unread' }]"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="filter === tab.key"
            class="px-3.5 py-1.5 rounded-md text-[12px] font-medium transition-colors"
            :class="filter === tab.key ? 'bg-white text-brand-blue shadow-sm' : 'text-muted hover:text-gray-800'"
            @click="setFilter(tab.key)"
          >
            {{ tab.label }}
            <span v-if="tab.key === 'unread' && store.unreadCount > 0" class="ml-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-brand text-white text-[10px] font-semibold">
              {{ store.unreadCount > 99 ? '99+' : store.unreadCount }}
            </span>
          </button>
        </div>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 text-[12px] font-medium text-brand-blue hover:underline disabled:opacity-40 disabled:no-underline disabled:cursor-not-allowed"
          :disabled="store.unreadCount === 0 || markingAll"
          @click="markAll"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 6 7 17 2 12" /><polyline points="22 10 13 19 11.5 17.5" /></svg>
          Mark all as read
        </button>
      </div>

      <div class="flex-1 overflow-y-auto">
        <div v-if="loading" class="divide-y divide-soft">
          <div v-for="i in 5" :key="i" class="flex items-start gap-3 px-5 py-4 animate-pulse">
            <span class="w-9 h-9 rounded-full bg-gray-100 shrink-0"></span>
            <span class="flex-1 space-y-2 pt-1">
              <span class="block h-3 w-1/3 rounded bg-gray-100"></span>
              <span class="block h-3 w-4/5 rounded bg-gray-100"></span>
            </span>
          </div>
        </div>

        <div v-else-if="failed" class="py-14 px-6 text-center">
          <p class="text-[13px] text-muted">Could not load notifications.</p>
          <button type="button" class="mt-3 text-[12px] font-medium text-brand-blue hover:underline" @click="load(pagination.current_page)">Try again</button>
        </div>

        <div v-else-if="items.length === 0" class="py-14 px-6 flex flex-col items-center text-center">
          <span class="w-14 h-14 rounded-full bg-soft text-brand-blue flex items-center justify-center">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /></svg>
          </span>
          <p class="mt-3 text-sm font-semibold text-gray-800">{{ filter === 'unread' ? 'No unread notifications' : 'No notifications yet' }}</p>
          <p class="mt-1 text-[12px] text-muted">{{ filter === 'unread' ? "You're all caught up." : "We'll let you know when something needs your attention." }}</p>
        </div>

        <div v-else class="divide-y divide-soft">
          <NotificationItem v-for="n in items" :key="n.id" :notification="n" @open="emit('open', $event)" />
        </div>
      </div>

      <div v-if="!loading && pagination.last_page > 1" class="px-5 py-3 border-t border-soft">
        <Pagination :current-page="pagination.current_page" :last-page="pagination.last_page" :total="pagination.total" @change="load" />
      </div>
    </div>
  </div>
</template>
