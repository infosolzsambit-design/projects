<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import FaceCaptureModal from '../components/common/FaceCaptureModal.vue'
import EsignCropperModal from '../components/common/EsignCropperModal.vue'
import { useToast } from '../composables/useToast'
import { useAuthStore } from '../stores/auth'

const toast = useToast()
const authStore = useAuthStore()

const profile = ref(null)
const loading = ref(true)
const loadError = ref('')

async function loadProfile() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/profile')
    profile.value = res.data.data
    resetForm()
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load your profile.'
  } finally {
    loading.value = false
  }
}

const initials = computed(() => {
  const name = profile.value?.name?.trim()
  if (!name) return '?'
  const parts = name.split(/\s+/)
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || name[0].toUpperCase()
})

// Face-scan photo/descriptor only ever persist to teacher_details (see
// ProfileController) — a plain (e.g. Super Admin) user has no
// teacherDetail row to save them to, so the capture UI stays hidden for
// anyone without at least one of these teacher-only fields.
const isTeacher = computed(() => !!(profile.value?.emp_code || profile.value?.designation || profile.value?.department))

// --- Edit / Save (name, gender, location, about only — email/phone/role
// are never editable from here, see UpdateProfileRequest).
const isEditing = ref(false)
const saving = ref(false)
const formError = ref('')
const form = reactive({ name: '', gender: '', location: '', about: '' })

function resetForm() {
  if (!profile.value) return
  form.name = profile.value.name || ''
  form.gender = profile.value.gender || ''
  form.location = profile.value.location || ''
  form.about = profile.value.about || ''
}

function startEdit() {
  formError.value = ''
  isEditing.value = true
}

function cancelEdit() {
  resetForm()
  formError.value = ''
  isEditing.value = false
}

async function saveProfile() {
  if (!form.name.trim()) {
    formError.value = 'Name is required.'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const res = await api.put('/profile', { ...form })
    profile.value = res.data.data
    isEditing.value = false
    toast.success('Profile updated successfully.')
  } catch (err) {
    formError.value = err.response?.data?.message || 'Could not save your profile.'
  } finally {
    saving.value = false
  }
}

// --- Face-scan profile photo. Capture UI lives in the shared
// FaceCaptureModal (also used from the Teachers list — see
// TeachersView.vue) — this just opens/closes it and applies whatever it
// hands back on save. Persisted against the logged-in user's teacherDetail
// (see ProfileController::updateFace()) — this is the face checked before
// starting to check a paper (see ReviewDetailView.vue).
const showCaptureModal = ref(false)

function onFaceSaved(data) {
  profile.value = data
}

// --- E-signature. Same upload-modal pattern as the face scan above, just
// with EsignCropperModal (pick a file, crop it) instead of a live camera.
// Also persisted against teacherDetail (see ProfileController::updateEsign()).
const showEsignModal = ref(false)

function onEsignSaved(data) {
  profile.value = data
  // Refreshes profile_completion_required — see router/index.js's own
  // beforeEach guard, which reads this straight off authStore.user and
  // otherwise keeps parking this user back here on every navigation.
  authStore.fetchMe().catch(() => {})
}

onMounted(loadProfile)
</script>

