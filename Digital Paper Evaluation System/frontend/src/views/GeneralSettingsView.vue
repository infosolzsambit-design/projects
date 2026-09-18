<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../utils/api'
import { useBrandingStore } from '../stores/branding'
import { useToast } from '../composables/useToast'

const branding = useBrandingStore()
const toast = useToast()

// General Settings — a single dynamic form, no list. Each row from
// GET /general-settings both *describes* one field (type/options/...) and
// carries its current value; this component just renders whatever it's
// handed and posts everything back together on Save (see
// GeneralSettingController — no per-field save, one submission for all of
// them, same as the single Save button below).
const fields = ref([])
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const form = reactive({}) // field_name -> string | string[]
const extra = reactive({}) // field_name -> string (only for a radio's has_extra companion)
const fieldErrors = reactive({})
const pendingFiles = reactive({}) // field_name -> File chosen but not yet saved
const previewUrls = reactive({}) // field_name -> object URL for a just-chosen file, revoked on change/unmount
// field_name -> the field's own input/select/textarea/file element — a
// plain object (not reactive) since these are just DOM node handles, not
// state the template needs to react to. Populated by each field's own
// `:ref` callback below (see the template's field-type branches).
const fieldRefs = {}

// Focuses the first field (in the form's own top-to-bottom order — same
// order `fields.value` already renders in) that currently has an error, so
// a failed save doesn't just show a red border somewhere off-screen. Radio/
// checkbox groups don't get a ref (no single element to focus for those),
// so this simply skips a field it has no ref for.
function focusFirstError() {
  const field = fields.value.find((f) => fieldErrors[f.field_name])
  fieldRefs[field?.field_name]?.focus()
}

// Fields with the same group_name render together in their own card (see
// "Branding & Icons" — the app's icon slots, seeded by GeneralSettingSeeder)
// instead of all piling into one undifferentiated form. Ungrouped fields
// (group_name === null) always render first, in their own default card,
// regardless of where a named group happens to sort in — a form this size
// reads better with the "always there" settings up top and any optional
// named sections below.
const groupedFields = computed(() => {
  const sections = []
  const byName = new Map()
  fields.value.forEach((field) => {
    const key = field.group_name || null
    if (!byName.has(key)) {
      const section = { name: key, fields: [] }
      byName.set(key, section)
      sections.push(section)
    }
    byName.get(key).fields.push(field)
  })
  return sections.sort((a, b) => (a.name === null ? -1 : b.name === null ? 1 : 0))
})

function hasExtraOption(field) {
  return field.type === 'radio' && (field.options || []).some((o) => o.has_extra)
}

function selectedExtraLabel(field) {
  const option = (field.options || []).find((o) => o.value === form[field.field_name])
  return option?.has_extra ? option.extra_label || 'Additional value' : null
}

function applyFields(list) {
  fields.value = list
  list.forEach((field) => {
    if (field.type === 'checkbox') {
      try {
        form[field.field_name] = field.value ? JSON.parse(field.value) : []
      } catch {
        form[field.field_name] = []
      }
    } else if (field.type === 'file') {
      form[field.field_name] = field.value || ''
    } else {
      form[field.field_name] = field.value ?? ''
    }
    if (hasExtraOption(field)) extra[field.field_name] = field.extra_value || ''
  })
}

async function loadSettings() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/general-settings')
    applyFields(res.data.data)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load settings.'
  } finally {
    loading.value = false
  }
}

function clearFieldError(fieldName) {
  fieldErrors[fieldName] = ''
  formError.value = ''
}

function onFileChange(field, event) {
  const file = event.target.files?.[0] || null
  clearFieldError(field.field_name)
  if (previewUrls[field.field_name]) {
    URL.revokeObjectURL(previewUrls[field.field_name])
    delete previewUrls[field.field_name]
  }
  pendingFiles[field.field_name] = file
  if (file) previewUrls[field.field_name] = URL.createObjectURL(file)
}

function revokeAllPreviews() {
  Object.values(previewUrls).forEach((url) => URL.revokeObjectURL(url))
}
onBeforeUnmount(revokeAllPreviews)

function applyServerErrors(errors) {
  Object.entries(errors).forEach(([key, messages]) => {
    // "allowed_upload_types.1" (a bad item inside a checkbox array) still
    // belongs to the "allowed_upload_types" field as far as the form is
    // concerned — there's no per-item UI to pin it to.
    const bucket = key.split('.')[0]
    if (!fieldErrors[bucket]) fieldErrors[bucket] = messages[0]
  })
}

