<script setup>
// "Sync …" from the college's main database, for a master list page's
// header (DepartmentsView.vue, CoursesView.vue): the button (hover for the
// last sync's details) and MainDbSyncModal (the request settings, the
// last sync, a confirm, then the sync). Renders nothing unless General
// Settings → Is Need API From Main DB is "yes" (GET /main-db-sync/status).
// Emits 'synced' once a sync has finished and the modal is closed, so the
// page can reload its list.
import { computed, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { formatDateTime } from '../../utils/date'
import MainDbSyncModal from './MainDbSyncModal.vue'

const props = defineProps({
  entity: { type: String, required: true }, // plural, e.g. 'departments' — main_db_syncs.entity
  endpoint: { type: String, required: true }, // e.g. '/departments/sync'
  buttonLabel: { type: String, required: true }, // e.g. 'Sync Department'
  label: { type: String, default: '' }, // plural for the modal's text, e.g. 'exam terms' — defaults to entity
})
const emit = defineEmits(['synced'])

const enabled = ref(false)
const lastSync = ref(null)
const config = ref(null) // MainDbSyncConfig — the request the sync makes (edited in the modal)
const showModal = ref(false)
const syncedOnce = ref(false)

// Hover text for the button: when, who, and the counts.
const lastSyncTitle = computed(() => {
  if (!lastSync.value) return 'Last sync: Never synced'
  const s = lastSync.value.summary || {}
  return [
    `Last sync: ${formatDateTime(lastSync.value.synced_at)}`,
    `Synced by: ${lastSync.value.synced_by || '—'}`,
    `Received: ${s.fetched ?? 0}`,
    `Added: ${s.created ?? 0}`,
    `Updated: ${s.updated ?? 0}`,
    `Restored: ${s.restored ?? 0}`,
    `Unchanged: ${s.unchanged ?? 0}`,
    `Removed: ${s.deleted ?? 0}`,
    `Skipped: ${s.skipped ?? 0}`,
  ].join('\n')
})

async function loadStatus() {
  try {
    const res = await api.get('/main-db-sync/status', { params: { entity: props.entity }, skipLoader: true })
    enabled.value = !!res.data?.data?.enabled
    lastSync.value = res.data?.data?.last_sync || null
    config.value = res.data?.data?.config || null
  } catch {
    enabled.value = false
  }
}

function start() {
  syncedOnce.value = false
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  loadStatus() // fresh last sync + the request settings saved in the modal
  if (syncedOnce.value) emit('synced')
}

onMounted(loadStatus)
</script>

<template>
  <template v-if="enabled">
    <button
      type="button"
      class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 sm:px-5 hover:border-brand-blue hover:text-brand-blue transition-colors"
      :title="lastSyncTitle"
      @click="start"
    >
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
      {{ buttonLabel }}
    </button>
    <MainDbSyncModal
      v-if="showModal"
      :endpoint="endpoint"
      :label="label || entity"
      :last-sync="lastSync"
      :entity="entity"
      :config="config"
      @synced="syncedOnce = true"
      @close="closeModal"
    />
  </template>
</template>
