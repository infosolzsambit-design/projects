<script setup>
import { useBrandingStore } from '../../stores/branding'

const branding = useBrandingStore()
const year = new Date().getFullYear()
</script>

<template>
  <footer id="site-footer" class="relative z-50 bg-footer py-5 mt-auto lg:-ml-[60px] lg:w-[calc(100%+60px)]">
    <div class="px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col items-center text-center gap-4 md:flex-row md:justify-between md:text-left">
        <RouterLink to="/dashboard" class="inline-flex items-center">
          <img :src="branding.footerLogoUrl" :alt="branding.siteTitleValue" class="h-9 sm:h-10 w-auto object-contain" />
        </RouterLink>
        <div class="flex items-center gap-3">
          <a href="#" class="w-9 h-9 rounded-full border border-gray-600 flex items-center justify-center text-white hover:border-brand hover:text-brand transition-colors" aria-label="Facebook" @click.prevent>
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" /></svg>
          </a>
          <a href="#" class="w-9 h-9 rounded-full border border-gray-600 flex items-center justify-center text-white hover:border-brand hover:text-brand transition-colors" aria-label="YouTube" @click.prevent>
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-2C18.88 4 12 4 12 4s-6.88 0-8.59.42a2.78 2.78 0 0 0-1.95 2C1 8.13 1 12 1 12s0 3.87.46 5.58a2.78 2.78 0 0 0 1.95 2C5.12 20 12 20 12 20s6.88 0 8.59-.42a2.78 2.78 0 0 0 1.95-2C23 15.87 23 12 23 12s0-3.87-.46-5.58z" /><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#fff" /></svg>
          </a>
          <a href="#" class="w-9 h-9 rounded-full border border-gray-600 flex items-center justify-center text-white hover:border-brand hover:text-brand transition-colors" aria-label="Instagram" @click.prevent>
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" /><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" /><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" /></svg>
          </a>
        </div>
        <p class="text-xs text-gray-400">
          Copyright &copy; {{ year }} <span class="text-brand font-medium">{{ branding.siteTitleValue }}</span>. All Rights Reserved.
        </p>
      </div>
    </div>
  </footer>
</template>
