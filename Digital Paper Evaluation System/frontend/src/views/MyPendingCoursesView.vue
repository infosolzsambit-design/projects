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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useExamYearStore } from '../stores/examYear'
import { useExamTypeStore } from '../stores/examType'
import { useNotificationsStore } from '../stores/notifications'
import { useToast } from '../composables/useToast'
import FaceScanModal from '../components/FaceScanModal.vue'
import RaiseIssueModal from '../components/evaluation/RaiseIssueModal.vue'

const router = useRouter()
const toast = useToast()
const authStore = useAuthStore()
const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()
const notificationsStore = useNotificationsStore()

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
// The one-time evaluation_token minted by start-evaluation for whichever
// paper is currently going through the face-scan step — see
// proceedToEvaluation()'s own docblock for what this actually is.
const pendingEvaluationToken = ref(null)

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
// than a blank sheet. Every other state is disabled outright, not just
// 'closed': a window that hasn't opened yet ('upcoming') or was never
// scheduled at all ('unscheduled' — no evaluation_start_date/end_date on
// the sheet) has nothing real to evaluate against yet, so clicking
// through would only ever reach EvaluatePaperView.vue's own gate anyway —
// disabling it here instead is the honest state, not a dead end dressed
// up as a live button.
function paperAction(paper) {
  // An open Printing Issue means the physical sheet itself needs fixing
  // (see NotificationController::resolvePrintingIssue()) — takes priority
  // over the time-window state below since evaluating a sheet that can't
  // even be read properly makes no sense regardless of the schedule. An
  // open Timing Issue is deliberately *not* checked here — see
  // AnswerSheetResource's own 'blocks_evaluation' docblock for why.
  if (paper.blocks_evaluation) return { label: 'Printing Issue Pending', disabled: true }
  const { state } = paperTimeStatus(paper)
  if (state === 'active') return { label: isDraft(paper) ? 'Continue Evaluate' : 'Start Evaluate', disabled: false }
  if (checkingEvaluationId.value === paper.id) return { label: 'Checking…', disabled: true }
  if (state === 'closed') return { label: 'Evaluation Closed', disabled: true }
  if (state === 'upcoming') return { label: 'Not Yet Available', disabled: true }
  return { label: 'Not Scheduled', disabled: true } // 'unscheduled'
}

// An issue only counts as "still raised" while it's open — once an admin
// resolves it (see NotificationController's own resolve*Issue() methods),
// this paper's issue_master_name/issue_master_id stay on the row as
// history, but the flag badge and "Raise Issue" button should go back to
// normal rather than staying stuck forever.
function isIssueOpen(paper) {
  return !!paper.issue_master_name && paper.issue_status === 'open'
}

