import axios from 'axios'

// Relative base URL — goes through Vite's dev proxy (see vite.config.js),
// which avoids the browser blocking HTTPS-page-calls-HTTP-backend as mixed
// content. In production, point this at wherever the API is actually served.
const api = axios.create({
  baseURL: '/api/v1',
  headers: {
    'X-API-KEY': import.meta.env.VITE_API_KEY,
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Centralized 401 handling: any expired/invalid token bounces to /login,
// instead of every call site needing to check for it individually.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  },
)

export default api
