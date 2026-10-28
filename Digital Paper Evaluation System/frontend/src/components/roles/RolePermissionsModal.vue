<script setup>
// Opened from RolesView.vue's Permissions count — a read-only view of one
// role's permissions, grouped by Permission Group / Sub Group exactly like
// RoleFormView.vue's picker (the role list's own permissions carry no
// group, so the full list is loaded here: GET /permissions?status=all).
// "Granted only" (default) shows just what the role has; "All" also shows
// what it doesn't, greyed out, so gaps are easy to spot.
import { computed, onMounted, ref } from 'vue'
import api from '../../utils/api'
import { permissionLabel, permissionMatches } from '../../utils/permission'

const props = defineProps({
  role: { type: Object, required: true }, // { id, name, permissions: [{ id, name }] }
  canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'edit'])

const allPermissions = ref([])
const loading = ref(true)
const loadError = ref('')
const search = ref('')
const show = ref('granted') // 'granted' | 'all'

const grantedIds = computed(() => new Set((props.role.permissions || []).map((p) => p.id)))

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/permissions', { params: { status: 'all' }, skipLoader: true })
    allPermissions.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load permissions.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Same ordering as RoleFormView.vue — ungrouped permissions sort last.
const UNGROUPED = { id: '_ungrouped', name: 'Ungrouped', sort_order: Infinity }
function bySortOrder([, a], [, b]) {
  return a.sort_order - b.sort_order || a.name.localeCompare(b.name)
}

const groups = computed(() => {
  const query = search.value.trim().toLowerCase()
  const map = new Map() // groupId -> { name, sort_order, total, granted, subGroups: Map }

  for (const permission of allPermissions.value) {
    const group = permission.permission_group || UNGROUPED
    const subGroup = permission.permission_sub_group || UNGROUPED
    const granted = grantedIds.value.has(permission.id)

    if (!map.has(group.id)) map.set(group.id, { name: group.name, sort_order: group.sort_order ?? 0, total: 0, granted: 0, subGroups: new Map() })
    const g = map.get(group.id)
    g.total++
    if (granted) g.granted++

    // Counts above cover the whole group; the list below is what's shown.
    if (show.value === 'granted' && !granted) continue
    // Matches the stored name ("course-status") or how it's shown ("course status").
    if (query && !permissionMatches(permission.name, query) && !g.name.toLowerCase().includes(query)) continue

    if (!g.subGroups.has(subGroup.id)) g.subGroups.set(subGroup.id, { name: subGroup.name, sort_order: subGroup.sort_order ?? 0, items: [] })
    g.subGroups.get(subGroup.id).items.push({ ...permission, granted })
  }

  return [...map.entries()]
    .sort(bySortOrder)
    .map(([groupId, g]) => ({
      groupId,
      name: g.name,
      total: g.total,
      granted: g.granted,
      subGroups: [...g.subGroups.entries()].sort(bySortOrder).map(([subGroupId, sg]) => ({ subGroupId, ...sg })),
    }))
    .filter((g) => g.subGroups.length)
})

const totalGranted = computed(() => grantedIds.value.size)
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-2xl shadow-card w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
      <!-- Header -->
      <div class="flex items-start justify-between gap-3 px-5 pt-4 pb-3 border-b border-soft">
        <div class="min-w-0">
          <h2 class="text-base font-bold text-black truncate">Permissions — {{ role.name }}</h2>
          <p class="text-[12px] text-muted">
            {{ totalGranted }} permission{{ totalGranted === 1 ? '' : 's' }} granted<template v-if="!loading && allPermissions.length"> out of {{ allPermissions.length }}</template>.
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors shrink-0" aria-label="Close" @click="emit('close')">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <!-- Search + show toggle -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 px-5 py-2 border-b border-soft">
        <div class="relative flex-1">
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
          <input
            v-model="search"
            type="text"
            placeholder="Search permissions or groups…"
            class="w-full h-8 pl-9 pr-3 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
          />
        </div>
        <div class="inline-flex rounded-lg border border-input-border p-0.5 bg-page-bg shrink-0" role="group" aria-label="Show">
          <button
            type="button"
            class="h-7 px-3 rounded-md text-[12px] font-semibold transition-colors"
            :class="show === 'granted' ? 'bg-white text-brand-blue shadow-sm' : 'text-gray-500 hover:text-gray-800'"
            @click="show = 'granted'"
          >
            Granted only
          </button>
          <button
            type="button"
            class="h-7 px-3 rounded-md text-[12px] font-semibold transition-colors"
            :class="show === 'all' ? 'bg-white text-brand-blue shadow-sm' : 'text-gray-500 hover:text-gray-800'"
            @click="show = 'all'"
          >
            All
          </button>
        </div>
      </div>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto px-4 py-3">
        <p v-if="loading" class="text-center text-sm text-muted py-10">Loading permissions&hellip;</p>
        <div v-else-if="loadError" class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-[13px] text-brand">{{ loadError }}</div>
        <p v-else-if="!totalGranted && show === 'granted'" class="text-center text-sm text-muted py-10">This role has no permissions yet.</p>
        <p v-else-if="!groups.length" class="text-center text-sm text-muted py-10">No permissions match your search.</p>

        <div v-else class="space-y-2.5">
          <div v-for="group in groups" :key="group.groupId" class="rounded-xl overflow-hidden border border-soft">
            <!-- Same group header bar as RoleFormView.vue -->
            <div class="bg-subject-header px-3 py-1.5 flex items-center justify-between gap-3">
              <span class="text-white text-[13px] font-semibold">{{ group.name }}</span>
              <span
                class="text-[10.5px] font-semibold whitespace-nowrap rounded-full px-2 py-px"
                :class="group.granted === group.total ? 'bg-white text-green-700' : group.granted ? 'bg-white/90 text-brand-blue' : 'bg-white/20 text-white'"
              >
                {{ group.granted }}/{{ group.total }} granted
              </span>
            </div>
            <!-- One line per sub group: its name, then its permissions beside it. -->
            <div class="bg-white divide-y divide-soft">
              <div v-for="subGroup in group.subGroups" :key="subGroup.subGroupId" class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 px-3 py-1.5">
                <p class="sm:w-40 shrink-0 text-[12px] font-semibold text-gray-800 sm:pt-0.5">{{ subGroup.name }}</p>
                <div class="flex flex-wrap gap-1.5 min-w-0">
                  <span
                    v-for="permission in subGroup.items"
                    :key="permission.id"
                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11.5px] font-medium"
                    :class="permission.granted
                      ? 'bg-green-50 text-green-700 border-green-200'
                      : 'bg-white text-gray-400 border-input-border line-through decoration-gray-300'"
                    :title="`${permission.name} — ${permission.granted ? 'granted' : 'not granted'}`"
                  >
                    <svg v-if="permission.granted" class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
                    <svg v-else class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                    {{ permissionLabel(permission.name) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex justify-end gap-2 px-5 py-2.5 border-t border-soft">
        <button
          v-if="canEdit"
          type="button"
          class="h-8 inline-flex items-center gap-1.5 rounded-lg border border-input-border bg-white text-[12.5px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="emit('edit')"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9" /><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" /></svg>
          Edit Permissions
        </button>
        <button
          type="button"
          class="h-8 inline-flex items-center rounded-lg bg-btn-gradient text-white text-[12.5px] font-semibold px-4 hover:opacity-90 transition-opacity"
          @click="emit('close')"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>
