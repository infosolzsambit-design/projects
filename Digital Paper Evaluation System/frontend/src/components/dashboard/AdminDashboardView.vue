<script setup>
// Admin's own dashboard — matches designed_files/admin_dashboard.png.
// Backed by GET /dashboard/admin-summary (see DashboardController::
// adminSummary() on the backend for exactly how every number here is
// computed) — one combined fetch on mount, since the whole page loads
// together. Only the numbers come from there; label/tint/badge/icon below
// stay local static metadata (purely cosmetic, never returned by the API)
// so the two KPI rows' wording/colours stay whatever's been tuned here
// without the backend needing to know about presentation at all.
import { computed, onMounted, ref, watch } from 'vue'
import api from '../../utils/api'
import { useExamYearStore } from '../../stores/examYear'
import { useExamTypeStore } from '../../stores/examType'
import DepartmentProgressModal from './DepartmentProgressModal.vue'
import TeacherWorkloadModal from './TeacherWorkloadModal.vue'
import CoursePendingModal from './CoursePendingModal.vue'

const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const loading = ref(true)
const loadError = ref('')
const summary = ref(null)

// Row 1 (org-wide totals) stays whatever it already is regardless of the
// header's own Exam Year picker (see DashboardController::totals()'s own
// docblock — those aren't tied to one exam year at all); everything else
// here (workflow counts, the two charts, department/teacher/course
// breakdowns) is answer-sheet activity for whichever year is picked.
// Unlike the header picker's other pages (Question Papers/Assigned
// Teacher List/My Pending Course/My Completed Course — see
// stores/examYear.js's own docblock), this sends exam_year *and*
// exam_type_id for *every* role, including a super admin: on this page
// the picker is the whole point of "which year/examination am I looking
// at," not an optional narrowing a super admin can ignore.
async function fetchSummary() {
  loading.value = true
  loadError.value = ''
  try {
    // examTypeStore.selectedId starts out null until its own async load()
    // (kicked off by AppHeader.vue, not this component) resolves — that
    // can still be in flight the moment this page's own onMounted below
    // fires, so the key is only sent once there's a real id to send;
    // omitted, the backend's own HasExamTypeScope falls back to the exact
    // same "most recently created active exam type" default the store
    // itself will shortly settle on, so this is never actually wrong,
    // just possibly one redundant refetch once the store catches up.
    const params = { exam_year: examYearStore.selectedYear }
    if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    const res = await api.get('/dashboard/admin-summary', { params })
    summary.value = res.data.data
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load the dashboard.'
  } finally {
    loading.value = false
  }
}
onMounted(fetchSummary)
watch(() => [examYearStore.selectedYear, examTypeStore.selectedId], fetchSummary)

function fmt(n) {
  return (n ?? 0).toLocaleString()
}

// --- Row 1: org-wide totals — order matches DashboardController::totals().
// Each one links to its own real list page (`to` is a route name) so the
// count itself is just a shortcut into "go see them," not a dead end.
const orgKpiMeta = [
  { key: 'programs', label: 'Programs', tint: 'bg-blue-100', badge: 'bg-blue-500', icon: 'bank', to: 'master-programs' },
  { key: 'courses', label: 'Courses', tint: 'bg-emerald-100', badge: 'bg-emerald-500', icon: 'book', to: 'master-courses' },
  { key: 'departments', label: 'Departments', tint: 'bg-violet-100', badge: 'bg-violet-500', icon: 'org', to: 'master-departments' },
  { key: 'teachers', label: 'Teachers', tint: 'bg-orange-100', badge: 'bg-orange-500', icon: 'users', to: 'teachers' },
  { key: 'students', label: 'Students', tint: 'bg-cyan-100', badge: 'bg-cyan-500', icon: 'grad', to: 'students' },
  { key: 'question_papers', label: 'All Question Papers', tint: 'bg-rose-100', badge: 'bg-rose-500', icon: 'doc', to: 'question-papers' },
  { key: 'answer_sheets', label: 'All Answer Sheets', tint: 'bg-purple-100', badge: 'bg-purple-500', icon: 'sheets', to: 'answer-sheets' },
]
const orgKpis = computed(() => orgKpiMeta.map((kpi) => ({ ...kpi, value: fmt(summary.value?.totals?.[kpi.key]) })))

