<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useBrandingStore } from '../stores/branding'
import ForgotPasswordModal from '../components/common/ForgotPasswordModal.vue'

const authStore = useAuthStore()
const branding = useBrandingStore()
const router = useRouter()
const route = useRoute()

const login = ref('')
const password = ref('')
const showPassword = ref(false)
const status = ref('idle') // idle | loading | error
const errorMessage = ref('')
const loginError = ref('')
const passwordError = ref('')
const showForgotPassword = ref(false)

async function submit() {
  loginError.value = ''
  passwordError.value = ''
  errorMessage.value = ''
  status.value = 'idle'

  if (!login.value.trim()) loginError.value = 'Email or phone is required.'
  if (!password.value.trim()) passwordError.value = 'Password is required.'
  if (loginError.value || passwordError.value) return

  status.value = 'loading'
  try {
    await authStore.login(login.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    router.push(redirect)
  } catch (err) {
    status.value = 'error'
    errorMessage.value = err.response?.data?.message || 'Could not log in. Please try again.'
  }
}
</script>

<template>
  <div
    class="relative min-h-screen flex flex-col font-poppins bg-page-bg text-gray-900 antialiased overflow-x-hidden lg:h-screen lg:overflow-hidden">
    <!-- Half circle from top-left; logo centered in the visible part -->
    <div
      class="absolute z-30 top-0 left-0 w-[220px] h-[110px] min-[1500px]:w-[300px] min-[1500px]:h-[150px] overflow-hidden">
      <span
        class="pointer-events-none absolute left-0 top-0 w-[220px] h-[220px] min-[1500px]:w-[300px] min-[1500px]:h-[300px] -translate-y-1/2 rounded-full bg-white"></span>
      <div class="absolute inset-0 z-10 flex items-center justify-center">
        <img :src="branding.loginLogoUrl" :alt="branding.siteTitleValue"
          class="w-[58px] min-[1500px]:w-[80px] h-auto object-contain" />
      </div>
    </div>
    <img src="/images/login-bg.png" alt=""
      class="pointer-events-none hidden lg:block absolute z-20 bottom-0 right-0 w-[40vw] max-w-[480px] min-[1500px]:w-[38vw] min-[1500px]:max-w-[620px] h-auto max-h-[78%] object-contain object-right-bottom select-none"
      aria-hidden="true" />

    <main class="relative z-10 flex-1 flex items-center w-full">
      <div class="w-full px-4 sm:px-8 lg:pl-8 xl:pl-10 min-[1500px]:pl-24 lg:pr-0 py-8 lg:py-0">
        <div
          class="relative flex items-center min-h-[520px] lg:ml-[3%] xl:ml-[4%] min-[1500px]:ml-[12%] lg:mt-10 xl:mt-12">
          <!-- Form -->
          <div
            class="w-full max-w-[580px] min-[1500px]:max-w-[640px] min-[1701px]:max-w-[760px] mx-auto lg:mx-0 relative z-10">
            <h1 class="text-[28px] sm:text-[34px] lg:text-[40px] font-bold text-black tracking-tight mb-4 sm:mb-5">
              Welcome Back</h1>

            <div
              class="bg-white rounded-[28px] shadow-card px-6 py-8 sm:px-10 sm:py-10 lg:px-12 lg:py-11 transition-shadow duration-300 hover:shadow-[-10px_14px_36px_rgba(232,27,38,0.22),10px_14px_36px_rgba(47,86,192,0.22)]">
              <h2 class="text-xl sm:text-[22px] font-bold text-black pb-3.5 mb-6 sm:mb-7 border-b border-input-border">
                Sign In</h2>

              <form class="flex flex-col gap-5 sm:gap-6" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                  <label for="login" class="text-[13px] text-label">Email or Phone</label>
                  <input id="login" v-model="login" type="text" name="login" autocomplete="email" autofocus
                    @input="loginError = ''"
                    class="w-full h-12 sm:h-[52px] px-4 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                    :class="loginError ? 'border-brand' : 'border-input-border'" />
                  <p v-if="loginError" class="text-[12px] text-brand -mt-1">{{ loginError }}</p>
                </div>

                <div class="flex flex-col gap-2">
                  <label for="password" class="text-[13px] text-label">Password</label>
                  <div class="relative">
                    <input id="password" v-model="password" :type="showPassword ? 'text' : 'password'" name="password"
                      autocomplete="current-password"
                      @input="passwordError = ''"
                      class="w-full h-12 sm:h-[52px] pl-4 pr-12 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                      :class="passwordError ? 'border-brand' : 'border-input-border'" />
                    <button type="button"
                      class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                      aria-label="Toggle password visibility" @click="showPassword = !showPassword">
                      <svg v-if="!showPassword" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" aria-hidden="true">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                        <line x1="1" y1="1" x2="23" y2="23" />
                      </svg>
                      <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        aria-hidden="true">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                      </svg>
                    </button>
                  </div>
                  <p v-if="passwordError" class="text-[12px] text-brand -mt-1">{{ passwordError }}</p>

                  <div class="flex justify-between items-center">
                    <p v-if="status === 'error'" class="text-[13px] text-brand -mt-1">{{ errorMessage }}</p>

                    <button type="button"
                      class="group self-end mt-1 inline-flex items-center gap-1.5 text-[12px] sm:text-[13px] font-semibold tracking-wide text-brand-blue hover:text-brand transition-colors duration-200 ml-auto"
                      @click="showForgotPassword = true">
                      <svg class="w-3.5 h-3.5 text-brand-blue group-hover:text-brand transition-colors"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="11" width="18" height="11" rx="2" />
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                      </svg>
                      <span
                        class="relative after:absolute after:left-0 after:-bottom-0.5 after:h-[1.5px] after:w-0 after:bg-btn-gradient after:transition-all after:duration-300 group-hover:after:w-full">Forgot
                        password?</span>
                    </button>

                  </div>
                </div>


                <div class="pt-4 flex justify-center">
                  <button type="submit" :disabled="status === 'loading'"
                    class="min-w-[210px] px-16 py-3.5 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.1em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed disabled:hover:translate-y-0">
                    {{ status === 'loading' ? 'Signing in…' : 'Sign In' }}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </main>

    <ForgotPasswordModal v-if="showForgotPassword" @close="showForgotPassword = false" />
  </div>
</template>
