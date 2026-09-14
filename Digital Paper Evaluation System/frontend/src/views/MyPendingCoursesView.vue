<script setup>
// Sidebar's "My Pending Course" — the logged-in teacher's own not-yet-
// evaluated answer sheets, grouped by the actual assigned course (not the
// answer-sheet upload CSV's own subject_code/subject_name text, which can
// name something other than the course the packet was really mapped to
// — see MyPendingCourseController's docblock). Mirrors designed_files/
// subject-list.html: a row of course chips up top (auto-selecting the
// first one), a colored header naming the active course, and the paper
// list below it. Gated UI-only by 'my-pending-course-list' (see
// stores/auth.js's authStore.can()) — no route/middleware gate, same
// show/hide-only approach as the rest of this app's permission checks.
//
// The "Start Evaluate"/"Evaluate" button, once the face-scan gate (if
// any) passes, opens EvaluatePaperView.vue — the real PDF + marks-entry
// screen, wired to this same answer sheet's real data (see that view's
// own docblock).
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../composables/useToast'
import { encodeId } from '../utils/obfuscateId'
import FaceScanModal from '../components/FaceScanModal.vue'

const router = useRouter()
const toast = useToast()
const authStore = useAuthStore()

// "Start Evaluate" gate — POST /my-pending-courses/papers/{id}/start-
// evaluation (see MyPendingCourseController::startEvaluation()'s own
// docblock) decides, server-side, whether this teacher needs a face scan
// before evaluating this particular sheet: general_settings'
// face_scan_applicable off skips it for everyone; on, it still defers to
// this teacher's own teacher_details.face_scan_applicable flag. Never
// decided client-side — the API is the source of truth every time the
// button's clicked, not just cached from the page's own initial load.
const checkingEvaluationId = ref(null) // paper.id currently mid-check, or null
const faceScanPaper = ref(null) // paper currently showing the scan modal, or null

// Live "Time Status" badge (designed_files/subject-list.html's "Available
// 59 minute from now" style), computed against the sheet's own
// evaluation_start_date/evaluation_end_date (see AssignTeacherView.vue —
// now a full datetime, not just a date) instead of just echoing the raw
// window back. `now` ticks every 30s so an open row's badge counts down
// live instead of freezing at whatever it read on page load.
const now = ref(Date.now())
let nowTimer = null
onMounted(() => { nowTimer = setInterval(() => { now.value = Date.now() }, 30000) })
onBeforeUnmount(() => clearInterval(nowTimer))

function parseSheetDateTime(value) {
  // Resource sends 'YYYY-MM-DD HH:mm' — Date needs the 'T' separator to
  // parse it as local time reliably across browsers.
  return new Date(value.replace(' ', 'T')).getTime()
}

