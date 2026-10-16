<script setup>
// "Sync …" from the college's main database (opened by
// MainDbSyncControl.vue's button). First it shows the request the sync
// will make — method, path after the base URL and Postman-style query
// params, auto-saved as they're edited (PUT /main-db-sync/config) — and the
// last sync's details, and asks to confirm; "Sync" then runs POST
// `endpoint` (no global loader — this modal is the progress) and shows the
// backend's summary, or its error with a Retry.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import api from '../../utils/api'
import { formatDateTime } from '../../utils/date'

const props = defineProps({
  endpoint: { type: String, required: true }, // e.g. '/departments/sync'
  label: { type: String, required: true }, // plural, e.g. 'departments'
  lastSync: { type: Object, default: null }, // MainDbSync::lastFor() — null if never synced
  entity: { type: String, required: true }, // e.g. 'departments' — main_db_sync_configs.entity
  config: { type: Object, default: null }, // MainDbSyncConfig::toPayload() — { method, path, params }
})
const emit = defineEmits(['close', 'synced'])

const stage = ref('confirm') // 'confirm' | 'syncing' | 'done' | 'error'
const result = ref(null)
const error = ref('')

const title = `Sync ${props.label.replace(/\b\w/g, (ch) => ch.toUpperCase())}` // 'exam terms' → 'Sync Exam Terms'

const rows = [
  { key: 'fetched', text: 'Received from main database', cls: 'text-gray-900' },
  { key: 'created', text: 'Added', cls: 'text-green-600' },
  { key: 'updated', text: 'Updated', cls: 'text-brand-blue' },
  { key: 'restored', text: 'Restored', cls: 'text-brand-blue' },
  { key: 'unchanged', text: 'Unchanged', cls: 'text-gray-500' },
  { key: 'deleted', text: 'Removed (no longer in main database)', cls: 'text-brand' },
  { key: 'skipped', text: 'Skipped (missing id or name)', cls: 'text-gray-500' },
]

const lastSummary = computed(() => props.lastSync?.summary || {})

// ---- Request settings (auto-saved) ----
const request = reactive({
  method: props.config?.method || 'POST',
  path: props.config?.path || '',
  params: (props.config?.params || []).map((p) => ({ key: p.key || '', value: p.value || '', enabled: p.enabled !== false })),
})
const saveState = ref('') // '' | 'saving' | 'saved' | 'error'
const pathError = ref('')
const PATH_RE = /^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/
let saveTimer = null
let savePromise = null

function addParam() {
  request.params.push({ key: '', value: '', enabled: true })
}

function removeParam(index) {
  request.params.splice(index, 1)
}

async function save() {
  clearTimeout(saveTimer)
  saveTimer = null
  const path = request.path.trim().replace(/^\/+|\/+$/g, '')
  if (!PATH_RE.test(path)) {
    pathError.value = 'Use only letters, numbers, -, _ and /, e.g. DataApi/course.'
    saveState.value = 'error'
    return false
  }
  pathError.value = ''
  saveState.value = 'saving'
  savePromise = api.put('/main-db-sync/config', {
    entity: props.entity,
    method: request.method,
    path,
    params: request.params.map((p) => ({ key: p.key.trim(), value: p.value, enabled: p.enabled })),
  }, { skipLoader: true })
  try {
    await savePromise
    saveState.value = 'saved'
    return true
  } catch (err) {
    pathError.value = err.response?.data?.errors?.path?.[0] || ''
    saveState.value = 'error'
    return false
  } finally {
    savePromise = null
  }
}

// Save shortly after the last change.
watch(request, () => {
  saveState.value = 'saving'
  clearTimeout(saveTimer)
  saveTimer = setTimeout(save, 700)
}, { deep: true })

// Leaving with a change not yet saved — save it now.
onBeforeUnmount(() => {
  if (saveTimer) save()
})

async function confirmSync() {
  // Make sure the sync uses what's on screen.
  if (saveTimer) {
    if (!(await save())) return
  } else if (savePromise) {
    await savePromise.catch(() => {})
  }
  if (saveState.value === 'error') return
  runSync()
}