<template>
  <div>
    <div class="max-w-[1100px] mx-auto">
      <div v-if="authStore.user?.profile_completion_required" class="mb-4 rounded-2xl bg-soft border border-chip-border px-4 py-3 flex items-start gap-2.5 text-[13px] text-brand-blue">
        <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
        <span>Please upload your e-signature below before continuing — it's required for evaluating answer sheets.</span>
      </div>

      <!-- Page header, matching designed_files/profile.html's icon-badge + title header -->
      <div class="flex items-start gap-3 mb-5">
        <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
          <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
            <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
          </span>
        </span>
        <div>
          <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Profile</h1>
          <p class="mt-1 text-[13px] sm:text-sm text-muted">Manage your personal information and account details from one place.</p>
        </div>
      </div>

      <p v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

      <div v-else class="grid grid-cols-1 min-[992px]:grid-cols-[320px_minmax(0,1fr)] gap-6">
        <!-- Left card: avatar + quick info -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <div class="flex flex-col items-center text-center min-w-0 w-full">
            <div class="relative w-24 h-24 sm:w-28 sm:h-28 rounded-full bg-btn-gradient p-[3px] shadow-card shrink-0">
              <img
                v-if="profile.photo"
                :src="profile.photo"
                :alt="profile.name"
                class="w-full h-full rounded-full object-cover bg-white"
              />
              <span v-else class="w-full h-full rounded-full bg-white flex items-center justify-center text-2xl font-semibold text-brand-blue">
                {{ initials }}
              </span>
              <button
                v-if="isTeacher"
                type="button"
                class="absolute inset-[3px] rounded-full bg-black/0 hover:bg-black/45 flex items-center justify-center cursor-pointer transition-colors group"
                aria-label="Scan a new profile photo"
                @click="showCaptureModal = true"
              >
                <svg class="w-7 h-7 text-white opacity-0 group-hover:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                  <circle cx="12" cy="13" r="4" />
                </svg>
              </button>
            </div>
            <h2 class="mt-4 w-full text-[18px] sm:text-[20px] font-semibold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis px-1">{{ profile.name }}</h2>
            <p class="mt-1 w-full text-sm text-muted whitespace-nowrap overflow-hidden text-ellipsis px-1">{{ profile.role || '—' }}</p>
            <span
              class="mt-3 inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-semibold tracking-[0.04em] whitespace-nowrap"
              :class="profile.is_active ? 'bg-soft text-brand-blue' : 'bg-brand/10 text-brand'"
            >
              {{ profile.is_active ? 'ACTIVE ACCOUNT' : 'INACTIVE ACCOUNT' }}
            </span>
            <button
              v-if="isTeacher"
              type="button"
              class="mt-3 text-[12px] font-medium text-brand-blue hover:underline"
              @click="showCaptureModal = true"
            >
              {{ profile.has_face_profile ? 'Update face scan' : 'Set up face scan' }}
            </button>
          </div>

          <div class="mt-6 space-y-3">
            <div class="rounded-2xl bg-page-bg px-3 py-2.5 min-w-0">
              <p class="text-[12px] text-label">Email Address</p>
              <p class="mt-1 text-sm font-medium text-gray-800 whitespace-nowrap overflow-hidden text-ellipsis">{{ profile.email }}</p>
            </div>
            <div class="rounded-2xl bg-page-bg px-3 py-2.5 min-w-0">
              <p class="text-[12px] text-label">Phone Number</p>
              <p class="mt-1 text-sm font-medium text-gray-800 whitespace-nowrap overflow-hidden text-ellipsis">{{ profile.phone_no || '—' }}</p>
            </div>
            <div class="rounded-2xl bg-page-bg px-3 py-2.5 min-w-0">
              <p class="text-[12px] text-label">Department</p>
              <p class="mt-1 text-sm font-medium text-gray-800 whitespace-nowrap overflow-hidden text-ellipsis">{{ profile.department || '—' }}</p>
            </div>
          </div>
        </section>

        <!-- Right card: editable details -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <label for="profile-name" class="text-[13px] text-label">Name</label>
              <input
                id="profile-name"
                v-model="form.name"
                type="text"
                :readonly="!isEditing"
                class="w-full h-12 sm:h-[52px] px-4 rounded-xl text-sm text-gray-800 outline-none border transition"
                :class="isEditing ? 'bg-white border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15' : 'bg-input-bg border-input-border'"
              />
            </div>
            <div class="flex flex-col gap-2">
              <label for="profile-gender" class="text-[13px] text-label">Gender</label>
              <select
                id="profile-gender"
                v-model="form.gender"
                :disabled="!isEditing"
                class="w-full h-12 sm:h-[52px] px-4 rounded-xl text-sm text-gray-800 outline-none border transition disabled:cursor-not-allowed"
                :class="isEditing ? 'bg-white border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 cursor-pointer' : 'bg-input-bg border-input-border'"
              >
                <option value="">Not specified</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="flex flex-col gap-2">
              <label for="profile-email" class="text-[13px] text-label">Email</label>
              <input id="profile-email" :value="profile.email" type="email" readonly class="w-full h-12 sm:h-[52px] px-4 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border" />
            </div>
            <div class="flex flex-col gap-2">
              <label for="profile-phone" class="text-[13px] text-label">Phone Number</label>
              <input id="profile-phone" :value="profile.phone_no || '—'" type="text" readonly class="w-full h-12 sm:h-[52px] px-4 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border" />
            </div>
            <div class="flex flex-col gap-2">
              <label for="profile-role" class="text-[13px] text-label">Role</label>
              <input id="profile-role" :value="profile.role || '—'" type="text" readonly class="w-full h-12 sm:h-[52px] px-4 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border" />
            </div>
            <div class="flex flex-col gap-2">
              <label for="profile-location" class="text-[13px] text-label">Location</label>
              <input
                id="profile-location"
                v-model="form.location"
                type="text"
                placeholder="e.g. Kolkata, India"
                :readonly="!isEditing"
                class="w-full h-12 sm:h-[52px] px-4 rounded-xl text-sm text-gray-800 outline-none border transition"
                :class="isEditing ? 'bg-white border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15' : 'bg-input-bg border-input-border'"
              />
            </div>
          </div>

          <div class="mt-4 flex flex-col gap-2">
            <label for="profile-about" class="text-[13px] text-label">About</label>
            <textarea
              id="profile-about"
              v-model="form.about"
              rows="5"
              placeholder="A short note about yourself…"
              :readonly="!isEditing"
              class="w-full px-4 py-3 rounded-2xl text-sm text-gray-800 outline-none border transition resize-none"
              :class="isEditing ? 'bg-white border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15' : 'bg-input-bg border-input-border'"
            ></textarea>
          </div>

          <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl bg-page-bg px-3 py-3">
              <p class="text-[12px] text-label">Employee ID</p>
              <p class="mt-1 text-sm font-semibold text-gray-800">{{ profile.emp_code || '—' }}</p>
            </div>
            <div class="rounded-2xl bg-page-bg px-3 py-3">
              <p class="text-[12px] text-label">Designation</p>
              <p class="mt-1 text-sm font-semibold text-gray-800">{{ profile.designation || '—' }}</p>
            </div>
            <div class="rounded-2xl bg-page-bg px-3 py-3">
              <p class="text-[12px] text-label">Status</p>
              <p class="mt-1 text-sm font-semibold" :class="profile.is_active ? 'text-success' : 'text-brand'">
                {{ profile.is_active ? 'Active' : 'Inactive' }}
              </p>
            </div>
          </div>

          <!-- E-Signature — teacher-only, same as the face-scan capture
               above (see isTeacher). Used to sign off on evaluated answer
               sheets. -->
          <div v-if="isTeacher" class="mt-6">
            <p class="text-[12px] text-label mb-2">E-Signature</p>
            <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-page-bg px-4 py-3.5">
              <div class="w-[150px] h-[75px] rounded-xl bg-white border border-input-border flex items-center justify-center shrink-0 overflow-hidden">
                <img v-if="profile.esign" :src="profile.esign" alt="Your e-signature" class="max-w-full max-h-full object-contain" />
                <span v-else class="text-[11px] text-muted text-center px-2">No signature uploaded</span>
              </div>
              <button
                type="button"
                class="px-5 py-2.5 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors"
                @click="showEsignModal = true"
              >
                {{ profile.esign ? 'Update Signature' : 'Upload Signature' }}
              </button>
            </div>
          </div>

          <p v-if="formError" class="mt-4 text-[13px] text-brand text-center sm:text-right">{{ formError }}</p>

          <div v-if="!isEditing" class="pt-5 flex justify-center min-[992px]:justify-end">
            <button
              type="button"
              class="min-w-[140px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all"
              @click="startEdit"
            >
              Edit
            </button>
          </div>
          <div v-else class="pt-5 flex flex-wrap gap-3 justify-center min-[992px]:justify-end">
            <button
              type="button"
              class="px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="saving"
              @click="cancelEdit"
            >
              Cancel
            </button>
            <button
              type="button"
              class="min-w-[200px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="saving"
              @click="saveProfile"
            >
              {{ saving ? 'Saving…' : 'Save Changes' }}
            </button>
          </div>
        </section>
      </div>
    </div>

    <FaceCaptureModal
      v-if="showCaptureModal"
      title="Scan Your Face"
      endpoint="/profile/face"
      @saved="onFaceSaved"
      @close="showCaptureModal = false"
    />

    <EsignCropperModal
      v-if="showEsignModal"
      title="Upload E-Signature"
      endpoint="/profile/esign"
      @saved="onEsignSaved"
      @close="showEsignModal = false"
    />
  </div>
</template>
