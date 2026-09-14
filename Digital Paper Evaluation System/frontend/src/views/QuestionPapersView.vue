<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from '../composables/useConfirm'
import { useToast } from '../composables/useToast'
import Pagination from '../components/common/Pagination.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'

const router = useRouter()
const { confirmDialog } = useConfirm()
const toast = useToast()
const authStore = useAuthStore()

const papers = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'draft' | 'ready'

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchPapers(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.status = statusFilter.value

    const res = await api.get('/question-papers', { params })
    papers.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load question papers.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchPapers(1)
}

const statusOptions = [
  { value: '', label: 'All Status' },
  { value: 'ready', label: 'Ready' },
  { value: 'draft', label: 'Draft' },
]
const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
function selectStatus(value) {
  statusFilter.value = value
  showFilter.value = false
  fetchPapers(1)
}
onMounted(() => document.addEventListener('click', closeFilter))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPapers(page)
}

const deletingId = ref(null)

function goToSetup() {
  router.push({ name: 'question-papers-setup' })
}

function goToConfigure(paper) {
  router.push({ name: 'question-papers-configure', params: { id: paper.id } })
}

function goToView(paper) {
  router.push({ name: 'question-papers-view', params: { id: paper.id } })
}

async function removePaper(paper) {
  const confirmed = await confirmDialog({
    title: 'Delete Question Paper',
    message: `Delete this ${paper.course_name || 'question paper'} (${paper.exam_year}, Sem ${paper.semester})? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = paper.id
  try {
    await api.delete(`/question-papers/${paper.id}`)
    if (papers.value.length === 1 && pagination.current_page > 1) {
      await fetchPapers(pagination.current_page - 1)
    } else {
      await fetchPapers(pagination.current_page)
    }
    toast.success('Question paper deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete this question paper.'
  } finally {
    deletingId.value = null
  }
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('question-paper-list')) fetchPapers(1)
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('question-paper-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb + page action -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-5 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700">Question Paper List</span>
      </div>
      <div v-if="authStore.can('question-paper-setup')" class="flex items-center gap-2">
        <!-- No "Add Question Paper" here on purpose — a question paper isn't
             a simple record you fill in from scratch, it's a PDF you upload
             and then transcribe the structure of. "Setup" makes that two-step
             nature explicit instead of implying a single quick-add form. -->
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToSetup"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          Setup Question Paper
        </button>
      </div>
    </div>

    <p v-if="loadError" class="text-[13px] text-brand mb-4">{{ loadError }}</p>

    <!-- Section title bar, matching designed_files/subject-list.html's
         colored info bar directly above its table — search/filter live here
         on the right, same as the reference's "Available : 37" pill. -->
    <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="8" y1="13" x2="16" y2="13" />
            <line x1="8" y1="17" x2="16" y2="17" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Question Papers</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search…"
          class="w-full sm:w-56 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          @keyup.enter="runSearch"
        />
        <button
          type="button"
          class="h-10 w-10 shrink-0 rounded-lg bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors"
          aria-label="Search"
          @click="runSearch"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
        </button>
        <button
          type="button"
          class="h-10 shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-white text-[13px] font-medium px-3.5 transition-colors"
          :aria-expanded="showFilter"
          @click="toggleFilter"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" /></svg>
          Filter
        </button>
        <div
          v-show="showFilter"
          class="absolute right-4 sm:right-5 top-full mt-2 w-40 bg-white rounded-xl shadow-panel border border-soft py-1.5 z-30 text-left"
        >
          <button
            v-for="option in statusOptions"
            :key="option.value"
            type="button"
            class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] transition-colors"
            :class="statusFilter === option.value ? 'text-brand-blue font-semibold bg-soft' : 'text-gray-700 hover:bg-soft hover:text-brand-blue'"
            @click="selectStatus(option.value)"
          >
            {{ option.label }}
          </button>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[13px] font-medium">
              <th class="px-4 py-3.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-3.5 font-medium">Exam Year</th>
              <th class="px-4 py-3.5 font-medium">Course</th>
              <th class="px-4 py-3.5 font-medium">Exam Term</th>
              <th class="px-4 py-3.5 font-medium">Semester</th>
              <th class="px-4 py-3.5 font-medium">Full Marks</th>
              <th class="px-4 py-3.5 font-medium">Status</th>
              <th class="px-4 py-3.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('question-paper-edit') || authStore.can('question-paper-delete') || authStore.can('question-paper-view')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!papers.length">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">No question papers found.</td>
            </tr>
            <tr
              v-for="(paper, index) in papers"
              v-else
              :key="paper.id"
              class="text-[13px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-3.5">
                <span class="inline-flex items-center justify-center min-w-[46px] rounded-md status-gradient-border px-2 py-1.5 text-[12px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-3.5 font-semibold">{{ paper.exam_year }}</td>
              <td class="px-4 py-3.5">
                {{ paper.course_name || '—' }}
                <span v-if="paper.course_code" class="text-muted">({{ paper.course_code }})</span>
              </td>
              <td class="px-4 py-3.5">{{ paper.exam_term_name || '—' }}</td>
              <td class="px-4 py-3.5">{{ paper.semester }}</td>
              <td class="px-4 py-3.5">{{ paper.full_marks ?? '—' }}</td>
              <td class="px-4 py-3.5">
                <span
                  class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold"
                  :class="paper.status === 'ready' ? 'bg-success/10 text-success' : 'bg-badge/15 text-badge'"
                >
                  {{ paper.status === 'ready' ? 'Ready' : 'Draft' }}
                </span>
              </td>
              <td v-if="authStore.can('question-paper-edit') || authStore.can('question-paper-delete') || authStore.can('question-paper-view')" class="px-4 py-3.5 text-center relative" @click.stop>
                <RowActionMenu width="w-44">
                  <button
                    v-if="paper.status === 'ready' || authStore.can('question-paper-view')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors"
                    @click="goToView(paper)"
                  >
                    View
                  </button>
                  <button v-if="authStore.can('question-paper-edit')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="goToConfigure(paper)">
                    {{ paper.status === 'ready' ? 'Edit Setup' : 'Continue Setup' }}
                  </button>
                  <button
                    v-if="authStore.can('question-paper-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                    :disabled="deletingId === paper.id"
                    @click="removePaper(paper)"
                  >
                    {{ deletingId === paper.id ? 'Deleting…' : 'Delete' }}
                  </button>
                </RowActionMenu>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Pagination -->
    <Pagination
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      :total="pagination.total"
      @change="goToPage"
    />
  </div>
</template>