async function runSync() {
  stage.value = 'syncing'
  result.value = null
  error.value = ''
  try {
    const res = await api.post(props.endpoint, {}, { skipLoader: true })
    result.value = res.data?.data || {}
    stage.value = 'done'
    emit('synced', result.value)
  } catch (err) {
    error.value = err.response?.data?.message || `Could not sync ${props.label}.`
    stage.value = 'error'
  }
}

function close() {
  if (stage.value === 'syncing') return
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full px-6 py-7 max-h-[90vh] overflow-y-auto" :class="stage === 'confirm' ? 'max-w-4xl' : 'max-w-md'">
      <div class="flex items-start justify-between gap-3 mb-4">
        <h2 class="text-lg font-bold text-black">{{ title }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0 disabled:opacity-40" aria-label="Close" :disabled="stage === 'syncing'" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <!-- Confirm: the request (auto-saved) + last sync details + what a sync does -->
      <div v-if="stage === 'confirm'">
        <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_300px] gap-5">
          <!-- Request -->
          <div class="min-w-0">
            <div class="flex items-center justify-between mb-2">
              <p class="text-[12px] font-semibold text-gray-500 uppercase tracking-wide">API Request</p>
              <span class="text-[11px]" :class="saveState === 'error' ? 'text-brand' : 'text-muted'">
                <template v-if="saveState === 'saving'">Saving&hellip;</template>
                <template v-else-if="saveState === 'saved'">&#10003; Saved</template>
                <template v-else-if="saveState === 'error'">Not saved</template>
              </span>
            </div>
            <div class="flex items-stretch rounded-lg border border-input-border bg-input-bg overflow-hidden focus-within:border-brand-blue focus-within:ring-2 focus-within:ring-brand-blue/15 transition" :class="{ '!border-brand': pathError }">
              <select v-model="request.method" aria-label="Method" class="h-9 px-2 bg-white border-r border-input-border text-[12.5px] font-bold text-brand-blue outline-none cursor-pointer">
                <option value="POST">POST</option>
                <option value="GET">GET</option>
              </select>
              <span class="flex items-center px-2 text-[12px] text-muted whitespace-nowrap select-none border-r border-input-border" title="The API Base URL from General Settings">Base URL /</span>
              <input v-model="request.path" type="text" maxlength="255" placeholder="DataApi/course" aria-label="Path" class="h-9 flex-1 min-w-0 px-3 bg-transparent text-[13px] text-gray-800 outline-none" />
            </div>
            <p v-if="pathError" class="text-[11px] text-brand mt-1">{{ pathError }}</p>

            <p class="text-[12px] font-semibold text-gray-500 uppercase tracking-wide mt-4 mb-2">Params</p>
            <div class="rounded-xl border border-input-border overflow-hidden">
              <div class="grid grid-cols-[32px_minmax(0,1fr)_minmax(0,1fr)_32px] bg-soft text-[11.5px] font-semibold text-gray-600">
                <span></span><span class="px-2 py-1.5">Key</span><span class="px-2 py-1.5">Value</span><span></span>
              </div>
              <div v-for="(param, i) in request.params" :key="i" class="grid grid-cols-[32px_minmax(0,1fr)_minmax(0,1fr)_32px] items-center border-t border-input-border">
                <span class="flex justify-center">
                  <input v-model="param.enabled" type="checkbox" class="w-4 h-4 accent-brand-blue cursor-pointer" :title="param.enabled ? 'Sent — untick to skip' : 'Not sent — tick to send'" />
                </span>
                <input v-model="param.key" type="text" maxlength="100" placeholder="Key" class="h-8 px-2 text-[12.5px] text-gray-800 outline-none border-l border-input-border focus:bg-input-bg" :class="{ 'text-gray-400 line-through': !param.enabled }" />
                <input v-model="param.value" type="text" maxlength="255" placeholder="Value" class="h-8 px-2 text-[12.5px] text-gray-800 outline-none border-l border-input-border focus:bg-input-bg" :class="{ 'text-gray-400': !param.enabled }" />
                <button type="button" class="h-8 flex items-center justify-center text-gray-400 hover:text-brand border-l border-input-border" aria-label="Remove param" @click="removeParam(i)">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                </button>
              </div>
              <p v-if="!request.params.length" class="border-t border-input-border px-3 py-2.5 text-[12px] text-muted">No params — none are sent.</p>
            </div>
            <button type="button" class="mt-2 inline-flex items-center gap-1 text-[12px] font-semibold text-brand-blue hover:underline disabled:opacity-50 disabled:no-underline" :disabled="request.params.length >= 20" @click="addParam">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
              Add Param
            </button>
            <p class="text-[11px] text-muted mt-1">Ticked params are sent in the URL (e.g. ?delete_status=0), for GET and POST alike. Changes save automatically.</p>
          </div>

          <!-- Last sync -->
          <div>
            <p class="text-[12px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Last Sync</p>
            <div v-if="lastSync" class="rounded-xl border border-input-border divide-y divide-input-border">
              <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-[12.5px]">
                <span class="text-gray-700">Synced on</span>
                <span class="font-bold text-gray-900 whitespace-nowrap">{{ formatDateTime(lastSync.synced_at) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-[12.5px]">
                <span class="text-gray-700">Synced by</span>
                <span class="font-bold text-gray-900 text-right">{{ lastSync.synced_by || '—' }}</span>
              </div>
              <div v-for="row in rows" :key="row.key" class="flex items-center justify-between gap-3 px-3 py-1.5 text-[12.5px]">
                <span class="text-gray-700">{{ row.text }}</span>
                <span class="font-bold" :class="row.cls">{{ lastSummary[row.key] ?? 0 }}</span>
              </div>
            </div>
            <div v-else class="rounded-xl bg-soft px-4 py-3 text-[13px] text-gray-600">Never synced.</div>
          </div>
        </div>

        <p class="text-[13px] text-gray-600 mt-5">
          Fetch {{ label }} from the main database now? Matching {{ label }} will be updated, new ones added, and synced {{ label }} not in the API's response will be deleted.
        </p>
      </div>

      <!-- Syncing -->
      <div v-else-if="stage === 'syncing'" class="flex flex-col items-center gap-3 py-6">
        <svg class="w-10 h-10 text-brand-blue animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20" />
          <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <p class="text-[14px] font-semibold text-gray-900">Syncing {{ label }}&hellip;</p>
        <p class="text-[12.5px] text-muted text-center">Fetching from the main database. Please don't close this window.</p>
      </div>

      <!-- Error -->
      <div v-else-if="stage === 'error'" class="py-2">
        <div class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-[13px] text-brand">{{ error }}</div>
      </div>

      <!-- Result -->
      <div v-else-if="stage === 'done'" class="py-1">
        <div class="flex items-center gap-2 mb-3">
          <span class="w-7 h-7 rounded-full bg-green-100 text-green-600 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
          </span>
          <p class="text-[14px] font-semibold text-gray-900">Sync completed</p>
        </div>
        <ul class="rounded-xl border border-input-border divide-y divide-input-border">
          <li v-for="row in rows" :key="row.key" class="flex items-center justify-between px-4 py-2 text-[13px]">
            <span class="text-gray-700">{{ row.text }}</span>
            <span class="font-bold" :class="row.cls">{{ result[row.key] ?? 0 }}</span>
          </li>
        </ul>
      </div>

      <div v-if="stage !== 'syncing'" class="flex justify-end gap-2 mt-5">
        <template v-if="stage === 'confirm'">
          <button
            type="button"
            class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saveState === 'error'"
            @click="confirmSync"
          >
            Sync
          </button>
        </template>
        <template v-else>
          <button
            v-if="stage === 'error'"
            type="button"
            class="h-9 inline-flex items-center rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
            @click="runSync"
          >
            Retry
          </button>
          <button
            type="button"
            class="h-9 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity"
            @click="close"
          >
            Close
          </button>
        </template>
      </div>
    </div>
  </div>
</template>
