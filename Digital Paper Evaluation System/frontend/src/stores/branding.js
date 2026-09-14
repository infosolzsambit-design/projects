import { defineStore } from 'pinia'
import api, { resolveStorageUrl } from '../utils/api'

// The app's own chrome (favicon, login-page logo, sidebar logos, footer
// logo, and the site title shown in the browser tab / footer / anywhere
// else the app's name appears) — configurable via General Settings (the
// "Branding & Icons" section for the icons, plus the standalone Site Title
// field — see GeneralSettingsView.vue) instead of being hardcoded to files
// under public/images/ or the literal string "CJ Paper Check". Fetched once
// from the public GET /branding endpoint (no auth required — this has to
// paint before a visitor has even logged in, see LoginView.vue) and shared
// from here so every consumer (App.vue's favicon/title, LoginView,
// AppSidebar, AppFooter) reads the same values instead of each fetching
// independently.
//
// Every getter falls back to something sane if a value hasn't been set (or
// hasn't loaded yet) — nothing regresses for an app that never touches this
// feature. Deliberately "Paper Check", not "CJ Paper Check", as the
// fallback site title — see App.vue/AppFooter.vue.
export const useBrandingStore = defineStore('branding', {
  state: () => ({
    favicon: null,
    loginLogo: null,
    headerLogoFull: null,
    headerLogoIcon: null,
    footerLogo: null,
    siteTitle: null,
    loaded: false,
  }),
  getters: {
    faviconUrl: (state) => state.favicon || '/images/logo-image.png',
    loginLogoUrl: (state) => state.loginLogo || '/images/logo-image.png',
    headerLogoFullUrl: (state) => state.headerLogoFull || '/images/header_logo.png',
    headerLogoIconUrl: (state) => state.headerLogoIcon || '/images/logo-icon.png',
    footerLogoUrl: (state) => state.footerLogo || '/images/footer_logo.png',
    siteTitleValue: (state) => state.siteTitle || 'Paper Check',
  },
  actions: {
    async load() {
      try {
        const res = await api.get('/branding')
        const data = res.data.data
        this.favicon = resolveStorageUrl(data.favicon)
        this.loginLogo = resolveStorageUrl(data.login_logo)
        this.headerLogoFull = resolveStorageUrl(data.header_logo_full)
        this.headerLogoIcon = resolveStorageUrl(data.header_logo_icon)
        this.footerLogo = resolveStorageUrl(data.footer_logo)
        this.siteTitle = data.site_title
      } catch {
        // Network hiccup or the endpoint being unreachable just means every
        // getter above keeps falling back to the static defaults — not
        // worth surfacing an error for.
      } finally {
        this.loaded = true
      }
    },
  },
})
