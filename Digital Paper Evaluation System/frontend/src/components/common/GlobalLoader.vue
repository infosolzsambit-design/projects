<script setup>
import { useLoading } from '../../composables/useLoading'

const { isActive, isDeterminate, progress, progressLabel } = useLoading()
</script>

<template>
  <!-- Above every modal (z-[200]) and the toast stack (z-[300]) — while
       this is up, nothing underneath should be reachable at all (see
       useLoading.js's own docblock for why this shows), so it's a plain
       full-viewport blocker rather than a corner spinner. -->
  <div v-if="isActive" class="fixed inset-0 z-[400] flex items-center justify-center bg-black/40" @click.stop @mousedown.stop @wheel.prevent>
    <div class="bg-white rounded-[24px] shadow-card px-8 py-7 flex flex-col items-center gap-4 min-w-[200px]">
      <template v-if="isDeterminate">
        <div class="w-52 h-2.5 rounded-full bg-soft overflow-hidden">
          <div class="h-full bg-btn-gradient transition-all duration-200 ease-out" :style="{ width: progress + '%' }" />
        </div>
        <p class="text-sm font-semibold text-gray-800">{{ Math.round(progress) }}%</p>
      </template>
      <template v-else>
        <svg class="animate-spin w-9 h-9 text-brand" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
      </template>
      <p v-if="progressLabel" class="text-xs text-muted text-center">{{ progressLabel }}</p>
    </div>
  </div>
</template>
