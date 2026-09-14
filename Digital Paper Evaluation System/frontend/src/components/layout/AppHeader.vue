<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useSidebar } from '../../composables/useSidebar'
import ChangePasswordModal from '../common/ChangePasswordModal.vue'

const authStore = useAuthStore()
const router = useRouter()
const { isOpen, isPinned, toggle } = useSidebar()

const userMenuOpen = ref(false)
const userMenuRoot = ref(null)
const showChangePassword = ref(false)

function openChangePassword() {
  userMenuOpen.value = false
  showChangePassword.value = true
}

function onDocumentClick(event) {
  if (userMenuRoot.value && !userMenuRoot.value.contains(event.target)) {
    userMenuOpen.value = false
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

async function logout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <header class="bg-white shadow-[0_2px_8px_rgba(0,0,0,0.06)] sticky top-0 z-20">
    <div class="flex items-center justify-between h-14 sm:h-[70px] px-4 gap-3">
      <button
        type="button"
        class="relative z-50 p-2 rounded-lg hover:bg-gray-100 text-gray-700"
        aria-label="Toggle sidebar"
        :aria-expanded="isPinned || isOpen"
        @click="toggle"
      >
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="6" x2="21" y2="6" />
          <line x1="3" y1="12" x2="21" y2="12" />
          <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
      </button>

      <!-- Season / Exam filters — kept for visual fidelity with the design;
           no backend concept of "season"/"exam" exists yet, so these are
           inert single-option selects for now. -->
      <!-- <div class="flex items-center gap-2 sm:gap-4 md:gap-6 ml-auto mr-2 sm:mr-4 min-w-0">
        <div class="flex items-center gap-1.5 sm:gap-2 min-w-0">
          <label for="season-select" class="hidden sm:flex items-center gap-1.5 text-[13px] text-gray-500 whitespace-nowrap">
            <svg class="w-4 h-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
            Season
          </label>
          <div class="relative">
            <select
              id="season-select"
              class="appearance-none bg-white border border-input-border rounded-md py-1.5 pl-2 sm:pl-3 pr-7 sm:pr-8 text-[12px] sm:text-[13px] cursor-pointer w-[78px] sm:min-w-[100px] sm:w-auto focus:outline-none focus:border-brand-blue"
              aria-label="Season"
            >
              <option selected>SO24</option>
            </select>
            <svg
              class="absolute right-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
            >
              <polyline points="6 9 12 15 18 9" />
            </svg>
          </div>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 min-w-0">
          <label for="exam-select" class="hidden sm:flex items-center gap-1.5 text-[13px] text-gray-500 whitespace-nowrap">
            <svg class="w-4 h-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
              <polyline points="14 2 14 8 20 8" />
            </svg>
            Exam
          </label>
          <div class="relative">
            <select
              id="exam-select"
              class="appearance-none bg-white border border-input-border rounded-md py-1.5 pl-2 sm:pl-3 pr-7 sm:pr-8 text-[12px] sm:text-[13px] cursor-pointer w-[88px] sm:min-w-[100px] sm:w-auto focus:outline-none focus:border-brand-blue"
              aria-label="Exam"
            >
              <option selected>Regular</option>
            </select>
            <svg
              class="absolute right-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
            >
              <polyline points="6 9 12 15 18 9" />
            </svg>
          </div>
        </div>
      </div> -->

      <div ref="userMenuRoot" class="relative">
        <button
          type="button"
          class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50"
          :aria-expanded="userMenuOpen"
          aria-haspopup="true"
          aria-controls="user-menu"
          @click="userMenuOpen = !userMenuOpen"
        >
          <span class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-gray-500" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
            </svg>
          </span>
          <span class="hidden sm:inline text-[13px] text-gray-500 max-w-[160px] truncate">{{ authStore.user?.name || 'Welcome' }}</span>
          <svg
            class="w-3.5 h-3.5 text-gray-400 transition-transform duration-200"
            :class="{ 'rotate-180': userMenuOpen }"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
          >
            <polyline points="6 9 12 15 18 9" />
          </svg>
        </button>
        <div
          v-show="userMenuOpen"
          id="user-menu"
          class="absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-panel border border-soft py-1.5 z-30"
          role="menu"
        >
          <RouterLink v-if="authStore.can('profile-update')"
            :to="{ name: 'profile' }"
            class="flex items-center gap-2.5 px-3.5 py-2.5 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
            role="menuitem"
            @click="userMenuOpen = false"
          >
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
            Profile
          </RouterLink>
          <button
            v-if="authStore.can('change-password')"
            type="button"
            class="w-full flex items-center gap-2.5 px-3.5 py-2.5 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
            role="menuitem"
            @click="openChangePassword"
          >
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            Change Password
          </button>
          <button
            type="button"
            class="w-full flex items-center gap-2.5 px-3.5 py-2.5 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors"
            role="menuitem"
            @click="logout"
          >
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            Logout
          </button>
        </div>
      </div>
    </div>

    <ChangePasswordModal v-if="showChangePassword" @close="showChangePassword = false" />
  </header>
</template>
