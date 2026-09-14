<script setup>
import { useToast } from '../../composables/useToast'

// Mounted once, globally, in App.vue — every page pushes to this same stack
// via useToast()'s success()/error()/info() instead of rolling its own
// inline banner. Sits above everything, including open modals (z-[300] vs.
// their z-[200]), so e.g. closing ChangePasswordModal after a save doesn't
// hide the confirmation behind it.
const { toasts, dismiss } = useToast()

const ICONS = {
  success: { wrap: 'bg-success/10 text-success' },
  error: { wrap: 'bg-brand/10 text-brand' },
  info: { wrap: 'bg-brand-blue/10 text-brand-blue' },
}
</script>

<template>
  <div class="fixed top-4 right-4 z-[300] flex flex-col gap-2 w-[calc(100%-2rem)] max-w-[360px] pointer-events-none">
    <TransitionGroup name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex items-start gap-3 rounded-2xl bg-white shadow-panel px-4 py-3"
      >
        <span class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 mt-0.5" :class="ICONS[toast.type].wrap">
          <svg v-if="toast.type === 'success'" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
            <polyline points="20 6 9 17 4 12" />
          </svg>
          <svg v-else-if="toast.type === 'error'" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          <svg v-else class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" />
          </svg>
        </span>
        <p class="flex-1 text-[13px] text-gray-800 leading-snug pt-0.5">{{ toast.message }}</p>
        <button
          type="button"
          class="text-gray-400 hover:text-gray-600 shrink-0 mt-0.5"
          aria-label="Dismiss"
          @click="dismiss(toast.id)"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active,
.toast-move {
  transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(24px);
}
.toast-leave-active {
  position: absolute;
  width: 100%;
}
</style>
