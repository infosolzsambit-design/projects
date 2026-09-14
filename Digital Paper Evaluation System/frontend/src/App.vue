<script setup>
import { onMounted, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import ConfirmDialog from './components/common/ConfirmDialog.vue'
import GlobalLoader from './components/common/GlobalLoader.vue'
import Toaster from './components/common/Toaster.vue'
import { useBrandingStore } from './stores/branding'

const route = useRoute()

// Loaded once, app-wide — see stores/branding.js for why this has to work
// before login (LoginView's logo) as well as after (sidebar/footer).
const branding = useBrandingStore()
onMounted(() => branding.load())

// index.html's <link rel="icon"> can't be templated (it's static HTML
// served before Vue even boots), so the favicon swap has to happen
// imperatively here once the real value comes back — everything else
// (LoginView, AppSidebar, AppFooter) just binds to the store directly.
watch(
  () => branding.faviconUrl,
  (href) => {
    document.querySelector('link[rel="icon"]')?.setAttribute('href', href)
  },
)

// document.title, similarly imperative and similarly not something a
// <RouterView>-nested page can own — this used to live in router/index.js's
// afterEach (per-navigation only), which meant a page loaded *before*
// branding.load() resolved kept the fallback title even after the real
// site_title came in. Watching both together here fixes that: it re-runs
// on every navigation (route.meta.title changes) *and* the moment the site
// title actually loads, whichever happens second.
watch(
  [() => route.meta.title, () => branding.siteTitleValue],
  ([pageTitle, siteTitle]) => {
    document.title = pageTitle ? `${pageTitle} - ${siteTitle}` : siteTitle
  },
  { immediate: true },
)
</script>

<template>
  <RouterView />
  <!-- Mounted once, globally — every page shares this one dialog via
       useConfirm()'s confirmDialog() instead of the browser's native
       confirm(). -->
  <ConfirmDialog />
  <!-- Same idea, for toast notifications — see useToast(). -->
  <Toaster />
  <!-- Same idea again, for the app-wide "an API call is in flight" overlay
       — see useLoading() / utils/api.js's interceptors. -->
  <GlobalLoader />
</template>
