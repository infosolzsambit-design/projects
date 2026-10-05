<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useUserNotificationsStore } from '../../stores/userNotifications'
import { useToast } from '../../composables/useToast'
import NotificationItem from './NotificationItem.vue'
import AllNotificationsModal from './AllNotificationsModal.vue'

// Header bell: unread badge (polled, see stores/userNotifications.js),
// a dropdown of the latest few, and "View all" for the full paged list.
// Opening a notification marks it read and follows its link.
const store = useUserNotificationsStore()
const router = useRouter()
const toast = useToast()

const open = ref(false)
const root = ref(null)
const showAll = ref(false)
const markingAll = ref(false)

// Lets AppHeader lift itself above page content while the dropdown is open.
const emit = defineEmits(['open-change'])
watch(open, (value) => emit('open-change', value))

function toggle() {
  open.value = !open.value
  // Always refresh on open; a cached list (if any) stays visible meanwhile.
  if (open.value) store.loadRecent()
}

function openNotification(notification) {
  store.markRead(notification)
  open.value = false
  showAll.value = false
  if (notification.link && router.currentRoute.value.path !== notification.link) {
    router.push(notification.link)
  }
}

async function markAll() {
  markingAll.value = true
  try {
    await store.markAllRead()
  } catch {
    toast.error('Could not mark notifications as read. Please try again.')
  } finally {
    markingAll.value = false
  }
}

function viewAll() {
  open.value = false
  showAll.value = true
}

function onDocumentClick(event) {
  if (root.value && !root.value.contains(event.target)) open.value = false
}
function onKey(event) {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => {
  store.startPolling()
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  store.stopPolling()
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="relative w-9 h-9 sm:w-10 sm:h-10 shrink-0 rounded-full flex items-center justify-center text-gray-500 hover:bg-gray-100 hover:text-brand-blue transition-colors"
      :class="{ 'bg-soft text-brand-blue': open }"
      :aria-label="store.unreadCount > 0 ? `Notifications, ${store.unreadCount} unread` : 'Notifications'"
      :aria-expanded="open"
      aria-haspopup="true"
      aria-controls="notification-menu"
      @click="toggle"
    >
      <svg class="w-[22px] h-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
      </svg>
      <span
        v-if="store.unreadCount > 0"
        class="absolute top-0.5 right-0 min-w-[18px] h-[18px] px-1 rounded-full bg-brand text-white text-[10px] font-semibold leading-[18px] text-center ring-2 ring-white"
      >
        {{ store.unreadCount > 99 ? '99+' : store.unreadCount }}
      </span>
    </button>

    <div
      v-show="open"
      id="notification-menu"
      class="fixed left-3 right-3 top-[60px] sm:absolute sm:left-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[380px] bg-white rounded-2xl shadow-panel border border-soft z-30 overflow-hidden"
      role="menu"
    >
      <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-soft">
        <div class="flex items-center gap-2">
          <h3 class="text-[14px] font-semibold text-gray-900">Notifications</h3>
          <span v-if="store.unreadCount > 0" class="px-2 py-0.5 rounded-full bg-brand/10 text-brand text-[11px] font-semibold">{{ store.unreadCount }} new</span>
        </div>
        <button
          v-if="store.unreadCount > 0"
          type="button"
          class="text-[12px] font-medium text-brand-blue hover:underline disabled:opacity-50"
          :disabled="markingAll"
          @click="markAll"
        >
          Mark all as read
        </button>
      </div>

      <div class="max-h-[min(420px,60vh)] overflow-y-auto">
        <div v-if="store.recentLoading && !store.recentLoaded" class="divide-y divide-soft">
          <div v-for="i in 3" :key="i" class="flex items-start gap-3 px-4 py-3 animate-pulse">
            <span class="w-9 h-9 rounded-full bg-gray-100 shrink-0"></span>
            <span class="flex-1 space-y-2 pt-1">
              <span class="block h-3 w-1/2 rounded bg-gray-100"></span>
              <span class="block h-3 w-4/5 rounded bg-gray-100"></span>
            </span>
          </div>
        </div>

        <div v-else-if="store.recent.length === 0" class="py-10 px-6 flex flex-col items-center text-center">
          <span class="w-12 h-12 rounded-full bg-soft text-brand-blue flex items-center justify-center">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /></svg>
          </span>
          <p class="mt-3 text-[13px] font-semibold text-gray-800">No notifications yet</p>
          <p class="mt-1 text-[12px] text-muted">We'll let you know when something needs your attention.</p>
        </div>

        <div v-else class="divide-y divide-soft">
          <NotificationItem v-for="n in store.recent" :key="n.id" :notification="n" compact @open="openNotification" />
        </div>
      </div>

      <button
        v-if="store.recentTotal > 0"
        type="button"
        class="w-full flex items-center justify-center gap-1.5 px-4 py-3 border-t border-soft text-[13px] font-medium text-brand-blue hover:bg-soft transition-colors"
        @click="viewAll"
      >
        View all notifications
        <span v-if="store.hasMore" class="text-muted font-normal">({{ store.recentTotal }})</span>
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
      </button>
    </div>

    <!-- Teleported: the header is its own stacking context, so a modal left
         inside it could be covered by page elements with a higher z-index. -->
    <Teleport to="body">
      <AllNotificationsModal v-if="showAll" @close="showAll = false" @open="openNotification" />
    </Teleport>
  </div>
</template>