// Raise Issue is deliberately *not* just "whatever paperAction() allows" —
// a closed evaluation window (consumed_time ran out or the end date
// passed) with the sheet still not-started/in-draft is exactly the
// situation a Timing Issue exists for, so it stays open even though
// Evaluate itself is disabled there. It's only ever blocked for a window
// that hasn't opened yet (nothing to report before evaluation even
// starts), an already-open issue, or an already-blocking Printing Issue.
function canRaiseIssue(paper) {
  if (isIssueOpen(paper) || paper.blocks_evaluation) return false
  const { state } = paperTimeStatus(paper)
  return state !== 'upcoming' && state !== 'unscheduled'
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
function resetPaperFilters() {
  paperStatusFilter.value = ''
  paperTimeStatusFilter.value = ''
  showFilter.value = false
}

const courses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')
const activeCourse = ref(null)

const papers = ref([])
const papersLoading = ref(false)
const papersError = ref('')

// Search + Filter for the paper list below — same designed_files/
// subject-list.html header pattern as every other list page in this app,
// applied client-side since one course's own pending list is always
// already fully loaded (never paginated).
const paperSearch = ref('')
const paperStatusFilter = ref('') // '' | 'draft' | 'not_started'
const paperTimeStatusFilter = ref('') // '' | paperTimeStatus(paper).state
const filteredPapers = computed(() => {
  const q = paperSearch.value.trim().toLowerCase()
  return papers.value.filter((paper) => {
    if (q) {
      const haystack = `${paper.barcode || ''} ${paper.subject_barcode || ''} ${paper.roll_no || ''}`.toLowerCase()
      if (!haystack.includes(q)) return false
    }
    if (paperStatusFilter.value) {
      const wantsDraft = paperStatusFilter.value === 'draft'
      if (isDraft(paper) !== wantsDraft) return false
    }
    if (paperTimeStatusFilter.value && paperTimeStatus(paper).state !== paperTimeStatusFilter.value) return false
    return true
  })
})

async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  // Cleared up front, not just left alone when the new list turns out
  // empty — switching the header's Exam Year to a year with no pending
  // work of its own used to leave the *previous* year's active course and
  // paper list on screen (nothing ever re-ran selectCourse() to clear
  // them), even though the "no pending papers" message above was
  // otherwise correctly empty.
  activeCourse.value = null
  papers.value = []
  try {
    // Server-enforced too (see MyPendingCourseController::subjects()) —
    // sending it here picks *which* year for a super admin who wants one;
    // a non-super-admin gets it whether they send it or not.
    const params = {}
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/my-pending-courses', { params })
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
    const params = { course_id: course.course_id }
    if (!authStore.user?.is_super_admin) {
      params.exam_year = examYearStore.selectedYear
      if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    }
    const res = await api.get('/my-pending-courses/papers', { params })
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
    const { face_scan_required, has_face_profile, evaluation_token } = res.data.data

    if (!face_scan_required) {
      proceedToEvaluation(evaluation_token)
      return
    }
    if (!has_face_profile) {
      toast.error('Face scan is required for evaluation, but no face profile is registered on your account yet. Contact an administrator.')
      return
    }
    pendingEvaluationToken.value = evaluation_token
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
  const token = pendingEvaluationToken.value
  faceScanPaper.value = null
  pendingEvaluationToken.value = null
  proceedToEvaluation(token)
}

function onFaceScanCancel() {
  faceScanPaper.value = null
  pendingEvaluationToken.value = null
}

// "Raise Issue" row action — the same flow as EvaluatePaperView.vue's
// "Problem" button, just reachable straight from the list without opening
// the marking screen first (e.g. a printing defect that's obvious before
// even starting). Unlike that locked flow, the teacher genuinely picks
// the issue type here (see RaiseIssueModal's `locked` prop).
const issuePaper = ref(null) // paper currently showing the raise-issue modal, or null
function onIssueRaised() {
  issuePaper.value = null
  if (activeCourse.value) loadPapers(activeCourse.value)
  notificationsStore.loadUnresolvedCount()
}

// The marking screen's own URL is built from this one-time token, minted
// fresh by start-evaluation on every click (see this file's own docblock
// on checkingEvaluationId above) — never from this paper's own id, so a
// copied/bookmarked link can't just be reused indefinitely (it expires,
// and a later real "Start Evaluate" click supersedes it — see
// MyPendingCourseController::startEvaluation()/showByToken()).
function proceedToEvaluation(token) {
  router.push({ name: 'evaluate-paper', params: { token } })
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (authStore.can('my-pending-course-list')) loadCourses()
})