// --- Row 2: evaluation-workflow counts — order matches
// DashboardController::workflow().
const workflowKpiMeta = [
  { key: 'assigned', label: 'Assigned Sheets', tint: 'bg-emerald-100', badge: 'bg-emerald-500', icon: 'send' },
  { key: 'pending_assignment', label: 'Pending Assignment', tint: 'bg-amber-100', badge: 'bg-amber-500', icon: 'clock' },
  { key: 'evaluated', label: 'Evaluated Sheets', tint: 'bg-sky-100', badge: 'bg-sky-500', icon: 'check' },
  { key: 'pending_evaluation', label: 'Pending Evaluation', tint: 'bg-rose-100', badge: 'bg-rose-500', icon: 'hourglass' },
  { key: 'raised_issues', label: 'Raised Issues', tint: 'bg-violet-100', badge: 'bg-violet-500', icon: 'warn' },
  { key: 'pending_issues', label: 'Pending Issues', tint: 'bg-blue-100', badge: 'bg-blue-500', icon: 'docSearch' },
]
const workflowKpis = computed(() => workflowKpiMeta.map((kpi) => ({ ...kpi, value: fmt(summary.value?.workflow?.[kpi.key]) })))

// --- Evaluation Progress — Assigned Sheets vs Evaluated Sheets -------------
// A direct side-by-side comparison of the same two totals already shown as
// their own KPI cards in row 2 (workflow.assigned/workflow.evaluated) —
// not a trend over time; there's no week/date dimension here at all. Each
// bar's width is relative to whichever of the two totals is larger, so the
// comparison stays readable whether the real numbers are single digits or
// in the thousands.
const assignedTotal = computed(() => summary.value?.workflow?.assigned ?? 0)
const evaluatedTotal = computed(() => summary.value?.workflow?.evaluated ?? 0)
const progressBarMax = computed(() => Math.max(assignedTotal.value, evaluatedTotal.value, 1))
const assignedBarPct = computed(() => Math.round((assignedTotal.value / progressBarMax.value) * 100))
const evaluatedBarPct = computed(() => Math.round((evaluatedTotal.value / progressBarMax.value) * 100))

// --- Evaluation Status — donut chart ---------------------------------------
const totalAnswerSheets = computed(() => summary.value?.evaluation_status?.total ?? 0)
const donutSegments = computed(() => {
  const s = summary.value?.evaluation_status
  const total = s?.total || 0
  const pct = (n) => (total ? `${((n / total) * 100).toFixed(1)}%` : '0.0%')
  return [
    { label: 'Evaluated', value: s?.evaluated ?? 0, displayPct: pct(s?.evaluated ?? 0), color: '#22c55e' },
    { label: 'Pending Evaluation', value: s?.pending_evaluation ?? 0, displayPct: pct(s?.pending_evaluation ?? 0), color: '#f9a228' },
    { label: 'Not Assigned', value: s?.not_assigned ?? 0, displayPct: pct(s?.not_assigned ?? 0), color: '#2f56c0' },
  ]
})
// Arc shares are normalised across the three segments so the ring always
// completes a full circle — the *displayed* percentages above are each
// segment's own share of totalAnswerSheets (as the design shows), which
// doesn't sum to 100 since "Not Assigned" is really a breakdown within
// "Pending Evaluation", not a fourth disjoint bucket; the ring itself is a
// visual approximation of that, not a literal encoding of the legend math.
const donutRadius = 70
const donutCircumference = 2 * Math.PI * donutRadius
const donutArcs = computed(() => {
  const segments = donutSegments.value
  const donutTotal = segments.reduce((sum, s) => sum + s.value, 0) || 1
  let offset = 0
  return segments.map((segment) => {
    const share = segment.value / donutTotal
    const length = share * donutCircumference
    const arc = { ...segment, dasharray: `${length} ${donutCircumference - length}`, dashoffset: -offset }
    offset += length
    return arc
  })
})