async function submit() {
  formError.value = ''
  fields.value.forEach((field) => { fieldErrors[field.field_name] = '' })

  const payload = new FormData()
  fields.value.forEach((field) => {
    const name = field.field_name
    if (field.type === 'file') {
      if (pendingFiles[name]) payload.append(name, pendingFiles[name])
      // No newly chosen file — leave the existing stored value untouched.
    } else if (field.type === 'checkbox') {
      (form[name] || []).forEach((value) => payload.append(`${name}[]`, value))
    } else {
      payload.append(name, form[name] ?? '')
    }
    if (hasExtraOption(field)) payload.append(`${name}_extra`, extra[name] ?? '')
  })

  saving.value = true
  try {
    const res = await api.post('/general-settings', payload)
    applyFields(res.data.data)
    Object.keys(pendingFiles).forEach((key) => delete pendingFiles[key])
    revokeAllPreviews()
    Object.keys(previewUrls).forEach((key) => delete previewUrls[key])
    toast.success(res.data.message || 'Settings saved successfully.')
    // In case any of the "Branding & Icons" fields changed — refreshes the
    // shared store so the sidebar/footer/favicon (and this page's own
    // "Current file" previews, via applyFields above) update immediately
    // instead of only on the next full page load.
    branding.load()
  } catch (err) {
    const data = err.response?.data
    if (data?.errors) {
      applyServerErrors(data.errors)
      focusFirstError()
      // Every field saves together in one submission (see submit()'s own
      // docblock) — one bad field (which may be scrolled out of view, or a
      // radio/checkbox group focusFirstError() can't scroll to) silently
      // discards every other change on the page too, including ones that
      // were themselves perfectly valid. Without this banner an admin who
      // doesn't spot the one inline red message below has no way to tell
      // their save didn't go through at all.
      formError.value = 'Some fields need attention — nothing on this page was saved. Please fix the highlighted field(s) below and save again.'
    } else {
      formError.value = data?.message || 'Could not save settings.'
    }
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <div>
    <div class="max-w-[900px] mx-auto">
      <!-- Page header, matching every other page's icon-badge + title pattern -->
      <div class="flex items-start gap-3 mb-5">
        <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
          <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
            <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <circle cx="12" cy="12" r="3" />
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
            </svg>
          </span>
        </span>
        <div>
          <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">General Settings</h1>
          <p class="mt-1 text-[13px] sm:text-sm text-muted">Site-wide configuration used across the app.</p>
        </div>
      </div>

      <p v-if="loading" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
      <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

      <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
        <p v-if="formError" class="text-[13px] text-brand bg-brand/10 rounded-xl px-4 py-2.5 text-center">{{ formError }}</p>

        <section
          v-for="section in groupedFields"
          :key="section.name || '_default'"
          class="bg-white rounded-[28px] shadow-card p-4 sm:p-5"
        >
          <template v-if="section.name">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">{{ section.name }}</h2>
            <p class="text-[13px] text-muted mb-3">Replace any of these to change the app's branding — leave a field blank to keep the current file.</p>
          </template>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <template v-for="field in section.fields" :key="field.field_name">
              <div
                class="flex flex-col gap-1.5"
                :class="['textarea', 'radio', 'checkbox'].includes(field.type) ? 'md:col-span-2' : ''"
              >
                <label :for="field.field_name" class="text-[13px] text-label">
                  {{ field.label || field.field_name }}
                  <span v-if="field.is_required" class="text-brand">*</span>
                </label>

                <!-- text / number / email / url / date -->
                <input
                  v-if="['text', 'number', 'email', 'url', 'date'].includes(field.type)"
                  :id="field.field_name"
                  :ref="(el) => (fieldRefs[field.field_name] = el)"
                  v-model="form[field.field_name]"
                  :type="field.type"
                  :placeholder="field.placeholder || ''"
                  class="form-control w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors[field.field_name] ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                  @input="clearFieldError(field.field_name)"
                />

                <!-- textarea -->
                <textarea
                  v-else-if="field.type === 'textarea'"
                  :id="field.field_name"
                  :ref="(el) => (fieldRefs[field.field_name] = el)"
                  v-model="form[field.field_name]"
                  :placeholder="field.placeholder || ''"
                  rows="4"
                  class="form-control w-full px-3 py-2.5 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition resize-y min-h-[96px]"
                  :class="fieldErrors[field.field_name] ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                  @input="clearFieldError(field.field_name)"
                ></textarea>

                <!-- selectbox -->
                <div v-else-if="field.type === 'selectbox'" class="relative">
                  <select
                    :id="field.field_name"
                    :ref="(el) => (fieldRefs[field.field_name] = el)"
                    v-model="form[field.field_name]"
                    class="form-control appearance-none w-full h-10 pl-3 pr-9 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                    :class="fieldErrors[field.field_name] ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                    @change="clearFieldError(field.field_name)"
                  >
                    <option v-if="field.placeholder" value="" disabled>{{ field.placeholder }}</option>
                    <option v-for="option in field.options || []" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>

                <!-- radio (with an optional inline "extra" companion field) -->
                <fieldset v-else-if="field.type === 'radio'" class="rounded-2xl border border-input-border bg-page-bg/60 p-3">
                  <div class="flex flex-wrap items-center gap-4">
                    <label v-for="option in field.options || []" :key="option.value" class="form-check">
                      <input
                        type="radio"
                        class="form-radio"
                        :name="field.field_name"
                        :value="option.value"
                        v-model="form[field.field_name]"
                        @change="clearFieldError(field.field_name)"
                      />
                      <span>{{ option.label }}</span>
                    </label>
                    <div v-if="selectedExtraLabel(field)" class="flex flex-col gap-1 min-w-[180px]">
                      <label :for="`${field.field_name}_extra`" class="text-[12px] text-label">{{ selectedExtraLabel(field) }}</label>
                      <input
                        :id="`${field.field_name}_extra`"
                        v-model="extra[field.field_name]"
                        type="text"
                        class="form-control h-9 px-3 rounded-lg bg-white text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                        :class="fieldErrors[`${field.field_name}_extra`] ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                        @input="clearFieldError(`${field.field_name}_extra`)"
                      />
                    </div>
                  </div>
                  <p v-if="fieldErrors[`${field.field_name}_extra`]" class="mt-2 text-[12px] text-brand">{{ fieldErrors[`${field.field_name}_extra`] }}</p>
                </fieldset>

                <!-- checkbox (multi-select) -->
                <fieldset v-else-if="field.type === 'checkbox'" class="rounded-2xl border border-input-border bg-page-bg/60 p-3">
                  <div class="flex flex-wrap gap-4">
                    <label v-for="option in field.options || []" :key="option.value" class="form-check">
                      <input
                        type="checkbox"
                        class="form-checkbox"
                        :value="option.value"
                        v-model="form[field.field_name]"
                        @change="clearFieldError(field.field_name)"
                      />
                      <span>{{ option.label }}</span>
                    </label>
                  </div>
                </fieldset>

                <!-- file -->
                <div v-else-if="field.type === 'file'" class="flex flex-col gap-2">
                  <div v-if="previewUrls[field.field_name] || form[field.field_name]" class="flex items-center gap-3">
                    <img
                      :src="previewUrls[field.field_name] || form[field.field_name]"
                      alt=""
                      class="w-14 h-14 rounded-xl object-cover border border-input-border bg-white"
                    />
                    <span class="text-[12px] text-muted">{{ pendingFiles[field.field_name] ? 'New file selected — not saved yet.' : 'Current file.' }}</span>
                  </div>
                  <input
                    :id="field.field_name"
                    :ref="(el) => (fieldRefs[field.field_name] = el)"
                    type="file"
                    accept="image/*"
                    class="form-file w-full text-sm text-gray-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-soft file:text-brand-blue hover:file:bg-brand-blue/10 cursor-pointer"
                    @change="onFileChange(field, $event)"
                  />
                </div>

                <p v-if="field.help_text" class="text-[12px] text-muted">{{ field.help_text }}</p>
                <p v-if="fieldErrors[field.field_name]" class="text-[12px] text-brand">{{ fieldErrors[field.field_name] }}</p>
              </div>
            </template>
          </div>
        </section>

        <div class="pt-2 flex justify-center">
          <button
            type="submit"
            class="min-w-[220px] px-8 py-3.5 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.08em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Updating…' : 'Update Settings' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
