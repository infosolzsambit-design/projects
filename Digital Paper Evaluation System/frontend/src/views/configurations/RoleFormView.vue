<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const roleId = computed(() => route.params.id || null)
const isEdit = computed(() => !!roleId.value)

const form = reactive({
  name: '',
  permission_ids: [],
})

const loading = ref(isEdit.value)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const fieldErrors = reactive({
  name: '',
  permission_ids: '',
})
const FIELD_ORDER = ['name', 'permission_ids']
const fieldRefs = {
  name: ref(null),
  permission_ids: ref(null),
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

let initialForm = { ...form, permission_ids: [] }

// Permission checkboxes, grouped by their actual Permission Group / Sub
// Group (see PermissionResource's nested permission_group/permission_sub_group
// — set on Configurations > Permissions) — same native checkbox-fieldset
// pattern as ProgramFormView's Courses picker, just nested two levels deep
// instead of one flat grid.
const availablePermissions = ref([])
const permissionsLoading = ref(true)
const permissionsError = ref('')
const permissionSearch = ref('')

async function loadPermissions() {
  permissionsLoading.value = true
  permissionsError.value = ''
  try {
    // ?status=all → every permission, unpaginated — no max_per_page cap
    // (see PermissionController::index).
    const res = await api.get('/permissions', { params: { status: 'all' } })
    availablePermissions.value = res.data.data
  } catch (err) {
    permissionsError.value = err.response?.data?.message || 'Could not load permissions.'
  } finally {
    permissionsLoading.value = false
  }
}

// Sorts last (Infinity) — an ungrouped/legacy permission has no sort_order
// of its own to rank by.
const UNGROUPED = { id: '_ungrouped', name: 'Ungrouped', sort_order: Infinity }

function bySortOrder([, a], [, b]) {
  return a.sort_order - b.sort_order || a.name.localeCompare(b.name)
}

const groupedPermissions = computed(() => {
  const query = permissionSearch.value.trim().toLowerCase()
  const groups = new Map() // groupId -> { name, sort_order, subGroups: Map(subGroupId -> { name, sort_order, items }) }

  for (const permission of availablePermissions.value) {
    if (query && !permission.name.toLowerCase().includes(query)) continue

    const group = permission.permission_group || UNGROUPED
    const subGroup = permission.permission_sub_group || UNGROUPED

    if (!groups.has(group.id)) groups.set(group.id, { name: group.name, sort_order: group.sort_order ?? 0, subGroups: new Map() })
    const subGroups = groups.get(group.id).subGroups
    if (!subGroups.has(subGroup.id)) subGroups.set(subGroup.id, { name: subGroup.name, sort_order: subGroup.sort_order ?? 0, items: [] })
    subGroups.get(subGroup.id).items.push(permission)
  }

  return [...groups.entries()]
    .sort(bySortOrder)
    .map(([groupId, { name, subGroups }]) => {
      const orderedSubGroups = [...subGroups.entries()]
        .sort(bySortOrder)
        .map(([subGroupId, sg]) => ({ subGroupId, ...sg }))
      return {
        groupId,
        name,
        subGroups: orderedSubGroups,
        // Every permission id across all this group's sub-groups — what
        // the group-level "Select All" checkbox below toggles as one unit.
        allIds: orderedSubGroups.flatMap((sg) => sg.items.map((p) => p.id)),
      }
    })
})

// --- "Select All", group- and sub-group-wise — checks/unchecks every
// permission id in `ids` at once against form.permission_ids. Shown as
// checked only once every one of them is already selected (so partial
// selections read as unchecked, not a misleading "all selected").
function isAllSelected(ids) {
  return ids.length > 0 && ids.every((id) => form.permission_ids.includes(id))
}
function toggleAllSelected(ids) {
  if (isAllSelected(ids)) {
    form.permission_ids = form.permission_ids.filter((id) => !ids.includes(id))
  } else {
    form.permission_ids = [...new Set([...form.permission_ids, ...ids])]
  }
  clearFieldError('permission_ids')
}

async function loadRole() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/roles/${roleId.value}`)
    const role = res.data.data
    form.name = role.name
    form.permission_ids = (role.permissions || []).map((p) => p.id)
    initialForm = { ...form, permission_ids: [...form.permission_ids] }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load this role.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  form.name = initialForm.name
  form.permission_ids = [...initialForm.permission_ids]
  formError.value = ''
  FIELD_ORDER.forEach((key) => (fieldErrors[key] = ''))
}

onMounted(() => {
  loadPermissions()
  if (isEdit.value) loadRole()
})

function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([field, messages]) => {
      const key = field.startsWith('permission_ids') ? 'permission_ids' : field
      if (key in fieldErrors) fieldErrors[key] = messages[0]
    })
    if (FIELD_ORDER.some((key) => fieldErrors[key])) {
      focusFirstError()
      return
    }
  }
  formError.value = data?.message || 'Could not save role.'
}

function validate() {
  if (!form.name.trim()) fieldErrors.name = 'Name is required.'
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
    let id = roleId.value
    if (isEdit.value) {
      await api.put(`/roles/${id}`, { name: form.name })
    } else {
      const res = await api.post('/roles', { name: form.name })
      id = res.data.data.id
    }
    // Permissions are synced separately (see RoleController::syncPermissions)
    // — always called so a freshly-created role with permissions checked
    // ends up with them too, not just an empty role.
    await api.post(`/roles/${id}/permissions/sync`, { permission_ids: form.permission_ids })

    router.push({ name: 'configurations-roles' })
    toast.success(isEdit.value ? 'Role updated successfully.' : 'Role created successfully.')
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'configurations-roles' })
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
                <path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12.01V2h10.01l8.58 8.58a2 2 0 0 1 0 2.83z" />
                <circle cx="7" cy="7" r="1.5" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">{{ isEdit ? 'Edit Role' : 'Add Role' }}</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">
              {{ isEdit ? "Update this role's name and permissions." : 'Create a new role and choose its permissions.' }}
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
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Role Details</h2>
          <p class="text-[13px] text-muted mb-3">Name is required and must be unique.</p>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <label for="name" class="text-[13px] text-label">Name <span class="text-brand">*</span></label>
              <input
                id="name"
                :ref="(el) => (fieldRefs.name.value = el)"
                v-model="form.name"
                type="text"
                placeholder="e.g. Evaluator"
                autofocus
                @input="clearFieldError('name')"
                class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                :class="fieldErrors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
              />
              <p v-if="fieldErrors.name" class="text-[12px] text-brand">{{ fieldErrors.name }}</p>
            </div>
          </div>
        </section>

        <!-- Permission multi-select — native checkbox-fieldset pattern from
             ProgramFormView's Courses picker, grouped into per-module
             sub-sections instead of one flat grid. -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900">Permissions</h2>
            <input
              v-model="permissionSearch"
              type="text"
              placeholder="Search permissions…"
              class="w-full sm:w-60 h-9 px-3 rounded-lg bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            />
          </div>
          <p class="text-[13px] text-muted mb-3">Optionally choose the permissions this role grants.</p>

          <p v-if="permissionsLoading" class="text-sm text-muted py-4">Loading permissions&hellip;</p>
          <p v-else-if="permissionsError" class="text-sm text-brand py-4">{{ permissionsError }}</p>
          <p v-else-if="!availablePermissions.length" class="text-sm text-muted py-4">No permissions available yet.</p>

          <fieldset
            v-else
            :ref="(el) => (fieldRefs.permission_ids.value = el)"
            tabindex="-1"
            class="space-y-4 rounded-2xl"
            :class="fieldErrors.permission_ids ? 'ring-2 ring-brand p-2' : ''"
          >
            <div v-for="group in groupedPermissions" :key="group.groupId" class="rounded-2xl overflow-hidden shadow-card">
              <!-- Group header — colored bar matching this app's list-page
                   section headers (bg-subject-header), so a group reads as
                   its own card instead of a plain checkbox list. -->
              <div class="bg-subject-header px-4 py-3 flex items-center justify-between gap-3">
                <label class="inline-flex items-center gap-2.5 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    class="form-checkbox"
                    :checked="isAllSelected(group.allIds)"
                    @change="toggleAllSelected(group.allIds)"
                  />
                  <span class="text-white text-[14px] font-semibold">{{ group.name }}</span>
                </label>
                <span class="text-white/80 text-[11px] font-medium whitespace-nowrap">
                  {{ group.allIds.filter((id) => form.permission_ids.includes(id)).length }}/{{ group.allIds.length }} selected
                </span>
              </div>

              <div class="bg-white p-3 sm:p-4 space-y-3">
                <div v-for="subGroup in group.subGroups" :key="subGroup.subGroupId" class="rounded-xl bg-page-bg/60 p-3">
                  <label class="inline-flex items-center gap-2 cursor-pointer select-none mb-2.5">
                    <input
                      type="checkbox"
                      class="form-checkbox"
                      :checked="isAllSelected(subGroup.items.map((p) => p.id))"
                      @change="toggleAllSelected(subGroup.items.map((p) => p.id))"
                    />
                    <span class="text-[12px] font-semibold text-gray-800">{{ subGroup.name }}</span>
                  </label>

                  <!-- Permission chips — a checkbox each, styled as a
                       toggle pill (same filled-vs-outline language as this
                       app's status badges) instead of a plain checkbox row. -->
                  <div class="flex flex-wrap gap-2">
                    <label
                      v-for="permission in subGroup.items"
                      :key="permission.id"
                      class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12px] font-medium cursor-pointer select-none transition-colors"
                      :class="form.permission_ids.includes(permission.id)
                        ? 'bg-btn-gradient text-white border-transparent shadow-sm'
                        : 'bg-white text-gray-700 border-input-border hover:border-brand-blue hover:text-brand-blue'"
                    >
                      <input
                        type="checkbox"
                        class="sr-only"
                        :value="permission.id"
                        v-model="form.permission_ids"
                        @change="clearFieldError('permission_ids')"
                      />
                      <svg v-if="form.permission_ids.includes(permission.id)" class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12" />
                      </svg>
                      {{ permission.name }}
                    </label>
                  </div>
                </div>
              </div>
            </div>
            <p v-if="!groupedPermissions.length" class="text-sm text-muted">No permissions match your search.</p>
          </fieldset>
          <p v-if="fieldErrors.permission_ids" class="text-[12px] text-brand mt-2">{{ fieldErrors.permission_ids }}</p>
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
            {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Role' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
