import { defineStore } from 'pinia'
import api from '../utils/api'

// Token theft is primarily stopped by IP/browser pinning on the backend
// (PinTokenToClient), not by a short expiration window — so this just keeps
// an active session's token rotating well inside the generous
// SANCTUM_TOKEN_EXPIRATION backstop (8h by default), not to force
// re-logins. Plain module-level handle, not reactive state — same pattern
// as the debounce/interval timers elsewhere in this app.
const AUTO_REFRESH_INTERVAL_MS = 60 * 60 * 1000
let refreshTimer = null

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('auth_token') || null,
    user: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,

    // UI-only show/hide check (no route/middleware gating reads this) —
    // e.g. v-if="authStore.can('admin-dashboard')". Backed by
    // `permission_names`, the effective (direct + role-derived) set the
    // backend computes in AuthController::withEffectivePermissions().
    can: (state) => (permissionName) => (state.user?.permission_names || []).includes(permissionName),
  },

  actions: {
    async login(login, password) {
      const response = await api.post('/login', { login, password })
      this.token = response.data.data.token
      this.user = response.data.data.user
      localStorage.setItem('auth_token', this.token)
    },

    async fetchMe() {
      const response = await api.get('/me')
      this.user = response.data.data
      return this.user
    },

    async refresh() {
      const response = await api.post('/refresh')
      this.token = response.data.data.token
      localStorage.setItem('auth_token', this.token)
    },

    startAutoRefresh() {
      this.stopAutoRefresh()
      refreshTimer = setInterval(() => {
        // A failed refresh (token already truly expired) falls through to
        // the 401 interceptor in utils/api.js, which redirects to /login.
        this.refresh().catch(() => {})
      }, AUTO_REFRESH_INTERVAL_MS)
    },

    stopAutoRefresh() {
      clearInterval(refreshTimer)
      refreshTimer = null
    },

    async logout() {
      try {
        await api.post('/logout')
      } finally {
        this.clearSession()
      }
    },

    clearSession() {
      this.stopAutoRefresh()
      this.token = null
      this.user = null
      localStorage.removeItem('auth_token')
    },
  },
})
