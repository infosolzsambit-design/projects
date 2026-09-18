import { defineStore } from 'pinia'
import api from '../utils/api'

// The sidebar's own badge next to "Notifications" (see AppSidebar.vue) —
// shared app-wide since it's shown on every page, not just
// NotificationsView.vue itself. Backed by its own lightweight endpoint
// (GET /notifications/unresolved-count) rather than loading the full
// notifications list just to count it client-side.
export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    unresolvedCount: 0,
  }),
  actions: {
    async loadUnresolvedCount() {
      try {
        const res = await api.get('/notifications/unresolved-count')
        this.unresolvedCount = res.data.data.count
      } catch {
        // Non-fatal — the badge just stays at its last known value.
      }
    },
  },
})