// --- Department Wise Progress — evaluated/pending/not-assigned per dept ---
// DashboardController::departmentProgress() already returns
// evaluated_pct/pending_pct/not_assigned_pct pre-computed (and guaranteed
// to sum to 100) — just renamed to this template's own camelCase.
const departments = computed(() => (summary.value?.department_progress ?? []).map((d) => ({
  name: d.name,
  evaluated: d.evaluated_pct,
  pending: d.pending_pct,
  notAssigned: d.not_assigned_pct,
})))
const departmentsHasMore = computed(() => summary.value?.department_progress_has_more ?? false)

// --- Issue Summary -----------------------------------------------------
const issueSummaryMeta = [
  { key: 'total_raised', label: 'Raised Issues', tint: 'bg-rose-100', text: 'text-brand', icon: 'warnCircle' },
  { key: 'pending', label: 'Pending Issues', tint: 'bg-amber-100', text: 'text-badge', icon: 'clock' },
  { key: 'resolved', label: 'Resolved Issues', tint: 'bg-emerald-100', text: 'text-success', icon: 'check' },
]
const issueSummary = computed(() => issueSummaryMeta.map((stat) => ({ ...stat, value: summary.value?.issue_summary?.[stat.key] ?? 0 })))

// --- Teacher Workload ----------------------------------------------------
const teacherWorkload = computed(() => summary.value?.teacher_workload ?? [])
const teacherWorkloadHasMore = computed(() => summary.value?.teacher_workload_has_more ?? false)

// --- Course Wise Pending Evaluation ----------------------------------------
const coursePending = computed(() => (summary.value?.course_pending ?? []).map((c) => ({ ...c, pendingPct: c.pending_pct })))
const coursePendingHasMore = computed(() => summary.value?.course_pending_has_more ?? false)

// --- "See All" modals — one flag per card, each backed by its own
// uncapped endpoint (see the three modal components' own docblocks).
const showDepartmentsModal = ref(false)
const showTeacherWorkloadModal = ref(false)
const showCoursePendingModal = ref(false)
</script>

