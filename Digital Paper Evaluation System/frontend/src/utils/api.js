import axios from 'axios'
import { useLoading } from '../composables/useLoading'

const { startRequest, endRequest } = useLoading()

// Dev: relative '/api/v1' goes through Vite's dev proxy (see
// vite.config.js), which avoids the browser blocking HTTPS-page-calls-HTTP-
// backend as mixed content. Production build: the frontend and backend are
// two separate domains (no dev server, no proxy to ride on), so this needs
// an absolute URL instead — VITE_API_BASE_URL, baked in at `npm run build`
// time, e.g. "https://digipaperchkapi.campusjadugar.com/api/v1". Falling
// back to the relative path keeps local dev working unchanged when that
// var isn't set.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: {
    'X-API-KEY': import.meta.env.VITE_API_KEY,
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  // Every single call through this instance — no per-call opt-in — shows
  // the app-wide blocking overlay (see GlobalLoader.vue / useLoading.js).
  // Opt out per-call with `{ skipLoader: true }` in the request config for
  // the rare case a call genuinely shouldn't block the UI (nothing does
  // today, but the escape hatch costs nothing to have ready).
  if (!config.skipLoader) startRequest()
  return config
})

// Centralized 401 handling: any expired/invalid token bounces to /login,
// instead of every call site needing to check for it individually.
api.interceptors.response.use(
  (response) => {
    if (!response.config?.skipLoader) endRequest()
    return response
  },
  (error) => {
    if (!error.config?.skipLoader) endRequest()
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  },
)

// The API returns *uploaded* file paths (question paper PDFs, a custom
// branding logo someone actually uploaded) as origin-relative "/storage/…"
// paths, meant to resolve against wherever the backend actually serves
// them from. In dev that's the same origin the page loads from (Vite's own
// /storage proxy — see vite.config.js); in production the backend is a
// separate domain, so a relative path would silently resolve against the
// *frontend's* own origin instead and 404. Reuses the same
// VITE_API_BASE_URL as the API client above (stripping the "/api/v1"
// suffix back off) rather than needing a second env var, since both point
// at the same backend origin.
//
// Only ever rewrites "/storage/…" specifically — branding fields can also
// legitimately hold a "/images/…" path (see GeneralSettingSeeder's
// defaults, deliberately pointing at frontend/public/images/ instead of an
// uploaded file), and those must stay resolved against the *frontend's*
// own origin, never the backend's.
const backendOrigin = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/api\/v1\/?$/, '')

export function resolveStorageUrl(path) {
  if (!path || !backendOrigin || !path.startsWith('/storage/')) return path
  return backendOrigin + path
}

export default api
