import { defineStore } from 'pinia'
import api from '../utils/api'

// The header bell's in-app notifications (GET /user-notifications) —
// not to be confused with stores/notifications.js, which is the sidebar's
// "Problems" unresolved-issue badge. Every call here is a background call
// (skipLoader) so the 60s poll never flashes the global loader.
const RECENT_LIMIT = 8
const POLL_MS = 60_000

let pollTimer = null
let onFocus = null

export const useUserNotificationsStore = defineStore('userNotifications', {
  state: () => ({
    unreadCount: 0,
    recent: [],
    recentTotal: 0,
    recentLoaded: false,
    recentLoading: false,
  }),
  getters: {
    hasMore: (state) => state.recentTotal > state.recent.length,
  },
  actions: {
    async loadUnreadCount() {
      try {
        const res = await api.get('/user-notifications/unread-count', { skipLoader: true })
        const count = res.data.data.unread_count
        // Something new arrived since the dropdown was last loaded — make
        // sure the next open shows it rather than the stale list.
        if (count !== this.unreadCount) this.recentLoaded = false
        this.unreadCount = count
      } catch {
        // Non-fatal — the badge just keeps its last known value.
      }
    },

    async loadRecent() {
      this.recentLoading = true
      try {
        const res = await api.get('/user-notifications', { params: { per_page: RECENT_LIMIT }, skipLoader: true })
        const data = res.data.data
        this.recent = data.items
        this.recentTotal = data.pagination.total
        this.unreadCount = data.unread_count
        this.recentLoaded = true
      } catch {
        // Leave whatever was showing; the dropdown shows its own empty state.
      } finally {
        this.recentLoading = false
      }
    },

    /** One page for the "View all" modal. filter: 'all' | 'unread'. */
    async fetchPage(page = 1, filter = 'all', perPage = 10) {
      const params = { page, per_page: perPage }
      if (filter === 'unread') params.filter = 'unread'
      const res = await api.get('/user-notifications', { params, skipLoader: true })
      this.unreadCount = res.data.data.unread_count
      return res.data.data
    },

    async markRead(notification) {
      if (!notification || notification.is_read) return
      notification.is_read = true
      const inRecent = this.recent.find((n) => n.id === notification.id)
      if (inRecent) inRecent.is_read = true
      this.unreadCount = Math.max(0, this.unreadCount - 1)
      try {
        const res = await api.post(`/user-notifications/${notification.id}/read`, null, { skipLoader: true })
        this.unreadCount = res.data.data.unread_count
      } catch {
        // Optimistic update stays; the next poll corrects the count if needed.
      }
    },

    async markAllRead() {
      const res = await api.post('/user-notifications/read-all', null, { skipLoader: true })
      this.recent.forEach((n) => (n.is_read = true))
      this.unreadCount = res.data.data.unread_count
    },

    startPolling() {
      this.stopPolling()
      this.loadUnreadCount()
      pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') this.loadUnreadCount()
      }, POLL_MS)
      // Coming back to the tab refreshes straight away instead of waiting
      // for the next tick.
      onFocus = () => {
        if (document.visibilityState === 'visible') this.loadUnreadCount()
      }
      window.addEventListener('focus', onFocus)
      document.addEventListener('visibilitychange', onFocus)
    },

    stopPolling() {
      if (pollTimer) clearInterval(pollTimer)
      pollTimer = null
      if (onFocus) {
        window.removeEventListener('focus', onFocus)
        document.removeEventListener('visibilitychange', onFocus)
      }
      onFocus = null
    },
  },
})
