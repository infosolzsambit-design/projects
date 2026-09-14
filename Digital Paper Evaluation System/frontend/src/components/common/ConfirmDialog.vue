<script setup>
import { useConfirm } from '../../composables/useConfirm'

const { isOpen, title, message, confirmText, cancelText, danger, handleConfirm, handleCancel } = useConfirm()
</script>

<template>
  <div
    v-if="isOpen"
    class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4"
    @click.self="handleCancel"
  >
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-sm px-6 py-7 text-center">
      <span
        class="mx-auto mb-4 w-14 h-14 rounded-full flex items-center justify-center"
        :class="danger ? 'bg-brand/10' : 'bg-soft'"
      >
        <svg v-if="danger" class="w-7 h-7 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M3 6h18" />
          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
          <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
          <line x1="10" y1="11" x2="10" y2="17" />
          <line x1="14" y1="11" x2="14" y2="17" />
        </svg>
        <svg v-else class="w-7 h-7 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="12" cy="12" r="10" />
          <line x1="12" y1="16" x2="12" y2="12" />
          <line x1="12" y1="8" x2="12.01" y2="8" />
        </svg>
      </span>

      <h2 class="text-lg font-bold text-black mb-2">{{ title }}</h2>
      <p class="text-[13px] text-muted mb-6">{{ message }}</p>

      <div class="flex justify-center gap-3">
        <button
          type="button"
          class="min-w-[110px] px-5 py-2.5 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="handleCancel"
        >
          {{ cancelText }}
        </button>
        <button
          type="button"
          class="min-w-[110px] px-5 py-2.5 rounded-full text-white text-sm font-semibold uppercase tracking-wide transition-opacity hover:opacity-90"
          :class="danger ? 'bg-brand' : 'bg-btn-gradient'"
          @click="handleConfirm"
        >
          {{ confirmText }}
        </button>
      </div>
    </div>
  </div>
</template>
