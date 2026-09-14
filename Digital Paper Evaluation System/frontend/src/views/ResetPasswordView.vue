<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../utils/api'
import { PASSWORD_RULES } from '../utils/passwordRules'
import PasswordRequirementsChecklist from '../components/common/PasswordRequirementsChecklist.vue'
import { useBrandingStore } from '../stores/branding'

const branding = useBrandingStore()

// Step 2 of the forgot-password flow — reached via the link SendGrid emails
// from ForgotPasswordModal.vue (see AuthController::forgotPassword() /
// AppServiceProvider's ResetPassword::createUrlUsing()). The token+email
// pair travel as query params; this page never needs the user to be
// logged in.
const route = useRoute()
const router = useRouter()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))

// Checked once against the server on mount (POST /reset-password/verify —
// same hash+expiry check reset() itself does, but non-destructive) so a
// dead link shows "invalid/expired" immediately instead of only after the
// user fills in and submits the form. Once true, this stays true for good
// — the form never comes back, whether that's because the link was dead on
// arrival or a later submit found out it went stale while the page sat
// open (see submit()'s catch below).
const checkingLink = ref(true)
const linkInvalid = ref(false)

async function verifyLink() {
  if (!token.value || !email.value) {
    linkInvalid.value = true
    checkingLink.value = false
    return
  }
  try {
    const res = await api.post('/reset-password/verify', { token: token.value, email: email.value })
    linkInvalid.value = !res.data.data.valid
  } catch {
    linkInvalid.value = true
  } finally {
    checkingLink.value = false
  }
}

const form = reactive({ password: '', password_confirmation: '' })
const fieldErrors = reactive({ password: '', password_confirmation: '' })
// Order matters — matches the form's own top-to-bottom field order, so the
// first of these (in this order) that has an error is the one that gets
// focused after a failed submit.
const FIELD_ORDER = ['password', 'password_confirmation']
const fieldRefs = { password: ref(null), password_confirmation: ref(null) }
function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}
const saving = ref(false)
const done = ref(false)

const showPassword = ref(false)
const showConfirm = ref(false)

function clearError(field) {
  fieldErrors[field] = ''
}

function validate() {
  fieldErrors.password = ''
  fieldErrors.password_confirmation = ''

  if (!form.password) {
    fieldErrors.password = 'Enter a new password.'
  } else if (PASSWORD_RULES.some((rule) => !rule.test(form.password))) {
    fieldErrors.password = 'Password does not meet all the requirements below.'
  }

  if (!form.password_confirmation) {
    fieldErrors.password_confirmation = 'Please confirm the new password.'
  } else if (form.password && form.password !== form.password_confirmation) {
    fieldErrors.password_confirmation = 'Passwords do not match.'
  }

  return !fieldErrors.password && !fieldErrors.password_confirmation
}

async function submit() {
  if (!validate()) {
    focusFirstError()
    return
  }

  saving.value = true
  try {
    await api.post('/reset-password', { token: token.value, email: email.value, ...form })
    done.value = true
  } catch {
    // validate() above already guarantees the password itself is fine, so
    // a failure here can only mean the token was no good after all (it
    // expired, or got used/invalidated by another request while this tab
    // sat open) — nothing left to fix and resubmit, so collapse straight to
    // the same terminal "invalid link" state a dead link shows on arrival
    // instead of leaving the form up with an error banner over it.
    linkInvalid.value = true
  } finally {
    saving.value = false
  }
}

function goToLogin() {
  router.push({ name: 'login' })
}

onMounted(verifyLink)
</script>

