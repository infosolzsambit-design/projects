import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import basicSsl from '@vitejs/plugin-basic-ssl'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Vite doesn't put .env values on process.env for the config file itself
  // (that only happens for import.meta.env in client code) — loadEnv is
  // needed here to actually read VITE_API_PROXY_TARGET from .env.
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [
      vue(),
      vueDevTools(),
      basicSsl(),
    ],
    server: {
      host: true,
      proxy: {
        // The frontend runs on HTTPS (needed for camera access) but the
        // Laravel backend is plain HTTP in dev — browsers block that as
        // mixed content if called directly. Proxying through Vite's own
        // (Node-side, not browser-side) server avoids that entirely.
        '/api': {
          target: env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
      },
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    optimizeDeps: {
      exclude: ['pdfjs-dist'],
    },
  }
})
