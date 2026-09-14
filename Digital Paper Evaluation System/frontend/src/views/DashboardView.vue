<script setup>
import { onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const loading = ref(true)
const loadError = ref('')

onMounted(async () => {
  try {
    await authStore.fetchMe()
  } catch {
    loadError.value = 'Could not load your profile. Try signing in again.'
  } finally {
    loading.value = false
  }
})

// Static placeholder figures matching the design mockup — there's no
// aggregate-stats API yet, so these aren't wired to real data. Swap for a
// real endpoint once one exists.
const kpis = [
  { label: 'Subject', value: '4', bg: 'bg-subject', shadow: 'shadow-subject' },
  { label: 'Evaluated', value: '35', bg: 'bg-evaluated', shadow: 'shadow-evaluated' },
  { label: 'Pending', value: '50', bg: 'bg-pending', shadow: 'shadow-pending' },
  { label: 'Ave/Day', value: '1.3', bg: 'bg-aveday', shadow: 'shadow-aveday' },
  { label: 'Highest/Day', value: '3', bg: 'bg-highestday', shadow: 'shadow-highestday', wide: true },
]

const notices = [
  'Lorem Ipsum is simply dummy text of the printing and typesetting.',
  'Lorem Ipsum is simply dummy text of the printing and typesetting.',
  'Lorem Ipsum is simply dummy text of the printing and typesetting.',
]

const pendingSubjects = [
  { code: 'ENG001', name: 'English' },
  { code: 'MAT002', name: 'Mathematics' },
  { code: 'SCI003', name: 'Science' },
  { code: 'HIS004', name: 'History' },
]
</script>

<template>
  <p v-if="loading" class="text-center text-sm text-muted py-10">Loading your dashboard&hellip;</p>
  <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

  <template v-else>

    <!---------------------------------------- Admin Dashboard ---------------------------------------->
    <template v-if="authStore.can('admin-dashboard')">
      <!-- KPI row -->
      <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4 mb-10">
        <div v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl px-5 py-5 text-white text-left"
          :class="[kpi.bg, kpi.shadow, kpi.wide ? 'col-span-2 sm:col-span-1' : '']">
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
            <span class="text-xl font-bold leading-none">{{ kpi.value }}</span>
          </div>
          <p class="mt-3 text-[16px] font-medium">{{ kpi.label }}</p>
        </div>
      </section>

      <!-- Quick actions -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
        <RouterLink to="/review"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform">
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-evaluation flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 11l3 3L22 4" />
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Evaluation</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </RouterLink>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-report flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
              <line x1="16" y1="13" x2="8" y2="13" />
              <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Detail Report</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-appointment flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Appointment</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-help flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Help</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
      </section>

      <!-- Bottom panels -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Notices -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/notice.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Notices</h3>
            <button type="button"
              class="ml-auto shrink-0 rounded-lg bg-support-gradient text-white px-3 py-2 text-[11px] font-semibold tracking-wide flex items-center gap-1.5">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                aria-hidden="true">
                <path d="M3 18v-6a9 9 0 0 1 18 0v6" />
                <path
                  d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z" />
              </svg>
              SUPPORT
            </button>
          </div>
          <ul class="space-y-2.5">
            <li v-for="(notice, i) in notices" :key="i"
              class="flex items-center gap-2.5 rounded-xl bg-soft px-3 py-2.5 text-[13px] text-notice">
              <span class="text-brand-blue font-bold shrink-0">&gt;</span>
              {{ notice }}
            </li>
          </ul>
        </div>

        <!-- Evaluation Trend -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/trend.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Evaluation Trend</h3>
          </div>
          <img src="/images/up-arrow.png" alt="Evaluation trend chart" class="w-full h-auto object-contain" />
        </div>

        <!-- Pending Subject -->
        <div class="bg-white rounded-2xl shadow-panel border border-pending-border p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/deadline.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Pending Subject</h3>
          </div>
          <ul class="space-y-2.5">
            <li v-for="subject in pendingSubjects" :key="subject.code"
              class="flex items-center justify-between gap-3 rounded-xl bg-soft px-3 py-2.5">
              <div>
                <span class="inline-block text-[10px] font-bold text-white bg-badge rounded-md px-2.5 py-1">{{
                  subject.code }}</span>
                <p class="text-xs font-bold text-gray-700 mt-1.5 uppercase tracking-wide">{{ subject.name }}</p>
              </div>
              <RouterLink to="/review"
                class="shrink-0 bg-white text-[12px] font-medium text-gray-500 rounded-full px-4 py-2 shadow-md whitespace-nowrap transition-colors duration-200 hover:bg-brand-blue hover:text-white hover:shadow-lg">
                All Pending</RouterLink>
            </li>
          </ul>
        </div>
      </section>

    </template>
    <!---------------------------------------- Admin Dashboard End ---------------------------------------->
    <!---------------------------------------- HOD Dashboard  ---------------------------------------->
    <template v-if="authStore.can('hod-dashboard')">
      <!-- KPI row -->
      <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4 mb-10">
        <div v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl px-5 py-5 text-white text-left"
          :class="[kpi.bg, kpi.shadow, kpi.wide ? 'col-span-2 sm:col-span-1' : '']">
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
            <span class="text-xl font-bold leading-none">{{ kpi.value }}</span>
          </div>
          <p class="mt-3 text-[16px] font-medium">{{ kpi.label }}</p>
        </div>
      </section>

      <!-- Quick actions -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
        <RouterLink to="/review"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform">
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-evaluation flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 11l3 3L22 4" />
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Evaluation</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </RouterLink>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-report flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
              <line x1="16" y1="13" x2="8" y2="13" />
              <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Detail Report</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-appointment flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Appointment</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-help flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Help</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
      </section>

      <!-- Bottom panels -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Notices -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/notice.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Notices</h3>
            <button type="button"
              class="ml-auto shrink-0 rounded-lg bg-support-gradient text-white px-3 py-2 text-[11px] font-semibold tracking-wide flex items-center gap-1.5">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                aria-hidden="true">
                <path d="M3 18v-6a9 9 0 0 1 18 0v6" />
                <path
                  d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z" />
              </svg>
              SUPPORT
            </button>
          </div>
          <ul class="space-y-2.5">
            <li v-for="(notice, i) in notices" :key="i"
              class="flex items-center gap-2.5 rounded-xl bg-soft px-3 py-2.5 text-[13px] text-notice">
              <span class="text-brand-blue font-bold shrink-0">&gt;</span>
              {{ notice }}
            </li>
          </ul>
        </div>

        <!-- Evaluation Trend -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/trend.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Evaluation Trend</h3>
          </div>
          <img src="/images/up-arrow.png" alt="Evaluation trend chart" class="w-full h-auto object-contain" />
        </div>

        <!-- Pending Subject -->
        <div class="bg-white rounded-2xl shadow-panel border border-pending-border p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/deadline.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Pending Subject</h3>
          </div>
          <ul class="space-y-2.5">
            <li v-for="subject in pendingSubjects" :key="subject.code"
              class="flex items-center justify-between gap-3 rounded-xl bg-soft px-3 py-2.5">
              <div>
                <span class="inline-block text-[10px] font-bold text-white bg-badge rounded-md px-2.5 py-1">{{
                  subject.code }}</span>
                <p class="text-xs font-bold text-gray-700 mt-1.5 uppercase tracking-wide">{{ subject.name }}</p>
              </div>
              <RouterLink to="/review"
                class="shrink-0 bg-white text-[12px] font-medium text-gray-500 rounded-full px-4 py-2 shadow-md whitespace-nowrap transition-colors duration-200 hover:bg-brand-blue hover:text-white hover:shadow-lg">
                All Pending</RouterLink>
            </li>
          </ul>
        </div>
      </section>
    </template>
    <!---------------------------------------- HOD Dashboard End  ---------------------------------------->
    <!---------------------------------------- Teacher Dashboard  ---------------------------------------->
    <template v-if="authStore.can('teacher-dashboard')">
      <!-- KPI row -->
      <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4 mb-10">
        <div v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl px-5 py-5 text-white text-left"
          :class="[kpi.bg, kpi.shadow, kpi.wide ? 'col-span-2 sm:col-span-1' : '']">
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
            <span class="text-xl font-bold leading-none">{{ kpi.value }}</span>
          </div>
          <p class="mt-3 text-[16px] font-medium">{{ kpi.label }}</p>
        </div>
      </section>

      <!-- Quick actions -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
        <RouterLink to="/review"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform">
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-evaluation flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 11l3 3L22 4" />
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Evaluation</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </RouterLink>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-report flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
              <line x1="16" y1="13" x2="8" y2="13" />
              <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Detail Report</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-appointment flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Appointment</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
        <a href="#"
          class="relative bg-white rounded-2xl shadow-panel pt-11 pb-5 px-5 hover:-translate-y-0.5 transition-transform"
          @click.prevent>
          <span
            class="absolute -top-4 left-5 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-help flex items-center justify-center shadow-md">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
          </span>
          <h3 class="font-bold text-gray-900">Help</h3>
          <p class="text-xs text-gray-400 mt-1">View Details</p>
        </a>
      </section>

      <!-- Bottom panels -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Notices -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/notice.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Notices</h3>
            <button type="button"
              class="ml-auto shrink-0 rounded-lg bg-support-gradient text-white px-3 py-2 text-[11px] font-semibold tracking-wide flex items-center gap-1.5">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                aria-hidden="true">
                <path d="M3 18v-6a9 9 0 0 1 18 0v6" />
                <path
                  d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z" />
              </svg>
              SUPPORT
            </button>
          </div>
          <ul class="space-y-2.5">
            <li v-for="(notice, i) in notices" :key="i"
              class="flex items-center gap-2.5 rounded-xl bg-soft px-3 py-2.5 text-[13px] text-notice">
              <span class="text-brand-blue font-bold shrink-0">&gt;</span>
              {{ notice }}
            </li>
          </ul>
        </div>

        <!-- Evaluation Trend -->
        <div class="bg-white rounded-2xl shadow-panel p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/trend.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Evaluation Trend</h3>
          </div>
          <img src="/images/up-arrow.png" alt="Evaluation trend chart" class="w-full h-auto object-contain" />
        </div>

        <!-- Pending Subject -->
        <div class="bg-white rounded-2xl shadow-panel border border-pending-border p-5">
          <div class="flex items-center gap-2 mb-4">
            <img src="/images/deadline.png" alt="" class="w-6 h-6 object-contain" />
            <h3 class="font-medium text-gray-900">Pending Subject</h3>
          </div>
          <ul class="space-y-2.5">
            <li v-for="subject in pendingSubjects" :key="subject.code"
              class="flex items-center justify-between gap-3 rounded-xl bg-soft px-3 py-2.5">
              <div>
                <span class="inline-block text-[10px] font-bold text-white bg-badge rounded-md px-2.5 py-1">{{
                  subject.code }}</span>
                <p class="text-xs font-bold text-gray-700 mt-1.5 uppercase tracking-wide">{{ subject.name }}</p>
              </div>
              <RouterLink to="/review"
                class="shrink-0 bg-white text-[12px] font-medium text-gray-500 rounded-full px-4 py-2 shadow-md whitespace-nowrap transition-colors duration-200 hover:bg-brand-blue hover:text-white hover:shadow-lg">
                All Pending</RouterLink>
            </li>
          </ul>
        </div>
      </section>
    </template>
    <!---------------------------------------- Teacher Dashboard End  ---------------------------------------->

  </template>


</template>
