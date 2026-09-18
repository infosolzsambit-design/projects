<script setup>
import { onMounted, reactive, ref } from 'vue'
import api, { resolveStorageUrl } from '../../utils/api'
import { useToast } from '../../composables/useToast'
import { useConfirm } from '../../composables/useConfirm'
import Pagination from '../common/Pagination.vue'
import RowActionMenu from '../common/RowActionMenu.vue'

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
const toast = useToast()
const { confirmDialog } = useConfirm()

const rows = ref([])
const loading = ref(true)
const loadError = ref('')
const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

// Matches Roll No, Name, or Subject Barcode server-side (see
// QuestionAnswerSheetMappingController::rows()) — same enter-to-search +
// button convention as AnswerSheetsView.vue's own packet search.
const search = ref('')

async function fetchRows(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get(`/answer-sheet-mappings/${props.mapping.id}/rows`, {
      params: { page, per_page: pagination.per_page, search: search.value || undefined },
    })
    rows.value = res.data.data.items.map((row) => ({ ...row, pdf_url: resolveStorageUrl(row.pdf_url) }))
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || "Could not load this packet's answer sheets."
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchRows(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchRows(page)
}

function close() {
  emit('close')
}

// Whether a teacher has started evaluating this sheet yet, and who —
// AnswerSheetResource already carries everything needed (teacher_id/
// teacher_name from the controller's own ->with('teacher'), plus marks/
// draft_marks/consumed_time), so this is purely a client-side read of
// fields already on the row, same reasoning MyPendingCoursesView.vue/
// AssignedTeachersView.vue use to tell "assigned" from "started" from
// "completed" apart.
function evaluationStatus(row) {
  if (!row.teacher_id) return { label: 'Not Assigned', class: 'bg-gray-100 text-gray-600' }
  if (row.marks !== null && row.marks !== undefined) return { label: 'Completed', class: 'bg-success/10 text-success' }
  if (row.draft_marks !== null || Number(row.consumed_time) > 0) return { label: 'In Progress', class: 'bg-amber-100 text-amber-700' }
  return { label: 'Not Started', class: 'bg-brand-blue/10 text-brand-blue' }
}

const deletingId = ref(null)
async function deleteRow(row) {
  const confirmed = await confirmDialog({
    title: 'Delete Answer Sheet',
    message: `Delete answer sheet for roll no "${row.roll_no}"? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return

  deletingId.value = row.id
  try {
    await api.delete(`/answer-sheet-mappings/${props.mapping.id}/rows/${row.id}`)
    toast.success('Answer sheet deleted successfully.')
    // Last row on a page past the first — step back a page, same
    // convention as every other paginated delete in this app (see
    // CoursesView.vue's own removeCourse()); otherwise just refresh
    // this same page since a later row has slid up to fill the gap.
    if (rows.value.length === 1 && pagination.current_page > 1) {
      await fetchRows(pagination.current_page - 1)
    } else {
      await fetchRows(pagination.current_page)
    }
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not delete this answer sheet.')
  } finally {
    deletingId.value = null
  }
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
        <div class="flex items-center gap-2 shrink-0">
          <input
            v-model="search"
            type="text"
            placeholder="Search roll no, name, barcode…"
            class="w-48 sm:w-60 h-9 px-3 rounded-lg bg-input-bg text-[12.5px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
            @keyup.enter="runSearch"
          />
          <button
            type="button"
            class="h-9 w-9 shrink-0 rounded-lg bg-input-bg hover:bg-soft text-gray-600 flex items-center justify-center transition-colors"
            aria-label="Search"
            @click="runSearch"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
          </button>
          <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="close">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
          </button>
        </div>
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
                  <th class="px-2.5 py-2 min-w-[170px]">Evaluation By</th>
                  <th class="px-2.5 py-2 min-w-[110px]">Evaluation Status</th>
                  <th class="px-2.5 py-2 text-center min-w-[70px]">Marks</th>
                  <th class="px-2.5 py-2 text-center rounded-tr-xl min-w-[70px]">Action</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-if="loading">
                  <td colspan="9" class="px-2.5 py-10 text-center text-[13px] text-muted">Loading&hellip;</td>
                </tr>
                <tr v-else-if="!rows.length">
                  <td colspan="9" class="px-2.5 py-10 text-center text-[13px] text-muted">No answer sheets in this packet.</td>
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
                  <td class="px-2.5 py-2.5">
                    <template v-if="row.teacher_name">
                      {{ row.teacher_name }}
                      <span v-if="row.teacher_emp_code" class="text-muted">({{ row.teacher_emp_code }})</span>
                    </template>
                    <span v-else class="text-muted">—</span>
                  </td>
                  <td class="px-2.5 py-2.5 whitespace-nowrap">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                      :class="evaluationStatus(row).class"
                    >
                      {{ evaluationStatus(row).label }}
                    </span>
                  </td>
                  <td class="px-2.5 py-2.5 text-center">{{ row.marks ?? '—' }}</td>
                  <td class="px-2.5 py-2.5 text-center relative" @click.stop>
                    <RowActionMenu width="w-44">
                      <a
                        v-if="row.pdf_url"
                        :href="row.pdf_url"
                        target="_blank"
                        rel="noopener"
                        class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                      >
                        View Answer Sheet
                      </a>
                      <span v-else class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-muted">
                        No Answer Sheet
                      </span>
                      <button
                        type="button"
                        class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                        :disabled="deletingId === row.id"
                        @click="deleteRow(row)"
                      >
                        {{ deletingId === row.id ? 'Deleting…' : 'Delete' }}
                      </button>
                    </RowActionMenu>
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
