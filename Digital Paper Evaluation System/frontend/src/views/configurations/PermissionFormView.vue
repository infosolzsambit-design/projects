<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import SearchableSelect from '../../components/common/SearchableSelect.vue'
import { finalizeSlug, slugify, SLUG_PATTERN } from '../../utils/slugify'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const permissionId = computed(() => route.params.id || null)
const isEdit = computed(() => !!permissionId.value)

const form = reactive({
  permission_group_id: '',
  permission_sub_group_id: '',
  name: '',
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const fieldErrors = reactive({
  permission_group_id: '',
  permission_sub_group_id: '',
  name: '',
})
const FIELD_ORDER = ['permission_group_id', 'permission_sub_group_id', 'name']
const fieldRefs = {
  permission_group_id: ref(null),
  permission_sub_group_id: ref(null),
  name: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

let initialForm = { ...form }

// Group dropdown — active only (see PermissionGroupsView's is_active filter
// convention). Sub Group dropdown is derived from this same fetch-once list
// of all active sub-groups, filtered to whichever group is selected — so
// picking a group "auto-populates" the sub-group options without another
// round-trip.
const availableGroups = ref([])
const groupsLoading = ref(true)
const groupsError = ref('')

const allSubGroups = ref([])
const subGroupsLoading = ref(true)
const subGroupsError = ref('')

const availableSubGroups = computed(() =>
  allSubGroups.value.filter((sg) => String(sg.permission_group_id) === String(form.permission_group_id)),
)

async function loadGroups() {
  groupsLoading.value = true
  groupsError.value = ''
  try {
    const res = await api.get('/permission-groups', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableGroups.value = res.data.data
  } catch (err) {
    groupsError.value = err.response?.data?.message || 'Could not load permission groups.'
  } finally {
    groupsLoading.value = false
  }
}

async function loadSubGroups() {
  subGroupsLoading.value = true
  subGroupsError.value = ''
  try {
    const res = await api.get('/permission-sub-groups', {
      params: { status: 'all', is_active: 'yes', table_fields: ['name', 'permission_group_id'] },
    })
    allSubGroups.value = res.data.data
  } catch (err) {
    subGroupsError.value = err.response?.data?.message || 'Could not load permission sub groups.'
  } finally {
    subGroupsLoading.value = false
  }
}

function onGroupChange() {
  clearFieldError('permission_group_id')
  // Selecting a different group invalidates whatever sub-group was picked
  // under the old one.
  form.permission_sub_group_id = ''
}

async function loadPermission() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/permissions/${permissionId.value}`)
    const permission = res.data.data
    form.permission_group_id = permission.permission_group_id || ''
    form.permission_sub_group_id = permission.permission_sub_group_id || ''
    form.name = permission.name
    initialForm = { ...form }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this permission.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, initialForm)
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  loadGroups()
  loadSubGroups()
  if (isEdit.value) loadPermission()
})

function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([field, messages]) => {
      if (field in fieldErrors) fieldErrors[field] = messages[0]
    })
    if (FIELD_ORDER.some((key) => fieldErrors[key])) {
      focusFirstError()
      return
    }
  }
  formError.value = data?.message || 'Could not save permission.'
}

// Live-slugifies as you type — "Paper View" / "paper view" / "One two
// THREE" all become "paper-view" / "one-two-three" (see utils/slugify.js).
// Keeps a trailing hyphen mid-typing (right after a space) rather than
// eating it; blur/submit trims that via finalizeSlug().
function onNameInput() {
  form.name = slugify(form.name)
  clearFieldError('name')
}
function onNameBlur() {
  form.name = finalizeSlug(form.name)
}

function validate() {
  if (!form.permission_group_id) fieldErrors.permission_group_id = 'Permission group is required.'
  if (!form.permission_sub_group_id) fieldErrors.permission_sub_group_id = 'Permission sub group is required.'
  form.name = finalizeSlug(form.name)
  if (!form.name) {
    fieldErrors.name = 'Name is required.'
  } else if (!SLUG_PATTERN.test(form.name)) {
    fieldErrors.name = 'Only lowercase letters, numbers and hyphens are allowed (e.g. paper-view).'
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
    const payload = { ...form }
    if (isEdit.value) {
      await api.put(`/permissions/${permissionId.value}`, payload)
    } else {
      await api.post('/permissions', payload)
    }
    router.push({ name: 'configurations-permissions' })
    toast.success(isEdit.value ? 'Permission updated successfully.' : 'Permission created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'configurations-permissions' })
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
                <rect x="3" y="11" width="18" height="10" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Permission' : 'Add Permission' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this permission's details." : 'Choose a group and sub group, then name the permission.' }}
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
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Permission Details</h2>
          <p class="text-[13px] text-muted mb-3">Pick the group, then its sub group, then a unique name. Whatever you type is auto-converted to lowercase-with-hyphens (e.g. "Paper View" becomes "paper-view") — no other special characters allowed.</p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="permission_group_id" class="text-[13px] text-label">Permission Group <span class="text-brand">*</span></label>
              <SearchableSelect
                id="permission_group_id"
                :ref="(el) => (fieldRefs.permission_group_id.value = el)"
                v-model="form.permission_group_id"
                :options="availableGroups"
                :loading="groupsLoading"
                :error="!!fieldErrors.permission_group_id"
                placeholder="Select permission group"
                search-placeholder="Search permission groups…"
                @change="onGroupChange"
              />
              <p v-if="groupsError" class="text-[12px] text-brand">{{ groupsError }}</p>
              <p v-else-if="fieldErrors.permission_group_id" class="text-[12px] text-brand">{{ fieldErrors.permission_group_id }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="permission_sub_group_id" class="text-[13px] text-label">Permission Sub Group <span class="text-brand">*</span></label>
              <SearchableSelect
                id="permission_sub_group_id"
                :ref="(el) => (fieldRefs.permission_sub_group_id.value = el)"
                v-model="form.permission_sub_group_id"
                :options="availableSubGroups"
                :loading="subGroupsLoading"
                :disabled="!form.permission_group_id"
                :error="!!fieldErrors.permission_sub_group_id"
                :placeholder="form.permission_group_id ? 'Select permission sub group' : 'Select a group first'"
                search-placeholder="Search permission sub groups…"
                @change="clearFieldError('permission_sub_group_id')"
              />
              <p v-if="subGroupsError" class="text-[12px] text-brand">{{ subGroupsError }}</p>
              <p v-else-if="fieldErrors.permission_sub_group_id" class="text-[12px] text-brand">{{ fieldErrors.permission_sub_group_id }}</p>
              <p v-else-if="form.permission_group_id && !availableSubGroups.length && !subGroupsLoading" class="text-[12px] text-muted">No active sub groups under this group yet.</p>
            </div>
            <div class="flex flex-col gap-1.5 md:col-span-2">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. Paper View → paper-view"
                @input="onNameInput"
                @blur="onNameBlur"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
          </div>
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
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Permission' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