// Largest whole unit that still reads naturally — "2 day(s)", "3 hour(s)",
// or "45 minute(s)" — never all three at once, matching the design's own
// single-unit phrasing.
function formatDuration(ms) {
  const minutes = Math.max(1, Math.round(ms / 60000))
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'}`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'}`
  const days = Math.round(hours / 24)
  return `${days} day${days === 1 ? '' : 's'}`
}

// `state` drives the Evaluate button below (see paperAction()) as well as
// the badge — 'unscheduled'/'upcoming' before the window's open yet,
// 'active' while it's open, 'closed' once it's passed.
function paperTimeStatus(paper) {
  if (!paper.evaluation_start_date || !paper.evaluation_end_date) {
    return { state: 'unscheduled', label: 'Not scheduled', badgeClass: 'text-muted' }
  }
  const start = parseSheetDateTime(paper.evaluation_start_date)
  const end = parseSheetDateTime(paper.evaluation_end_date)
  if (now.value < start) {
    return { state: 'upcoming', label: `Opens in ${formatDuration(start - now.value)}`, badgeClass: 'text-muted' }
  }
  if (now.value > end) {
    return { state: 'closed', label: 'Evaluation window closed', badgeClass: 'text-brand' }
  }
  return { state: 'active', label: `Evaluation Available ${formatDuration(end - now.value)} from now`, badgeClass: 'text-success' }
}

// A sheet has an in-progress draft the moment EvaluatePaperView.vue's
// autosave has fired at least once (see MyPendingCourseController::
// saveDraft()) — draft_marks gets set then, even to 0 if nothing's been
// entered yet, so its mere presence (not its value) is what "started but
// not completed" actually means. Comes back on every papers() row
// already (AnswerSheetResource never gates it behind whenLoaded), so no
// extra request is needed just to know this.
function isDraft(paper) {
  return paper.draft_marks !== null && paper.draft_marks !== undefined
}

// The Evaluate button's label/enabled-ness for a row, driven by the same
// time-window state as its badge: a live window says "Start Evaluate" —
// which runs the face-scan check below (a required scan's own "Start
// Evaluation" click, inside FaceScanModal itself once matched, is what
// actually proceeds — see onFaceScanMatched()) — or "Continue Evaluate"
// once a draft already exists, so it's clear there's work waiting rather
// than a blank sheet. One that hasn't opened yet stays clickable but only
// as far as the "coming soon" placeholder below (the real scoring screen
// doesn't exist yet either way); one that's closed is disabled outright —
// there's nothing left to do with it here.
function paperAction(paper) {
  const { state } = paperTimeStatus(paper)
  if (state === 'closed') return { label: 'Evaluation Closed', disabled: true }
  if (checkingEvaluationId.value === paper.id) return { label: 'Checking…', disabled: true }
  if (state === 'active') return { label: isDraft(paper) ? 'Continue Evaluate' : 'Start Evaluate', disabled: false }
  return { label: 'Evaluate', disabled: false }
}

const courses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')
const activeCourse = ref(null)

const papers = ref([])
const papersLoading = ref(false)
const papersError = ref('')

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  try {
    const res = await api.get('/my-pending-courses')
    courses.value = res.data.data
    // First course auto-selected; user can pick another chip afterwards.
    if (courses.value.length) selectCourse(courses.value[0])
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load your pending courses.'
  } finally {
    coursesLoading.value = false
  }
}

function selectCourse(course) {
  activeCourse.value = course
  loadPapers(course)
}

async function loadPapers(course) {
  papersLoading.value = true
  papersError.value = ''
  papers.value = []
  try {
    const res = await api.get('/my-pending-courses/papers', {
      params: { course_id: course.course_id },
    })
    papers.value = res.data.data
  } catch (err) {
    papersError.value = err.response?.data?.message || 'Could not load papers for this course.'
  } finally {
    papersLoading.value = false
  }
}

async function evaluate(paper) {
  if (paperAction(paper).disabled) return

  checkingEvaluationId.value = paper.id
  try {
    const res = await api.post(`/my-pending-courses/papers/${paper.id}/start-evaluation`)
    const { face_scan_required, has_face_profile } = res.data.data

    if (!face_scan_required) {
      proceedToEvaluation(paper)
      return
    }
    if (!has_face_profile) {
      toast.error('Face scan is required for evaluation, but no face profile is registered on your account yet. Contact an administrator.')
      return
    }
    faceScanPaper.value = paper
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not start evaluation for this paper.')
  } finally {
    checkingEvaluationId.value = null
  }
}

function onFaceScanMatched() {
  // Fires only once the teacher clicks FaceScanModal's own "Start
  // Evaluation" button (shown there after a successful match) — not
  // merely on a match, so nothing here proceeds silently the instant the
  // scan succeeds.
  const paper = faceScanPaper.value
  faceScanPaper.value = null
  proceedToEvaluation(paper)
}

function onFaceScanCancel() {
  faceScanPaper.value = null
}

function proceedToEvaluation(paper) {
  router.push({ name: 'evaluate-paper', params: { token: encodeId(paper.id) } })
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('my-pending-course-list')) loadCourses()
})
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('my-pending-course-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-3.5 mb-5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">My Pending Course</span>
    </div>

    <!-- Course chips -->
    <section class="bg-white rounded-2xl shadow-panel p-4 sm:p-5 mb-5">
      <div class="flex items-center gap-2 mb-4">
        <svg class="w-5 h-5 text-brand-blue shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
        <h2 class="text-[15px] sm:text-base font-semibold text-gray-800">Course Assigned List</h2>
      </div>

      <p v-if="coursesLoading" class="text-center text-sm text-muted py-6">Loading&hellip;</p>
      <p v-else-if="coursesError" class="text-center text-sm text-brand py-6">{{ coursesError }}</p>
      <p v-else-if="!courses.length" class="text-center text-sm text-muted py-6">You have no pending papers to evaluate right now.</p>
      <div v-else class="flex flex-wrap gap-3 pb-1">
        <button
          v-for="course in courses"
          :key="course.course_id"
          type="button"
          class="subject-chip shrink-0 flex items-center gap-[2px] rounded-xl border bg-white shadow-chip px-2 py-3 text-[12px] sm:text-[13px] font-medium text-left transition-colors"
          :class="activeCourse === course ? 'border-brand-blue text-brand-blue' : 'border-chip-border text-gray-800'"
          @click="selectCourse(course)"
        >
          <svg class="w-5 h-5 text-success shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
          <span class="min-w-0 leading-tight break-words">{{ course.course_code }} - {{ course.course_name?.toUpperCase() }}</span>
        </button>
      </div>
    </section>

    <template v-if="activeCourse">
      <!-- Active course header -->
      <section class="bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
          </span>
          <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">{{ activeCourse.course_code }} - {{ activeCourse.course_name }}</h2>
        </div>
        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2">Pending : {{ activeCourse.pending_count }}</span>
        </div>
      </section>

      <!-- Paper list -->
      <section class="overflow-hidden rounded-2xl shadow-panel">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[680px] text-left">
            <thead>
              <tr class="bg-subject-header text-white text-[13px] font-medium">
                <th class="px-4 py-3.5 font-medium rounded-tl-2xl">#</th>
                <th class="px-4 py-3.5 font-medium">Script/Unique Number</th>
                <th class="px-4 py-3.5 font-medium">Marks</th>
                <th class="px-4 py-3.5 font-medium">Max Mark</th>
                <th class="px-4 py-3.5 font-medium">Status</th>
                <th class="px-4 py-3.5 font-medium">Time Status</th>
                <th class="px-4 py-3.5 font-medium text-center rounded-tr-2xl">Action</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="papersLoading">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="papersError">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-brand">{{ papersError }}</td>
              </tr>
              <tr v-else-if="!papers.length">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-muted">No pending papers for this course.</td>
              </tr>
              <tr v-for="(paper, index) in papers" :key="paper.id" class="text-[13px] text-gray-800">
                <td class="px-4 py-4">
                  <span class="inline-flex items-center justify-center min-w-[46px] rounded-md status-gradient-border px-2 py-1.5 text-[12px] font-medium"># {{ index + 1 }}</span>
                </td>
                <td class="px-4 py-4 font-semibold">{{ paper.barcode || paper.subject_barcode || paper.roll_no || '—' }}</td>
                <td class="px-4 py-4">{{ paper.marks ?? '—' }}</td>
                <td class="px-4 py-4">{{ paper.max_marks ?? '—' }}</td>
                <td class="px-4 py-4">
                  <span
                    class="inline-flex items-center rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
                    :class="isDraft(paper) ? 'text-brand-blue' : 'text-muted'"
                  >
                    {{ isDraft(paper) ? 'In Draft' : 'Not Started' }}
                  </span>
                </td>
                <td class="px-4 py-4">
                  <span
                    class="inline-flex items-center rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
                    :class="paperTimeStatus(paper).badgeClass"
                  >
                    {{ paperTimeStatus(paper).label }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg status-gradient-border px-3.5 py-2 text-[13px] font-medium transition-colors"
                    :class="paperAction(paper).disabled ? 'text-muted opacity-60 cursor-not-allowed' : 'text-gray-800 hover:text-brand-blue'"
                    :disabled="paperAction(paper).disabled"
                    @click="evaluate(paper)"
                  >
                    <svg v-if="paperAction(paper).disabled" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="12" r="10" />
                      <line x1="8" y1="8" x2="16" y2="16" />
                    </svg>
                    <svg v-else class="w-4 h-4 text-brand shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                      <polyline points="14 2 14 8 20 8" />
                      <circle cx="11" cy="14" r="2.5" />
                      <path d="M13 16l1.5 1.5" />
                    </svg>
                    {{ paperAction(paper).label }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <FaceScanModal
      v-if="faceScanPaper"
      title="Verify Your Face to Start Evaluation"
      @matched="onFaceScanMatched"
      @cancel="onFaceScanCancel"
    />
  </div>
</template>