<template>
  <div class="relative min-h-screen flex flex-col items-center justify-center font-poppins bg-page-bg text-gray-900 antialiased px-4 py-10">
    <img :src="branding.loginLogoUrl" :alt="branding.siteTitleValue" class="w-14 h-auto object-contain mb-6" />

    <div class="w-full max-w-[460px] bg-white rounded-[28px] shadow-card px-6 py-8 sm:px-10 sm:py-10">
      <!-- Checking the link with the server before showing anything else. -->
      <div v-if="checkingLink" class="flex flex-col items-center text-center gap-3 py-6">
        <svg class="w-7 h-7 text-brand-blue animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-9-9" /></svg>
        <p class="text-[13px] text-muted">Checking your link&hellip;</p>
      </div>

      <!-- Dead link — missing token/email, already used, or expired. Once
           we know this, the form never shows (this is where a fresh visit
           to an already-dead link ends up too, straight off verifyLink()). -->
      <div v-else-if="linkInvalid" class="flex flex-col items-center text-center gap-3">
        <span class="w-14 h-14 rounded-full bg-brand/10 text-brand flex items-center justify-center">
          <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
        </span>
        <h1 class="text-lg font-semibold text-gray-900">Invalid Reset Link</h1>
        <p class="text-[13px] text-muted">This password reset link is invalid or has expired. Please request a new one from the login page.</p>
        <button type="button" class="mt-2 min-w-[160px] px-6 py-2.5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold tracking-wide uppercase hover:opacity-90 transition-all" @click="goToLogin">
          Back to Login
        </button>
      </div>

      <!-- Success — password changed. -->
      <div v-else-if="done" class="flex flex-col items-center text-center gap-3">
        <span class="w-14 h-14 rounded-full bg-success/10 text-success flex items-center justify-center">
          <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
        </span>
        <h1 class="text-lg font-semibold text-gray-900">Password Reset</h1>
        <p class="text-[13px] text-muted">Your password has been changed successfully. You can now sign in with your new password.</p>
        <button type="button" class="mt-2 min-w-[160px] px-6 py-2.5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold tracking-wide uppercase hover:opacity-90 transition-all" @click="goToLogin">
          Sign In
        </button>
      </div>

      <!-- The actual reset form. -->
      <form v-else class="flex flex-col gap-5" @submit.prevent="submit">
        <div>
          <h1 class="text-xl font-bold text-black">Reset Password</h1>
          <p class="mt-1 text-[13px] text-muted">Choose a new password for <span class="font-medium text-gray-700">{{ email }}</span>.</p>
        </div>

        <div class="flex flex-col gap-2">
          <label for="new-password" class="text-[13px] text-label">New Password</label>
          <div class="relative">
            <input
              id="new-password"
              :ref="(el) => (fieldRefs.password.value = el)"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              autofocus
              class="w-full h-12 pl-4 pr-12 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.password ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              @input="clearError('password')"
            />
            <button type="button" class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors" aria-label="Toggle password visibility" @click="showPassword = !showPassword">
              <svg v-if="!showPassword" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                <line x1="1" y1="1" x2="23" y2="23" />
              </svg>
              <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
            </button>
          </div>
          <p v-if="fieldErrors.password" class="text-[12px] text-brand">{{ fieldErrors.password }}</p>
          <PasswordRequirementsChecklist :password="form.password" />
        </div>

        <div class="flex flex-col gap-2">
          <label for="confirm-password" class="text-[13px] text-label">Confirm Password</label>
          <div class="relative">
            <input
              id="confirm-password"
              :ref="(el) => (fieldRefs.password_confirmation.value = el)"
              v-model="form.password_confirmation"
              :type="showConfirm ? 'text' : 'password'"
              autocomplete="new-password"
              class="w-full h-12 pl-4 pr-12 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.password_confirmation ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              @input="clearError('password_confirmation')"
            />
            <button type="button" class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors" aria-label="Toggle password visibility" @click="showConfirm = !showConfirm">
              <svg v-if="!showConfirm" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                <line x1="1" y1="1" x2="23" y2="23" />
              </svg>
              <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
            </button>
          </div>
          <p v-if="fieldErrors.password_confirmation" class="text-[12px] text-brand">{{ fieldErrors.password_confirmation }}</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button
            type="submit"
            class="min-w-[220px] px-8 py-3.5 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.08em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Resetting…' : 'Reset Password' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
