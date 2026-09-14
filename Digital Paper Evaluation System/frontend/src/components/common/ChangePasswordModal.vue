<script setup>
import { reactive, ref } from 'vue'
import api from '../../utils/api'
import { PASSWORD_RULES } from '../../utils/passwordRules'
import PasswordRequirementsChecklist from './PasswordRequirementsChecklist.vue'

// Change-password modal — reachable from the header's user menu (see
// AppHeader.vue). Based on designed_files/change-password.html, but shown
// as a modal instead of navigating to a full page. Hits the same endpoint
// the forced first-login flow uses (see AuthController::changePassword()):
// the current session's token stays valid, every *other* session for this
// user gets signed out.
const emit = defineEmits(['close'])

const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const fieldErrors = reactive({ current_password: '', password: '', password_confirmation: '' })
// Order matters — matches the form's own top-to-bottom field order, so the
// first of these (in this order) that has an error is the one that gets
// focused after a failed submit (client-side or server-side).
const FIELD_ORDER = ['current_password', 'password', 'password_confirmation']
const fieldRefs = { current_password: ref(null), password: ref(null), password_confirmation: ref(null) }
function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}
const formError = ref('')
const saving = ref(false)
const done = ref(false)

const showCurrent = ref(false)
const showNew = ref(false)
const showConfirm = ref(false)

function clearError(field) {
  fieldErrors[field] = ''
  formError.value = ''
}

function validate() {
  fieldErrors.current_password = ''
  fieldErrors.password = ''
  fieldErrors.password_confirmation = ''

  if (!form.current_password) fieldErrors.current_password = 'Enter your current password.'

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

  return !fieldErrors.current_password && !fieldErrors.password && !fieldErrors.password_confirmation
}

async function submit() {
  formError.value = ''
  if (!validate()) {
    focusFirstError()
    return
  }

  saving.value = true
  try {
    await api.post('/change-password', { ...form })
    done.value = true
    setTimeout(() => emit('close'), 1200)
  } catch (err) {
    const data = err.response?.data
    if (data?.message === 'The current password is incorrect.') {
      fieldErrors.current_password = data.message
      focusFirstError()
    } else if (data?.errors) {
      Object.entries(data.errors).forEach(([field, messages]) => {
        if (field in fieldErrors) fieldErrors[field] = messages[0]
      })
      focusFirstError()
    } else {
      formError.value = data?.message || 'Could not change your password.'
    }
  } finally {
    saving.value = false
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="close">
    <div class="w-full max-w-[460px] rounded-[28px] bg-white shadow-panel overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
        <h2 class="text-[18px] font-semibold text-gray-900">Change Password</h2>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors" aria-label="Close" @click="close">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div v-if="done" class="p-8 flex flex-col items-center text-center gap-3">
        <span class="w-14 h-14 rounded-full bg-success/10 text-success flex items-center justify-center">
          <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
        </span>
        <p class="text-sm font-semibold text-gray-900">Password changed successfully.</p>
        <p class="text-[12px] text-muted">Your other sessions have been signed out.</p>
      </div>

      <form v-else class="p-5 flex flex-col gap-4" @submit.prevent="submit">
        <p v-if="formError" class="text-[13px] text-brand text-center">{{ formError }}</p>

        <div class="flex flex-col gap-1.5">
          <label for="current-password" class="text-[13px] text-label">Old Password</label>
          <div class="relative">
            <input
              id="current-password"
              :ref="(el) => (fieldRefs.current_password.value = el)"
              v-model="form.current_password"
              :type="showCurrent ? 'text' : 'password'"
              autocomplete="current-password"
              class="w-full h-11 pl-4 pr-11 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.current_password ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              @input="clearError('current_password')"
            />
            <button
              type="button"
              class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
              aria-label="Toggle password visibility"
              @click="showCurrent = !showCurrent"
            >
              <svg v-if="!showCurrent" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
          <p v-if="fieldErrors.current_password" class="text-[12px] text-brand">{{ fieldErrors.current_password }}</p>
        </div>

        <div class="flex flex-col gap-1.5">
          <label for="new-password" class="text-[13px] text-label">New Password</label>
          <div class="relative">
            <input
              id="new-password"
              :ref="(el) => (fieldRefs.password.value = el)"
              v-model="form.password"
              :type="showNew ? 'text' : 'password'"
              autocomplete="new-password"
              class="w-full h-11 pl-4 pr-11 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.password ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              @input="clearError('password')"
            />
            <button
              type="button"
              class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
              aria-label="Toggle password visibility"
              @click="showNew = !showNew"
            >
              <svg v-if="!showNew" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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

        <div class="flex flex-col gap-1.5">
          <label for="confirm-password" class="text-[13px] text-label">Confirm Password</label>
          <div class="relative">
            <input
              id="confirm-password"
              :ref="(el) => (fieldRefs.password_confirmation.value = el)"
              v-model="form.password_confirmation"
              :type="showConfirm ? 'text' : 'password'"
              autocomplete="new-password"
              class="w-full h-11 pl-4 pr-11 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.password_confirmation ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              @input="clearError('password_confirmation')"
            />
            <button
              type="button"
              class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
              aria-label="Toggle password visibility"
              @click="showConfirm = !showConfirm"
            >
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
            class="min-w-[220px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.06em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Updating…' : 'Update Password' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
