<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useSidebar } from '../composables/useSidebar'
import AppSidebar from '../components/layout/AppSidebar.vue'
import AppHeader from '../components/layout/AppHeader.vue'
import AppFooter from '../components/layout/AppFooter.vue'

const authStore = useAuthStore()

// This layout only ever mounts on authenticated routes (see router meta),
// so its lifecycle is exactly "user is in an authenticated area" — the
// right place to run the token auto-refresh loop.
onMounted(() => authStore.startAutoRefresh())
onBeforeUnmount(() => authStore.stopAutoRefresh())

const { isOpen, isDesktop, isPinned, close } = useSidebar()
</script>

<template>
  <div class="min-h-screen font-poppins bg-page-bg text-gray-900 antialiased">
    <AppSidebar />

    <div
      class="fixed inset-0 bg-black/50 z-30"
      :class="isDesktop || !isOpen ? 'hidden' : ''"
      @click="close"
    ></div>

    <div class="page-wrapper flex flex-col min-w-0 min-h-screen" :class="{ 'sidebar-pinned': isPinned }">
      <AppHeader />

      <main class="flex-1 bg-page-bg px-4 lg:px-8 py-6 lg:py-8">
        <RouterView />
      </main>

      <AppFooter />
    </div>
  </div>
</template>
