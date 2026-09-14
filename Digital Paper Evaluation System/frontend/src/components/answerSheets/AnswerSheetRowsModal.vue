<script setup>
import { onMounted, reactive, ref } from 'vue'
import api, { resolveStorageUrl } from '../../utils/api'
import Pagination from '../common/Pagination.vue'

// Opened from AnswerSheetsView.vue's row action menu — a packet's own
// answer sheets, paginated. A packet can hold thousands of rows (see
// AnswerSheetUploadView.vue's own chunked-submit docblock), so this always
// fetches one page at a time from GET /answer-sheet-mappings/{id}/rows
// rather than ever asking for "all of them" — same reasoning as why show()
// on the backend no longer eager-loads the full answerSheets relation.
const props = defineProps({
  mapping: { type: Object, required: true }, // { id, packet_code, ... } — see AnswerSheetsView.vue
})
const emit = defineEmits(['close'])

const rows = ref([])
const loading = ref(true)
const loadError = ref('')
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchRows(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/answer-sheet-mappings/${props.mapping.id}/rows`, {
      params: { page, per_page: pagination.per_page },
    })
    rows.value = res.data.data.items.map((row) => ({ ...row, pdf_url: resolveStorageUrl(row.pdf_url) }))
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || "Could not load this packet's answer sheets."
  } finally {
    loading.value = false
  }
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchRows(page)
}

function close() {
  emit('close')
}

onMounted(() => fetchRows(1))
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-6xl max-h-[92vh] flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
        <div>
          <h2 class="text-lg font-bold text-black">Answer Sheets — {{ mapping.packet_code }}</h2>
          <p class="text-[13px] text-muted mt-0.5">
            {{ pagination.total }} answer sheet{{ pagination.total === 1 ? '' : 's' }} in this packet.
          </p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="close">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
        <p v-if="loadError" class="text-[13px] text-brand text-center py-8">{{ loadError }}</p>

        <template v-else>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-left border-separate border-spacing-0">
              <thead>
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-2.5 py-2 rounded-tl-xl">#</th>
                  <th class="px-2.5 py-2 min-w-[110px]">Roll No</th>
                  <th class="px-2.5 py-2 min-w-[160px]">Name</th>
                  <th class="px-2.5 py-2 min-w-[130px]">Subject Barcode</th>
                  <th class="px-2.5 py-2 min-w-[150px]">Branch</th>
                  <th class="px-2.5 py-2 text-center min-w-[80px]">Attendance</th>
                  <th class="px-2.5 py-2 text-center min-w-[70px]">Marks</th>
                  <th class="px-2.5 py-2 text-center rounded-tr-xl min-w-[100px]">Answer Sheet</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="loading">
                  <td colspan="8" class="px-2.5 py-10 text-center text-[13px] text-muted">Loading&hellip;</td>
                </tr>
                <tr v-else-if="!rows.length">
                  <td colspan="8" class="px-2.5 py-10 text-center text-[13px] text-muted">No answer sheets in this packet.</td>
                </tr>
                <tr
                  v-for="(row, index) in rows"
                  v-else
                  :key="row.id"
                  class="text-[12.5px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0 align-top"
                >
                  <td class="px-2.5 py-2.5 text-muted">
                    {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                  </td>
                  <td class="px-2.5 py-2.5 font-medium">{{ row.roll_no }}</td>
                  <td class="px-2.5 py-2.5">{{ row.name || '—' }}</td>
                  <td class="px-2.5 py-2.5">{{ row.subject_barcode }}</td>
                  <td class="px-2.5 py-2.5">{{ row.branch_name || '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                      :class="row.absent ? 'bg-brand/10 text-brand' : 'bg-success/10 text-success'"
                    >
                      {{ row.absent ? 'Absent' : 'Present' }}
                    </span>
                  </td>
                  <td class="px-2.5 py-2.5 text-center">{{ row.marks ?? '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center">
                    <a
                      v-if="row.pdf_url"
                      :href="row.pdf_url"
                      target="_blank"
                      rel="noopener"
                      class="inline-flex items-center gap-1 text-brand-blue hover:underline text-[12px] font-medium"
                    >
                      View
                    </a>
                    <span v-else class="text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <Pagination
            :current-page="pagination.current_page"
            :last-page="pagination.last_page"
            :total="pagination.total"
            @change="goToPage"
          />
        </template>
      </div>
    </div>
  </div>
</template>
