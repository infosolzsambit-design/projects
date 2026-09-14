<script setup>
import { ref } from 'vue'
import api from '../../utils/api'

// Step 1 of the forgot-password flow, opened from the "Forgot password?"
// link on the Login page. Emails a reset link via SendGrid (see
// AuthController::forgotPassword()) that lands on ResetPasswordView.vue —
// step 2 — for the actual new-password form.
const emit = defineEmits(['close'])

const email = ref('')
const emailError = ref('')
const formError = ref('')
const sending = ref(false)
const sent = ref(false)

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

async function submit() {
  emailError.value = ''
  formError.value = ''

  if (!email.value.trim()) {
    emailError.value = 'Email is required.'
    return
  }
  if (!EMAIL_PATTERN.test(email.value.trim())) {
    emailError.value = 'Enter a valid email address.'
    return
  }

  sending.value = true
  try {
    await api.post('/forgot-password', { email: email.value.trim() })
    // Always the same success state regardless of whether the address is
    // registered — the backend response is deliberately generic too (see
    // AuthController::forgotPassword()), so there's nothing to branch on.
    sent.value = true
  } catch (err) {
    const data = err.response?.data
    if (data?.errors?.email) {
      emailError.value = data.errors.email[0]
    } else {
      formError.value = data?.message || 'Could not send the reset link. Please try again.'
    }
  } finally {
    sending.value = false
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="close">
    <div class="w-full max-w-[440px] rounded-[28px] bg-white shadow-panel overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
        <h2 class="text-[18px] font-semibold text-gray-900">Forgot Password</h2>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors" aria-label="Close" @click="close">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div v-if="sent" class="p-8 flex flex-col items-center text-center gap-3">
        <span class="w-14 h-14 rounded-full bg-success/10 text-success flex items-center justify-center">
          <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6" /><rect x="2" y="4" width="20" height="16" rx="2" /></svg>
        </span>
        <p class="text-sm font-semibold text-gray-900">Check your email.</p>
        <p class="text-[13px] text-muted">
          If an account exists for <span class="font-medium text-gray-700">{{ email }}</span>, a password reset link has been sent. It expires in 60 minutes.
        </p>
        <button type="button" class="mt-2 min-w-[160px] px-6 py-2.5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="close">
          Close
        </button>
      </div>

      <form v-else class="p-5 flex flex-col gap-4" @submit.prevent="submit">
        <p class="text-[13px] text-muted -mt-1">Enter the email address on your account and we'll send you a link to reset your password.</p>
        <p v-if="formError" class="text-[13px] text-brand text-center">{{ formError }}</p>

        <div class="flex flex-col gap-1.5">
          <label for="forgot-email" class="text-[13px] text-label">Email Address</label>
          <input
            id="forgot-email"
            v-model="email"
            type="text"
            autocomplete="email"
            autofocus
            class="w-full h-11 px-4 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
            :class="emailError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
            @input="emailError = ''"
          />
          <p v-if="emailError" class="text-[12px] text-brand">{{ emailError }}</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button
            type="submit"
            class="min-w-[200px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.06em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="sending"
          >
            {{ sending ? 'Sending…' : 'Send Reset Link' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