// Switching the header's Exam Year or Examination picker re-runs the whole
// course list (and, since selectCourse() re-picks the first one, whatever
// paper list naturally follows) against the new one.
watch(
  () => [examYearStore.selectedYear, examTypeStore.selectedId],
  () => {
    if (permissionChecked.value && authStore.can('my-pending-course-list')) loadCourses()
  },
)
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('my-pending-course-list')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb + page action -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-3.5 mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
          Home
        </RouterLink>
        <span class="w-px h-4 bg-gray-300 shrink-0"></span>
        <span class="text-[13px] sm:text-sm text-gray-700">My Pending Course</span>
      </div>
      <RouterLink
        v-if="authStore.can('my-completed-course-list')"
        :to="{ name: 'my-completed-courses' }"
        class="h-8 shrink-0 inline-flex items-center justify-center gap-2 rounded-full bg-btn-gradient text-white text-[13px] font-semibold px-4 sm:px-5 hover:opacity-90 transition-opacity"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></svg>
        Completed Course
      </RouterLink>
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
      <section class="relative bg-subject-header rounded-t-2xl rounded-b-none px-4 sm:px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
          </span>
          <h2 class="text-white text-[16px] sm:text-lg font-medium truncate">{{ activeCourse.course_code }} - {{ activeCourse.course_name }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2" @click.stop>
          <span class="inline-flex items-center rounded-[10px] bg-white/15 text-white text-[11px] sm:text-[12px] px-3.5 py-2">Pending : {{ activeCourse.pending_count }}</span>
          <input
            v-model="paperSearch"
            type="text"
            placeholder="Search unique number…"
            class="w-full sm:w-48 h-10 px-3.5 rounded-lg bg-white text-sm text-gray-800 outline-none border border-transparent focus:border-white/60 transition"
          />
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
              aria-label="Filter papers"
            >
              <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                  <label class="text-[13px] text-label">Status</label>
                  <div class="relative">
                    <select
                      v-model="paperStatusFilter"
                      class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                    >
                      <option value="">All Status</option>
                      <option value="draft">In Draft</option>
                      <option value="not_started">Not Started</option>
                    </select>
                    <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                  </div>
                </div>
                <div class="flex flex-col gap-1">
                  <label class="text-[13px] text-label">Time Status</label>
                  <div class="relative">
                    <select
                      v-model="paperTimeStatusFilter"
                      class="appearance-none w-full h-8 pl-3 pr-9 rounded-xl bg-input-bg text-[13px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition cursor-pointer"
                    >
                      <option value="">All Time Status</option>
                      <option value="active">Available</option>
                      <option value="upcoming">Not Yet Available</option>
                      <option value="closed">Closed</option>
                      <option value="unscheduled">Not Scheduled</option>
                    </select>
                    <svg class="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9" /></svg>
                  </div>
                </div>
                <div class="flex items-center justify-end gap-2 pt-1">
                  <button type="button" class="min-w-[88px] h-9 px-5 rounded-full border border-input-border bg-white text-[13px] font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors" @click="resetPaperFilters">Reset</button>
                  <button type="button" class="min-w-[88px] h-9 px-5 rounded-full bg-btn-gradient text-white text-[13px] font-semibold hover:opacity-90 transition-all" @click="showFilter = false">Search</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Paper list -->
      <section class="overflow-hidden rounded-2xl shadow-panel">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[860px] text-left">
            <thead>
              <tr class="bg-subject-header text-white text-[12px] font-medium">
                <th class="px-4 py-1.5 font-medium rounded-tl-2xl">#</th>
                <th class="px-4 py-1.5 font-medium">Script/Unique Number</th>
                <th class="px-4 py-1.5 font-medium">Marks</th>
                <th class="px-4 py-1.5 font-medium">Max Mark</th>
                <th class="px-4 py-1.5 font-medium">Status</th>
                <th class="px-4 py-1.5 font-medium">Time Status</th>
                <th class="px-4 py-1.5 font-medium text-center">Action</th>
                <th class="px-4 py-1.5 font-medium text-center rounded-tr-2xl">Raise Issue</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-if="papersLoading">
                <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">Loading&hellip;</td>
              </tr>
              <tr v-else-if="papersError">
                <td colspan="8" class="px-4 py-8 text-center text-sm text-brand">{{ papersError }}</td>
              </tr>
              <tr v-else-if="!papers.length">
                <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">No pending papers for this course.</td>
              </tr>
              <tr v-else-if="!filteredPapers.length">
                <td colspan="8" class="px-4 py-8 text-center text-sm text-muted">No papers match your search/filter.</td>
              </tr>
              <tr v-for="(paper, index) in filteredPapers" :key="paper.id" class="text-[12px] text-gray-800">
                <td class="px-4 py-1.5">
                  <span class="inline-flex items-center justify-center min-w-[40px] rounded-md status-gradient-border px-2 py-0.5 text-[11px] font-medium"># {{ index + 1 }}</span>
                </td>
                <td class="px-4 py-1.5 font-semibold">
                  {{ paper.barcode || paper.subject_barcode || paper.roll_no || '—' }}
                  <span
                    v-if="isIssueOpen(paper)"
                    class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-brand/10 text-brand text-[11px] font-medium px-2 py-0.5 align-middle whitespace-nowrap"
                    :title="paper.issue_remarks || undefined"
                  >
                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
                    {{ paper.issue_master_name }}
                  </span>
                  <!-- Confirms the resolution actually took effect (see
                       NotificationController's own resolve*Issue() methods)
                       — without this, a resolved sheet looks identical to
                       one that never had an issue at all, and the admin's
                       fix is invisible from here. -->
                  <span
                    v-else-if="paper.issue_master_name && paper.issue_status === 'resolved'"
                    class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-success/10 text-success text-[11px] font-medium px-2 py-0.5 align-middle whitespace-nowrap"
                    :title="paper.issue_admin_remarks || undefined"
                  >
                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M9 12l2 2 4-4" /></svg>
                    {{ paper.issue_master_name }} Resolved
                  </span>
                </td>
                <td class="px-4 py-1.5">{{ paper.marks ?? '—' }}</td>
                <td class="px-4 py-1.5">{{ paper.max_marks ?? '—' }}</td>
                <td class="px-4 py-1.5">
                  <span
                    class="inline-flex items-center rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
                    :class="isDraft(paper) ? 'text-brand-blue' : 'text-muted'"
                  >
                    {{ isDraft(paper) ? 'In Draft' : 'Not Started' }}
                  </span>
                </td>
                <td class="px-4 py-1.5">
                  <span
                    class="inline-flex items-center rounded-full status-gradient-border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap"
                    :class="paperTimeStatus(paper).badgeClass"
                  >
                    {{ paperTimeStatus(paper).label }}
                  </span>
                </td>
                <td class="px-4 py-1.5 text-center">
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
                <td class="px-4 py-1.5 text-center">
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-[13px] font-medium transition-colors"
                    :class="isIssueOpen(paper)
                      ? 'bg-brand/10 border border-brand/30 text-brand cursor-not-allowed opacity-80'
                      : !canRaiseIssue(paper)
                        ? 'status-gradient-border text-muted opacity-60 cursor-not-allowed'
                        : 'status-gradient-border text-gray-800 hover:text-brand'"
                    :disabled="isIssueOpen(paper) || !canRaiseIssue(paper)"
                    :title="isIssueOpen(paper)
                      ? `${paper.issue_master_name} already raised for this sheet`
                      : !canRaiseIssue(paper)
                        ? (paper.blocks_evaluation ? 'A printing issue is already pending for this sheet' : 'Evaluation has not started for this sheet yet')
                        : 'Raise Issue'"
                    @click="issuePaper = paper"
                  >
                    <svg v-if="isIssueOpen(paper)" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M9 12l2 2 4-4" /></svg>
                    <svg v-else class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
                    {{ isIssueOpen(paper) ? 'Issue Raised' : 'Raise Issue' }}
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

    <RaiseIssueModal
      v-if="issuePaper"
      :answer-sheet-id="issuePaper.id"
      :locked="false"
      @close="issuePaper = null"
      @raised="onIssueRaised"
    />
  </div>
</template>
