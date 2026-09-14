<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import { PASSWORD_RULES } from '../../utils/passwordRules'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const userId = computed(() => route.params.id || null)
const isEdit = computed(() => !!userId.value)

const form = reactive({
  name: '',
  username: '',
  email: '',
  phone_no: '',
  password: '',
  password_confirmation: '',
  role_ids: [],
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')
const showPassword = ref(false)
const showPasswordConfirm = ref(false)

const fieldErrors = reactive({
  name: '',
  username: '',
  email: '',
  phone_no: '',
  password: '',
  password_confirmation: '',
  role_ids: '',
})
const FIELD_ORDER = ['name', 'username', 'email', 'phone_no', 'password', 'password_confirmation', 'role_ids']
const fieldRefs = {
  name: ref(null),
  username: ref(null),
  email: ref(null),
  phone_no: ref(null),
  password: ref(null),
  password_confirmation: ref(null),
  role_ids: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

let initialForm = { ...form, role_ids: [] }

// Role checkboxes — same native checkbox-fieldset pattern as the
// Permissions picker on RoleFormView.
const availableRoles = ref([])
const rolesLoading = ref(true)
const rolesError = ref('')

async function loadRoles() {
  rolesLoading.value = true
  rolesError.value = ''
  try {
    // RoleController::index has no ?status=all escape hatch — this relies
    // on per_page being clamped to the backend's own max (see
    // config/pagination.php) rather than an unpaginated "all" branch.
    const res = await api.get('/roles', { params: { per_page: 100 } })
    availableRoles.value = res.data.data.items
  } catch (err) {
    rolesError.value = err.response?.data?.message || 'Could not load roles.'
  } finally {
    rolesLoading.value = false
  }
}

async function loadUser() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/users/${userId.value}`)
    const user = res.data.data
    form.name = user.name
    form.username = user.username
    form.email = user.email
    form.phone_no = user.phone_no || ''
    form.role_ids = (user.roles || []).map((r) => r.id)
    initialForm = { ...form, role_ids: [...form.role_ids] }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this user.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, initialForm, { password: '', password_confirmation: '', role_ids: [...initialForm.role_ids] })
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  loadRoles()
  if (isEdit.value) loadUser()
})

function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([field, messages]) => {
      const key = field.startsWith('role_ids') ? 'role_ids' : field
      if (key in fieldErrors) fieldErrors[key] = messages[0]
    })
    if (FIELD_ORDER.some((key) => fieldErrors[key])) {
      focusFirstError()
      return
    }
  }
  formError.value = data?.message || 'Could not save user.'
}

// Replaces the native `required` attribute — styled error + red border
// instead of a browser validation bubble, like the rest of this app's forms.
function validate() {
  if (!form.name.trim()) fieldErrors.name = 'Name is required.'
  if (!form.username.trim()) fieldErrors.username = 'Username is required.'
  if (!form.email.trim()) fieldErrors.email = 'Email is required.'

  // Required on create (StoreUserRequest); optional on edit — leave blank
  // to keep the current password (UpdateUserRequest).
  if (!isEdit.value && !form.password) {
    fieldErrors.password = 'Password is required.'
  } else if (form.password) {
    const unmet = PASSWORD_RULES.filter((rule) => !rule.test(form.password))
    if (unmet.length) fieldErrors.password = `Missing: ${unmet.map((rule) => rule.label.toLowerCase()).join(', ')}.`
  }
  if (form.password && !form.password_confirmation) fieldErrors.password_confirmation = 'Please confirm the password.'
  if (form.password && form.password !== form.password_confirmation) {
    fieldErrors.password_confirmation = 'Passwords do not match.'
  }

  return FIELD_ORDER.every((key) => !fieldErrors[key])
}

async function submitForm() {
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))

  if (!validate()) {
    focusFirstError()
    return
  }

  saving.value = true
  try {
    const payload = {
      name: form.name,
      username: form.username,
      email: form.email,
      phone_no: form.phone_no || null,
    }
    if (form.password) {
      payload.password = form.password
      payload.password_confirmation = form.password_confirmation
    }

    let id = userId.value
    if (isEdit.value) {
      await api.put(`/users/${id}`, payload)
    } else {
      const res = await api.post('/users', payload)
      id = res.data.data.id
    }
    // Roles are synced separately (see UserController::syncRoles) — always
    // called so a freshly-created user with roles checked ends up with
    // them too, not just a role-less account.
    await api.post(`/users/${id}/roles/sync`, { role_ids: form.role_ids })

    router.push({ name: 'configurations-users' })
    toast.success(isEdit.value ? 'User updated successfully.' : 'User created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'configurations-users' })
}
</script>

<template>
  <div>
    <div class="max-w-[1100px] mx-auto">
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit User' : 'Add User' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this user's details and roles." : 'Create a new user and assign roles.' }}
            </p>
          </div>
        </div>
        <button
          type="button"
          class="mt-0.5 shrink-0 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="cancel"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          Back
        </button>
      </div>

      <p v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

      <form v-else class="space-y-4" @submit.prevent="submitForm">
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">User Details</h2>
          <p class="text-[13px] text-muted mb-3">Name, username and email are required. Username and email must be unique.</p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. Priya Sharma"
                autofocus
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="username" class="text-[13px] text-label">Username <span class="text-brand">*</span></label>
              <input
                id="username"
                :ref="(el) => (fieldRefs.username.value = el)"
                v-model="form.username"
                type="text"
                placeholder="e.g. priya-sharma"
                @input="clearFieldError('username')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.username ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.username" class="text-[12px] text-brand">{{ fieldErrors.username }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="email" class="text-[13px] text-label">Email <span class="text-brand">*</span></label>
              <input
                id="email"
                :ref="(el) => (fieldRefs.email.value = el)"
                v-model="form.email"
                type="email"
                placeholder="e.g. priya@example.com"
                @input="clearFieldError('email')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.email ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.email" class="text-[12px] text-brand">{{ fieldErrors.email }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="phone_no" class="text-[13px] text-label">Phone No</label>
              <input
                id="phone_no"
                :ref="(el) => (fieldRefs.phone_no.value = el)"
                v-model="form.phone_no"
                type="text"
                placeholder="e.g. 9876543210"
                @input="clearFieldError('phone_no')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.phone_no ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.phone_no" class="text-[12px] text-brand">{{ fieldErrors.phone_no }}</p>
            </div>
          </div>
        </section>

        <!-- Account Security -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1 flex items-center gap-1.5">
            Account Security
            <span class="relative inline-flex group">
              <button
                type="button"
                tabindex="-1"
                class="w-4 h-4 rounded-full border border-gray-300 text-gray-400 hover:text-brand-blue hover:border-brand-blue flex items-center justify-center text-[10px] font-bold leading-none transition-colors"
                aria-label="Password requirements"
              >
                i
              </button>
              <div
                class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-2 w-max max-w-[90vw] rounded-xl bg-gray-900 text-white text-[12px] leading-relaxed p-3 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity z-20 shadow-lg"
              >
                <p class="font-semibold mb-1">Password must include:</p>
                <ul class="list-disc list-inside space-y-0.5">
                  <li v-for="rule in PASSWORD_RULES" :key="rule.label" class="whitespace-nowrap">{{ rule.label }}</li>
                </ul>
                <span class="absolute left-1/2 -translate-x-1/2 top-full w-2.5 h-2.5 bg-gray-900 rotate-45 -mt-1"></span>
              </div>
            </span>
          </h2>
          <p class="text-[13px] text-muted mb-3">
            {{ isEdit ? 'Leave blank to keep the current password.' : 'Required. Must meet the rules below.' }}
          </p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="password" class="text-[13px] text-label">Password <span v-if="!isEdit" class="text-brand">*</span></label>
              <div class="relative">
                <input
                  id="password"
                  :ref="(el) => (fieldRefs.password.value = el)"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Enter password"
                  autocomplete="new-password"
                  @input="clearFieldError('password')"
                  class="w-full h-10 pl-3 pr-10 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.password ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <button
                  type="button"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                  aria-label="Toggle password visibility"
                  @click="showPassword = !showPassword"
                >
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
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="password_confirmation" class="text-[13px] text-label">Confirm Password <span v-if="!isEdit" class="text-brand">*</span></label>
              <div class="relative">
                <input
                  id="password_confirmation"
                  :ref="(el) => (fieldRefs.password_confirmation.value = el)"
                  v-model="form.password_confirmation"
                  :type="showPasswordConfirm ? 'text' : 'password'"
                  placeholder="Re-enter password"
                  autocomplete="new-password"
                  @input="clearFieldError('password_confirmation')"
                  class="w-full h-10 pl-3 pr-10 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.password_confirmation ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <button
                  type="button"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                  aria-label="Toggle password visibility"
                  @click="showPasswordConfirm = !showPasswordConfirm"
                >
                  <svg v-if="!showPasswordConfirm" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
          </div>
        </section>

        <!-- Role multi-select — same native checkbox-fieldset pattern as
             RoleFormView's Permissions picker. -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Roles</h2>
          <p class="text-[13px] text-muted mb-3">Optionally assign one or more roles to this user.</p>

          <p v-if="rolesLoading" class="text-sm text-muted py-4">Loading roles&hellip;</p>
          <p v-else-if="rolesError" class="text-sm text-brand py-4">{{ rolesError }}</p>
          <p v-else-if="!availableRoles.length" class="text-sm text-muted py-4">No roles available yet.</p>

          <fieldset
            v-else
            :ref="(el) => (fieldRefs.role_ids.value = el)"
            tabindex="-1"
            class="rounded-2xl border p-3"
            :class="fieldErrors.role_ids ? 'border-brand' : 'border-input-border bg-page-bg/60'"
          >
            <legend class="px-2 text-[13px] font-medium text-label">Available Roles</legend>
            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              <label v-for="role in availableRoles" :key="role.id" class="form-check">
                <input
                  type="checkbox"
                  class="form-checkbox"
                  :value="role.id"
                  v-model="form.role_ids"
                  @change="clearFieldError('role_ids')"
                />
                <span>{{ role.name }}</span>
              </label>
            </div>
          </fieldset>
          <p v-if="fieldErrors.role_ids" class="text-[12px] text-brand mt-2">{{ fieldErrors.role_ids }}</p>
        </section>

        <p v-if="formError" class="text-[13px] text-brand text-center sm:text-right">{{ formError }}</p>

        <div class="flex flex-wrap gap-3 justify-center sm:justify-end pb-2">
          <button
            type="button"
            class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="saving"
            @click="resetForm"
          >
            Reset
          </button>
          <button
            type="button"
            class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
            :disabled="saving"
            @click="cancel"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="min-w-[160px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create User' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
