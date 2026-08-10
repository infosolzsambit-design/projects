import { defineStore } from 'pinia'
import api from '../utils/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('auth_token') || null,
    user: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
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

    async logout() {
      try {
        await api.post('/logout')
      } finally {
        this.clearSession()
      }
    },

    clearSession() {
      this.token = null
      this.user = null
      localStorage.removeItem('auth_token')
    },
  },
})
