<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from '../composables/useConfirm'
import { useToast } from '../composables/useToast'
import Pagination from '../components/common/Pagination.vue'
import FaceCaptureModal from '../components/common/FaceCaptureModal.vue'
import FaceScanApplicableModal from '../components/common/FaceScanApplicableModal.vue'
import RowActionMenu from '../components/common/RowActionMenu.vue'

const router = useRouter()
const { confirmDialog } = useConfirm()
const toast = useToast()
const authStore = useAuthStore()

const teachers = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'
const departmentFilter = ref('') // '' | department id

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

async function fetchTeachers(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.is_active = statusFilter.value
    if (departmentFilter.value) params.department_id = departmentFilter.value

    const res = await api.get('/teachers', { params })
    teachers.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load teachers.'
  } finally {
    loading.value = false
  }
}

function runSearch() {
  fetchTeachers(1)
}

// Same "active departments, name+code" source AssignTeacherView.vue's own
// department filter already uses.
const availableDepartments = ref([])
async function loadDepartments() {
  try {
    const res = await api.get('/departments', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    availableDepartments.value = res.data.data.map((department) => ({
      ...department,
      name: department.code ? `${department.name} (${department.code})` : department.name,
    }))
  } catch {
    // Non-fatal — the department filter just stays empty; search/status still work.
  }
}

const showFilter = ref(false)
function toggleFilter() {
  showFilter.value = !showFilter.value
}
function closeFilter() {
  showFilter.value = false
}
onMounted(() => document.addEventListener('click', closeFilter))
onBeforeUnmount(() => document.removeEventListener('click', closeFilter))

function applyFilters() {
  showFilter.value = false
  fetchTeachers(1)
}
function resetFilters() {
  statusFilter.value = ''
  departmentFilter.value = ''
  showFilter.value = false
  fetchTeachers(1)
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchTeachers(page)
}

const deletingId = ref(null)
const togglingId = ref(null)

async function toggleStatus(teacher) {
  togglingId.value = teacher.id
  const next = !teacher.is_active
  try {
    await api.put(`/teachers/${teacher.id}`, { is_active: next })
    teacher.is_active = next
    toast.success(`Teacher ${next ? 'activated' : 'deactivated'} successfully.`)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not update teacher status.'
  } finally {
    togglingId.value = null
  }
}

function goToCreate() {
  router.push({ name: 'teachers-create' })
}

function goToEdit(teacher) {
  router.push({ name: 'teachers-edit', params: { id: teacher.id } })
}

async function removeTeacher(teacher) {
  const confirmed = await confirmDialog({
    title: 'Delete Teacher',
    message: `Delete teacher "${teacher.name}"? This can be undone by an admin later.`,
    confirmText: 'Delete',
  })
  if (!confirmed) return
  deletingId.value = teacher.id
  try {
    await api.delete(`/teachers/${teacher.id}`)
    if (teachers.value.length === 1 && pagination.current_page > 1) {
      await fetchTeachers(pagination.current_page - 1)
    } else {
      await fetchTeachers(pagination.current_page)
    }
    toast.success('Teacher deleted successfully.')
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete teacher.'
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
  if (authStore.can('teacher-list')) {
    fetchTeachers(1)
    loadDepartments()
  }
})

// --- Face Scan Applicable (per-row, row action menu — see
// FaceScanApplicableModal.vue's own docblock for how this differs from
// the Face Scan capture flow just below). ---------------------------
const faceScanApplicableModalTeacher = ref(null)
function openFaceScanApplicableModal(teacher) {
  faceScanApplicableModalTeacher.value = teacher
}
function closeFaceScanApplicableModal() {
  faceScanApplicableModalTeacher.value = null
}
function onFaceScanApplicableUpdated(applicable) {
  if (faceScanApplicableModalTeacher.value) faceScanApplicableModalTeacher.value.face_scan_applicable = applicable
}

// --- Face scan (per-row, admin-managed) — status comes cheaply from
// TeacherResource.has_face_profile on the list itself; the actual photo is
// only fetched on demand when a row's "Scanned" badge is opened (see
// TeacherFaceController::show()). Capture/retake reuses the same
// FaceCaptureModal as the Profile page, just pointed at this teacher's
// admin endpoint instead of the logged-in user's own.
const faceCaptureTeacher = ref(null) // teacher row currently being scanned, or null
const faceCaptureTitle = ref('')

function openFaceCapture(teacher) {
  faceCaptureTitle.value = teacher.has_face_profile ? `Retake Face Scan — ${teacher.name}` : `Start Face Scan — ${teacher.name}`
  faceCaptureTeacher.value = teacher
}

function onFaceCaptured(data) {
  if (faceCaptureTeacher.value) faceCaptureTeacher.value.has_face_profile = data.has_face_profile
}

function closeFaceCapture() {
  faceCaptureTeacher.value = null
}

const viewPhotoTeacher = ref(null) // teacher row whose scan is being viewed, or null
const viewPhoto = reactive({ loading: false, error: '', photo: '' })

async function openViewPhoto(teacher) {
  viewPhotoTeacher.value = teacher
  viewPhoto.loading = true
  viewPhoto.error = ''
  viewPhoto.photo = ''
  try {
    const res = await api.get(`/teachers/${teacher.id}/face`)
    viewPhoto.photo = res.data.data.photo
  } catch (err) {
    viewPhoto.error = err.response?.data?.message || 'Could not load the face scan.'
  } finally {
    viewPhoto.loading = false
  }
}

function closeViewPhoto() {
  viewPhotoTeacher.value = null
}

function retakeFromView() {
  const teacher = viewPhotoTeacher.value
  closeViewPhoto()
  openFaceCapture(teacher)
}
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('teacher-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
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
        <span class="text-[13px] sm:text-sm text-gray-700">Teacher List</span>
      </div>
      <div v-if="authStore.can('teacher-add')" class="flex items-center gap-2">
        <RouterLink
          :to="{ name: 'teachers-bulk-upload' }"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 sm:px-5 hover:border-brand-blue hover:text-brand-blue transition-colors"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          Bulk Upload
        </RouterLink>
        <button
          type="button"
          class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
          @click="goToCreate"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
          Add Teacher
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
            <path d="M22 10v6M2 10l10-5 10 5-10 5-10-5z" />
            <path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5" />
          </svg>
        </span>
        <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">All Teachers</h2>
      </div>
      <div class="flex items-center gap-2" @click.stop>
        <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2 shrink-0 whitespace-nowrap">Total : {{ pagination.total }}</span>
        <input
          v-model="search"
          type="text"
          placeholder="Search teachers…"
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
        <div class="relative">
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
            class="absolute right-0 top-full mt-2 z-40 w-[min(260px,calc(100vw-2rem))] bg-white rounded-2xl shadow-panel border border-soft p-4 text-left"
            role="dialog"
            aria-label="Filter teachers"
          >
            <div class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Status</label>
                <div class="relative">
                  <select
                    v-model="statusFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Status</option>
                    <option value="yes">Active</option>
                    <option value="no">Inactive</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-[13px] text-label">Department</label>
                <div class="relative">
                  <select
                    v-model="departmentFilter"
                    class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                  >
                    <option value="">All Departments</option>
                    <option v-for="department in availableDepartments" :key="department.id" :value="department.id">{{ department.name }}</option>
                  </select>
                  <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                </div>
              </div>
              <div class="flex items-center justify-end gap-2 pt-1">
                <button type="button" class="min-w-[88px] h-9 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="resetFilters">Reset</button>
                <button type="button" class="min-w-[88px] h-9 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-all" @click="applyFilters">Search</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="overflow-hidden rounded-2xl shadow-panel">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1150px] text-left">
          <thead>
            <tr class="bg-subject-header text-white text-[12px] font-medium">
              <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
              <th class="px-4 py-1.5 font-medium">Name</th>
              <th class="px-4 py-1.5 font-medium">Emp Code</th>
              <th class="px-4 py-1.5 font-medium">Email</th>
              <th class="px-4 py-1.5 font-medium">Phone</th>
              <th class="px-4 py-1.5 font-medium">Department</th>
              <th class="px-4 py-1.5 font-medium">Designation</th>
              <th class="px-4 py-1.5 font-medium" v-if="authStore.can('teacher-face-scan')">Face Scan</th>
              <th class="px-4 py-1.5 font-medium" v-if="authStore.can('teacher-status-change')">Status</th>
              <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl" v-if="authStore.can('teacher-edit') || authStore.can('teacher-delete')">Action</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <tr v-if="loading">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
            </tr>
            <tr v-else-if="!teachers.length">
              <td colspan="10" class="px-4 py-8 text-center text-sm text-muted">No teachers found.</td>
            </tr>
            <tr
              v-for="(teacher, index) in teachers"
              v-else
              :key="teacher.id"
              class="text-[12px] text-gray-800 even:bg-gray-50 border-b border-gray-100 last:border-b-0"
            >
              <td class="px-4 py-1">
                <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium">
                  # {{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}
                </span>
              </td>
              <td class="px-4 py-1 font-semibold">{{ teacher.name }}</td>
              <td class="px-4 py-1">{{ teacher.emp_code || '—' }}</td>
              <td class="px-4 py-1">{{ teacher.email }}</td>
              <td class="px-4 py-1">{{ teacher.phone_no || '—' }}</td>
              <td class="px-4 py-1">{{ teacher.department || '—' }}</td>
              <td class="px-4 py-1">{{ teacher.designation || '—' }}</td>
              <td v-if="authStore.can('teacher-face-scan')" class="px-4 py-1" @click.stop>
                <button
                  v-if="teacher.has_face_profile"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 text-[12px] font-semibold hover:bg-green-100 transition-colors"
                  @click="openViewPhoto(teacher)"
                >
                  <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
                  Scanned
                </button>
                <button
                  v-else
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-full border border-input-border bg-white px-3 py-1.5 text-[12px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors"
                  @click="openFaceCapture(teacher)"
                >
                  <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                  Start
                </button>
              </td>
              <td v-if="authStore.can('teacher-status-change')" class="px-4 py-1" @click.stop>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="teacher.is_active"
                  :disabled="togglingId === teacher.id"
                  :title="teacher.is_active ? 'Click to deactivate' : 'Click to activate'"
                  class="relative inline-flex items-center w-20 h-6 rounded-full text-[10px] font-semibold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="teacher.is_active ? 'bg-btn-gradient text-white justify-start pl-2.5 pr-6' : 'bg-gray-300 text-gray-600 justify-end pl-6 pr-2.5'"
                  @click="toggleStatus(teacher)"
                >
                  <span>{{ teacher.is_active ? 'Active' : 'Inactive' }}</span>
                  <span
                    class="absolute top-[5px] left-[5px] w-[14px] h-[14px] rounded-full bg-white shadow transition-transform duration-200"
                    :class="teacher.is_active ? 'translate-x-[56px]' : 'translate-x-0'"
                  ></span>
                </button>
              </td>
              <td v-if="authStore.can('teacher-edit') || authStore.can('teacher-delete') || authStore.can('teacher-face-scan-applicable')" class="px-4 py-1 text-center relative" @click.stop>
                <RowActionMenu width="w-48">
                  <button v-if="authStore.can('teacher-edit')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="goToEdit(teacher)">
                    Edit
                  </button>
                  <button v-if="authStore.can('teacher-face-scan-applicable')" type="button" class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand-blue transition-colors" @click="openFaceScanApplicableModal(teacher)">
                    Face Scan Applicable
                  </button>
                  <button
                    v-if="authStore.can('teacher-delete')"
                    type="button"
                    class="w-full flex items-center gap-2 px-3.5 py-2 text-[13px] text-gray-700 hover:bg-soft hover:text-brand transition-colors disabled:opacity-50"
                    :disabled="deletingId === teacher.id"
                    @click="removeTeacher(teacher)"
                  >
                    {{ deletingId === teacher.id ? 'Deleting…' : 'Delete' }}
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

    <!-- Face-scan view modal — shows an already-scanned teacher's stored
         photo, fetched on demand (see TeacherFaceController::show()) rather
         than carried in the list response, since photos are bulky base64
         stills. -->
    <div v-if="viewPhotoTeacher" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="closeViewPhoto">
      <div class="w-full max-w-[420px] rounded-[28px] bg-white shadow-panel overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
          <h2 class="text-[18px] font-semibold text-gray-900 truncate pr-3">{{ viewPhotoTeacher.name }}&rsquo;s Face Scan</h2>
          <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0" aria-label="Close" @click="closeViewPhoto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
          </button>
        </div>
        <div class="p-5">
          <div class="relative rounded-[24px] overflow-hidden bg-gray-900 aspect-[4/3] flex items-center justify-center">
            <p v-if="viewPhoto.loading" class="text-white/80 text-sm">Loading&hellip;</p>
            <p v-else-if="viewPhoto.error" class="text-white/80 text-sm px-4 text-center">{{ viewPhoto.error }}</p>
            <img v-else-if="viewPhoto.photo" :src="viewPhoto.photo" alt="Registered face scan" class="w-full h-full object-cover" />
          </div>
          <div class="mt-5 flex items-center justify-center gap-3">
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="closeViewPhoto">
              Close
            </button>
            <button type="button" class="min-w-[130px] px-5 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all" @click="retakeFromView">
              Retake
            </button>
          </div>
        </div>
      </div>
    </div>

    <FaceCaptureModal
      v-if="faceCaptureTeacher"
      :title="faceCaptureTitle"
      :endpoint="`/teachers/${faceCaptureTeacher.id}/face`"
      @saved="onFaceCaptured"
      @close="closeFaceCapture"
    />

    <FaceScanApplicableModal
      v-if="faceScanApplicableModalTeacher"
      :teacher="faceScanApplicableModalTeacher"
      @close="closeFaceScanApplicableModal"
      @updated="onFaceScanApplicableUpdated"
    />
  </div>
</template>