<template>
  <p v-if="loading" class="text-center text-sm text-muted py-10">Loading dashboard&hellip;</p>
  <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

  <div v-else class="flex flex-col gap-4">
    <!-- Row 1: org-wide totals — the last two labels ("All Question
         Papers"/"All Answer Sheets") are noticeably longer than the first
         five, so those two columns get extra fractional width instead of
         an even 7-way split, letting every title fit on one line without
         shrinking the first five any further than necessary. -->
    <section class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-[1fr_1fr_1fr_1fr_1fr_1.4fr_1.4fr] gap-3">
      <RouterLink
        v-for="kpi in orgKpis"
        :key="kpi.label"
        :to="{ name: kpi.to }"
        class="rounded-2xl px-4 py-3.5 flex items-center gap-3 transition-transform hover:-translate-y-0.5"
        :class="kpi.tint"
      >
        <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 text-white" :class="kpi.badge">
          <svg v-if="kpi.icon === 'bank'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M4 21V9l8-5 8 5v12M9 21v-6h6v6" /></svg>
          <svg v-else-if="kpi.icon === 'book'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
          <svg v-else-if="kpi.icon === 'org'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="2.5" /><path d="M12 7.5V12M4 20v-2a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v2M12 12l-5 3M12 12l5 3" /></svg>
          <svg v-else-if="kpi.icon === 'users'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
          <svg v-else-if="kpi.icon === 'grad'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10L12 5 2 10l10 5 10-5z" /><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5" /></svg>
          <svg v-else-if="kpi.icon === 'doc'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>
          <svg v-else-if="kpi.icon === 'sheets'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3" /><path d="M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3" /><rect x="8" y="2" width="8" height="20" rx="1" /></svg>
        </span>
        <div class="min-w-0">
          <p class="text-[12px] font-medium text-gray-700 leading-tight whitespace-nowrap overflow-hidden text-ellipsis">{{ kpi.label }}</p>
          <p class="text-[19px] font-bold text-gray-900 leading-tight">{{ kpi.value }}</p>
        </div>
      </RouterLink>
    </section>

    <!-- Row 2: evaluation-workflow counts -->
    <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
      <div v-for="kpi in workflowKpis" :key="kpi.label" class="rounded-2xl px-4 py-3.5 flex items-center gap-3" :class="kpi.tint">
        <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 text-white" :class="kpi.badge">
          <svg v-if="kpi.icon === 'send'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg>
          <svg v-else-if="kpi.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
          <svg v-else-if="kpi.icon === 'check'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
          <svg v-else-if="kpi.icon === 'hourglass'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 2h14M5 22h14M5 2c0 6 6 6 6 10s-6 4-6 10M19 2c0 6-6 6-6 10s6 4 6 10" /></svg>
          <svg v-else-if="kpi.icon === 'warn'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          <svg v-else-if="kpi.icon === 'docSearch'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6" /><polyline points="14 2 14 8 20 8" /><circle cx="17" cy="17" r="3.5" /><line x1="19.8" y1="19.8" x2="22" y2="22" /></svg>
        </span>
        <div class="min-w-0">
          <p class="text-[12px] font-medium text-gray-700 leading-tight whitespace-nowrap overflow-hidden text-ellipsis">{{ kpi.label }}</p>
          <p class="text-[19px] font-bold text-gray-900 leading-tight">{{ kpi.value }}</p>
        </div>
      </div>
    </section>

    <!-- Row 3: Evaluation Progress / Evaluation Status / Department Wise Progress -->
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Evaluation Progress</h3>
        </div>
        <p class="text-[12px] text-muted mb-4">Assigned sheets vs. evaluated sheets</p>
        <div class="flex flex-col gap-5 mt-2">
          <div>
            <div class="flex items-center justify-between text-[12.5px] mb-1.5">
              <span class="inline-flex items-center gap-1.5 font-medium text-gray-700"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>Assigned</span>
              <span class="font-bold text-gray-900">{{ fmt(assignedTotal) }}</span>
            </div>
            <div class="h-3 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: assignedBarPct + '%' }"></div>
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between text-[12.5px] mb-1.5">
              <span class="inline-flex items-center gap-1.5 font-medium text-gray-700"><span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>Evaluated</span>
              <span class="font-bold text-gray-900">{{ fmt(evaluatedTotal) }}</span>
            </div>
            <div class="h-3 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full bg-sky-500 rounded-full transition-all" :style="{ width: evaluatedBarPct + '%' }"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-4">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83" /><path d="M22 12A10 10 0 0 0 12 2v10z" /></svg>
          <h3 class="font-semibold text-gray-900">Evaluation Status</h3>
        </div>
        <div class="flex items-center gap-5">
          <svg viewBox="0 0 200 200" class="w-32 h-32 sm:w-36 sm:h-36 shrink-0">
            <g transform="rotate(-90 100 100)">
              <circle v-for="arc in donutArcs" :key="arc.label" cx="100" cy="100" :r="donutRadius" fill="none" :stroke="arc.color" stroke-width="26" :stroke-dasharray="arc.dasharray" :stroke-dashoffset="arc.dashoffset" />
            </g>
            <text x="100" y="96" text-anchor="middle" class="fill-gray-900" font-size="26" font-weight="700">{{ totalAnswerSheets.toLocaleString() }}</text>
            <text x="100" y="118" text-anchor="middle" class="fill-gray-400" font-size="11">Total Answer Sheets</text>
          </svg>
          <div class="flex flex-col gap-2.5 min-w-0">
            <div v-for="segment in donutSegments" :key="segment.label" class="flex items-start gap-2">
              <span class="w-3 h-3 rounded-full mt-1 shrink-0" :style="{ background: segment.color }"></span>
              <div class="min-w-0">
                <p class="text-[12px] text-gray-600 truncate">{{ segment.label }}</p>
                <p class="text-[14px] font-bold text-gray-900">{{ segment.value.toLocaleString() }} <span class="text-[12px] font-medium text-muted">({{ segment.displayPct }})</span></p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-4">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Department Wise Progress</h3>
        </div>
        <p v-if="!departments.length" class="text-[12px] text-muted text-center py-6">No department has any answer sheets yet.</p>
        <div v-else class="flex flex-col gap-2.5">
          <div v-for="dept in departments" :key="dept.name" class="flex items-center gap-2.5">
            <span :title="dept.name" class="w-[160px] shrink-0 text-[12px] text-gray-700 truncate cursor-default">{{ dept.name }}</span>
            <div class="flex-1 max-w-[110px] h-2.5 rounded-full overflow-hidden bg-gray-100 flex">
              <span class="h-full bg-emerald-500" :style="{ width: dept.evaluated + '%' }"></span>
              <span class="h-full bg-amber-400" :style="{ width: dept.pending + '%' }"></span>
              <span class="h-full bg-gray-300" :style="{ width: dept.notAssigned + '%' }"></span>
            </div>
            <span class="w-9 shrink-0 text-right text-[12px] font-semibold text-gray-800">{{ dept.evaluated }}%</span>
          </div>
        </div>
        <div class="flex items-center justify-between gap-3 mt-3.5">
          <div class="flex items-center gap-4 text-[11px] text-gray-600">
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>Evaluated</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>Pending</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-300"></span>Not Assigned</span>
          </div>
          <button v-if="departmentsHasMore" type="button" class="shrink-0 text-[11.5px] font-semibold text-brand-blue hover:underline" @click="showDepartmentsModal = true">
            See All
          </button>
        </div>
      </div>
    </section>

    <!-- Row 4: Issue Summary / Teacher Workload / Course Wise Pending Evaluation -->
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-4">
          <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Issue Summary</h3>
        </div>
        <div class="grid grid-cols-3 gap-2.5">
          <RouterLink
            v-for="stat in issueSummary"
            :key="stat.label"
            :to="{ name: 'notifications' }"
            class="rounded-2xl p-3 text-center transition-transform hover:-translate-y-0.5"
            :class="stat.tint"
          >
            <span class="w-9 h-9 rounded-full bg-white/70 mx-auto flex items-center justify-center mb-2" :class="stat.text">
              <svg v-if="stat.icon === 'warnCircle'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
              <svg v-else-if="stat.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
              <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
            </span>
            <p class="text-[11px] font-medium text-gray-600 leading-tight">{{ stat.label }}</p>
            <p class="text-[20px] font-bold text-gray-900 mt-0.5">{{ stat.value }}</p>
          </RouterLink>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-panel p-5 xl:col-span-1 overflow-hidden">
        <div class="flex items-center gap-2 mb-3">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
          <h3 class="font-semibold text-gray-900">Teacher Workload</h3>
          <button v-if="teacherWorkloadHasMore" type="button" class="ml-auto shrink-0 text-[11.5px] font-semibold text-brand-blue hover:underline" @click="showTeacherWorkloadModal = true">
            See All
          </button>
        </div>
        <div class="overflow-x-auto -mx-1">
          <table class="w-full min-w-[420px] text-left text-[12px]">
            <thead>
              <tr class="text-muted">
                <th class="px-1 py-1.5 font-medium">#</th>
                <th class="px-1 py-1.5 font-medium">Teacher Name</th>
                <th class="px-1 py-1.5 font-medium text-center">Assigned</th>
                <th class="px-1 py-1.5 font-medium text-center">Evaluated</th>
                <th class="px-1 py-1.5 font-medium text-center">Pending</th>
                <th class="px-1 py-1.5 font-medium text-center">Issues</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!teacherWorkload.length">
                <td colspan="6" class="px-1 py-6 text-center text-muted">No answer sheets assigned to any teacher yet.</td>
              </tr>
              <tr v-for="(row, i) in teacherWorkload" :key="row.name" class="border-t border-gray-100">
                <td class="px-1 py-1.5 text-gray-500">{{ i + 1 }}</td>
                <td :title="row.emp_code" class="px-1 py-1.5 font-medium text-gray-800 whitespace-nowrap cursor-default">{{ row.name }}</td>
                <td class="px-1 py-1.5 text-center text-gray-700">{{ row.assigned }}</td>
                <td class="px-1 py-1.5 text-center text-gray-700">{{ row.evaluated }}</td>
                <td class="px-1 py-1.5 text-center">
                  <span class="inline-flex min-w-[28px] justify-center rounded-full bg-amber-50 text-badge font-semibold px-1.5 py-0.5">{{ row.pending }}</span>
                </td>
                <td class="px-1 py-1.5 text-center">
                  <span class="inline-flex min-w-[22px] justify-center rounded-full bg-rose-50 text-brand font-semibold px-1.5 py-0.5">{{ row.issues }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-panel p-5 overflow-hidden">
        <div class="flex items-center gap-2 mb-3">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
          <h3 class="font-semibold text-gray-900">Course Wise Pending Evaluation</h3>
          <button v-if="coursePendingHasMore" type="button" class="ml-auto shrink-0 text-[11.5px] font-semibold text-brand-blue hover:underline" @click="showCoursePendingModal = true">
            See All
          </button>
        </div>
        <div class="overflow-x-auto -mx-1">
          <table class="w-full min-w-[380px] text-left text-[12px]">
            <thead>
              <tr class="text-muted">
                <th class="px-1 py-1.5 font-medium">#</th>
                <th class="px-1 py-1.5 font-medium">Course Name</th>
                <th class="px-1 py-1.5 font-medium text-center">Pending</th>
                <th class="px-1 py-1.5 font-medium text-center">Total</th>
                <th class="px-1 py-1.5 font-medium text-center">Pending %</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!coursePending.length">
                <td colspan="5" class="px-1 py-6 text-center text-muted">Nothing pending — every course is fully evaluated.</td>
              </tr>
              <tr v-for="(row, i) in coursePending" :key="row.name" class="border-t border-gray-100">
                <td class="px-1 py-1.5 text-gray-500">{{ i + 1 }}</td>
                <td :title="row.code" class="px-1 py-1.5 font-medium text-gray-800 whitespace-nowrap cursor-default">{{ row.name }}</td>
                <td class="px-1 py-1.5 text-center text-gray-700">{{ row.pending }}</td>
                <td class="px-1 py-1.5 text-center text-gray-700">{{ row.total }}</td>
                <td class="px-1 py-1.5 text-center">
                  <span class="inline-flex min-w-[38px] justify-center rounded-full bg-rose-50 text-brand font-semibold px-1.5 py-0.5">{{ row.pendingPct }}%</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <DepartmentProgressModal v-if="showDepartmentsModal" @close="showDepartmentsModal = false" />
    <TeacherWorkloadModal v-if="showTeacherWorkloadModal" @close="showTeacherWorkloadModal = false" />
    <CoursePendingModal v-if="showCoursePendingModal" @close="showCoursePendingModal = false" />
  </div>
</template>
