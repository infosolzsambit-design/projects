<script setup>
// HOD dashboard (designed_files/hod_dashboard.png) — GET /dashboard/hod-summary
// (DashboardController::hodSummary()): only the HOD's own department(s)
// (their teacher-department mapping), scoped to the header's Exam Year /
// Examination like the other dashboards; refetched when either changes.
// The "My …" cards are the HOD's own evaluation work as a teacher.
import { computed, onMounted, ref, watch } from 'vue'
import api from '../../utils/api'
import { useExamYearStore } from '../../stores/examYear'
import { useExamTypeStore } from '../../stores/examType'
import SearchableSelect from '../common/SearchableSelect.vue'
import StageColumnChart from './StageColumnChart.vue'

const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const EMPTY = {
  departments: [],
  kpis: {},
  progress: [],
  status: { total: 0, evaluated: 0, pending_evaluation: 0, problem: 0, not_assigned: 0, in_pool: 0 },
  course_pending: [],
  course_pending_total: 0,
  teacher_progress: { teachers: 0, assigned: 0, not_started: 0, under_half: 0, over_half: 0, completed: 0 },
  teacher_allocation: { with_sheets: 0, total_teachers: 0, avg: 0, min: 0, max: 0, buckets: [] },
  issues: { raised: 0, under_review: 0, resolved: 0, trend: [] },
  courses: [],
}
const summary = ref(EMPTY)
const loading = ref(true)
const loadError = ref('')

async function fetchSummary() {
  loading.value = true
  loadError.value = ''
  try {
    const params = { exam_year: examYearStore.selectedYear }
    if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
    const res = await api.get('/dashboard/hod-summary', { params })
    summary.value = { ...EMPTY, ...res.data.data }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load the dashboard.'
  } finally {
    loading.value = false
  }
}
onMounted(fetchSummary)
watch(() => [examYearStore.selectedYear, examTypeStore.selectedId], fetchSummary)

const departmentLabel = computed(() => summary.value.departments.map((d) => (d.code ? `${d.name} (${d.code})` : d.name)).join(', '))

// Series colours — one per entity, the same in every chart on this page and
// the same as the admin / teacher dashboards' charts.
const C = {
  assigned: '#2f56c0',
  evaluated: '#22c55e',
  pending: '#f9a228',
  problem: '#f43f5e',
  resolved: '#8b5cf6',
  notAssigned: '#2f56c0',
  inPool: '#06b6d4',
}

function fmt(n) {
  return (n ?? 0).toLocaleString()
}
function pct(n, total) {
  return total ? `${((n / total) * 100).toFixed(1)}%` : '0.0%'
}
// Seconds → "45 sec" under a minute, otherwise whole minutes ("18 min").
function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  return seconds < 60 ? `${seconds} sec` : `${Math.round(seconds / 60)} min`
}
const maxOf = (values) => Math.max(1, ...values)

// A "nice" axis: 4 steps of 1/2/5×10ⁿ covering the largest value.
function niceAxis(maxValue) {
  const raw = Math.max(maxValue, 1) / 4
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const step = Math.max(1, [1, 2, 5, 10].map((m) => m * magnitude).find((v) => v >= raw))
  const top = Math.max(step, Math.ceil(maxValue / step) * step)
  const ticks = []
  for (let t = 0; t <= top; t += step) ticks.push(t)
  return { max: top, ticks }
}

// ---------------------------------------------------------------------------
// KPI cards — department, then my own work as a teacher.
// ---------------------------------------------------------------------------
const kpiRows = computed(() => {
  const k = summary.value.kpis
  return [
    [
      { label: 'Total Teachers', value: fmt(k.total_teachers), icon: 'users', tint: 'bg-blue-100', badge: 'bg-blue-500 text-white' },
      { label: 'Assigned Teachers', value: fmt(k.assigned_teachers), icon: 'usersCheck', tint: 'bg-emerald-100', badge: 'bg-emerald-500 text-white' },
      { label: 'Department Courses', value: fmt(k.courses), icon: 'book', tint: 'bg-violet-100', badge: 'bg-violet-500 text-white' },
      { label: 'Total Answer Sheets', value: fmt(k.total_sheets), icon: 'doc', tint: 'bg-orange-100', badge: 'bg-orange-500 text-white' },
    ],
    [
      { label: 'Evaluated Sheets', value: fmt(k.evaluated), icon: 'check', tint: 'bg-cyan-100', badge: 'bg-cyan-500 text-white' },
      { label: 'Pending Evaluations', value: fmt(k.pending), icon: 'clock', tint: 'bg-rose-100', badge: 'bg-rose-500 text-white' },
      { label: 'Problem Cases', value: fmt(k.problem), icon: 'warn', tint: 'bg-pink-100', badge: 'bg-pink-500 text-white' },
      { label: 'Resolved Issues', value: fmt(k.resolved), icon: 'gear', tint: 'bg-green-100', badge: 'bg-green-500 text-white' },
    ],
    [
      { label: 'My Assigned Sheets', value: fmt(k.my_assigned), icon: 'user', tint: 'bg-purple-100', badge: 'bg-purple-500 text-white' },
      { label: 'My Completed Evaluations', value: fmt(k.my_completed), icon: 'check', tint: 'bg-sky-100', badge: 'bg-sky-500 text-white' },
      { label: 'My Pending Subjects', value: fmt(k.my_pending_subjects), icon: 'clock', tint: 'bg-amber-100', badge: 'bg-amber-500 text-white' },
      { label: 'Average Review Time', value: formatDuration(k.my_avg_review_seconds), icon: 'bars', tint: 'bg-indigo-100', badge: 'bg-indigo-500 text-white' },
    ],
  ]
})

// ---------------------------------------------------------------------------
// Department Evaluation Progress — line chart, assigned vs evaluated per day.
// ---------------------------------------------------------------------------
const progressDays = computed(() => summary.value.progress)
const progressSeries = [
  { key: 'assigned', label: 'Assigned Sheets', color: C.assigned },
  { key: 'evaluated', label: 'Evaluated Sheets', color: C.evaluated },
]
const LINE = computed(() => ({ w: 560, h: 190, left: 36, right: 26, top: 12, bottom: 30, ...niceAxis(maxOf(progressDays.value.flatMap((d) => [d.assigned, d.evaluated]))) }))
const lineX = (i) => LINE.value.left + (i * (LINE.value.w - LINE.value.left - LINE.value.right)) / Math.max(progressDays.value.length - 1, 1)
const lineY = (v) => LINE.value.top + (1 - v / LINE.value.max) * (LINE.value.h - LINE.value.top - LINE.value.bottom)
const linePath = (key) => progressDays.value.map((d, i) => `${i ? 'L' : 'M'}${lineX(i).toFixed(1)},${lineY(d[key]).toFixed(1)}`).join(' ')
const areaPath = (key) => (progressDays.value.length ? `${linePath(key)} L${lineX(progressDays.value.length - 1)},${LINE.value.h - LINE.value.bottom} L${lineX(0)},${LINE.value.h - LINE.value.bottom} Z` : '')
const hoveredDay = ref(null)

// ---------------------------------------------------------------------------
// Department Evaluation Status — donut. Disjoint buckets that add up to the
// total (DashboardController::evaluationStatus(), same as the admin donut).
// ---------------------------------------------------------------------------
const statusTotal = computed(() => summary.value.status.total)
const statusSegments = computed(() => {
  const s = summary.value.status
  return [
    { label: 'Evaluated', value: s.evaluated, color: C.evaluated },
    { label: 'Pending', value: s.pending_evaluation, color: C.pending },
    { label: 'Problem Cases', value: s.problem, color: C.problem },
    { label: 'Not Assigned', value: s.not_assigned, color: C.notAssigned },
    // Shared-pool sheets nobody has started yet — only listed once there are any.
    ...(s.in_pool > 0 ? [{ label: 'In Pool', value: s.in_pool, color: C.inPool }] : []),
  ]
})
const resolvedIssues = computed(() => summary.value.kpis.resolved ?? 0)
const donutRadius = 70
const donutCircumference = 2 * Math.PI * donutRadius
const donutArcs = computed(() => {
  const total = statusSegments.value.reduce((n, s) => n + s.value, 0)
  if (!total) return []
  const nonEmpty = statusSegments.value.filter((s) => s.value > 0)
  let offset = 0
  return nonEmpty.map((s) => {
    const length = (s.value / total) * donutCircumference
    // 2px surface gap between slices (none when there's only one).
    const drawn = nonEmpty.length > 1 ? Math.max(length - 2, 0) : length
    const arc = { ...s, dasharray: `${drawn} ${donutCircumference - drawn}`, dashoffset: -offset }
    offset += length
    return arc
  })
})

// ---------------------------------------------------------------------------
// Course Wise Pending Evaluation — horizontal bars (top 5).
// ---------------------------------------------------------------------------
const coursePending = computed(() => summary.value.course_pending)
const coursePendingMax = computed(() => maxOf(coursePending.value.map((c) => c.pending)))

// ---------------------------------------------------------------------------
// Teacher Progress — no names (a department can have many teachers): how
// many teachers are at each stage, by the share of their sheets evaluated
// (DashboardController::teacherProgress(), same as the admin dashboard).
// ---------------------------------------------------------------------------
const teacherProgress = computed(() => summary.value.teacher_progress)
const progressStages = computed(() => [
  { key: 'not_started', label: 'Not Started', hint: 'not evaluated any sheet yet' },
  { key: 'under_half', label: 'Under 50%', hint: 'evaluated less than half of their sheets' },
  { key: 'over_half', label: '50% or More', hint: 'evaluated half or more, not all' },
  { key: 'completed', label: 'Completed', hint: 'evaluated all of their sheets' },
].map((s) => ({ ...s, value: teacherProgress.value[s.key] ?? 0 })))

// ---------------------------------------------------------------------------
// Issue Summary — tiles with a 7-day sparkline, and the daily trend.
// Each day's column = issues raised that day, split by where they are now.
// ---------------------------------------------------------------------------
const issueDays = computed(() => summary.value.issues.trend.map((d) => ({ label: d.label, raised: d.raised, underReview: d.under_review, resolved: d.resolved })))
const issueTiles = computed(() => [
  { label: 'Problem Raised', value: summary.value.issues.raised, icon: 'warn', tint: 'bg-rose-100', badge: 'bg-rose-500', color: C.problem, spark: issueDays.value.map((d) => d.raised) },
  { label: 'Under Review', value: summary.value.issues.under_review, icon: 'clock', tint: 'bg-amber-100', badge: 'bg-amber-500', color: C.pending, spark: issueDays.value.map((d) => d.underReview) },
  { label: 'Resolved', value: summary.value.issues.resolved, icon: 'check', tint: 'bg-emerald-100', badge: 'bg-emerald-500', color: C.evaluated, spark: issueDays.value.map((d) => d.resolved) },
])
function sparkPoints(values) {
  if (values.length < 2) return ''
  const max = maxOf(values)
  return values.map((v, i) => `${(i * 100) / (values.length - 1)},${28 - (v / max) * 24}`).join(' ')
}
const issueSeries = [
  { key: 'resolved', label: 'Resolved', color: C.evaluated },
  { key: 'underReview', label: 'Under Review', color: C.pending },
]
const ISSUE = computed(() => ({ w: 440, h: 130, left: 26, right: 6, top: 14, bottom: 24, ...niceAxis(maxOf(issueDays.value.map((d) => d.raised))) }))
const issueSlot = computed(() => (ISSUE.value.w - ISSUE.value.left - ISSUE.value.right) / Math.max(issueDays.value.length, 1))
const issueBarW = computed(() => issueSlot.value * 0.5)
const issueY = (v) => ISSUE.value.top + (1 - v / ISSUE.value.max) * (ISSUE.value.h - ISSUE.value.top - ISSUE.value.bottom)
const issueBars = computed(() => issueDays.value.map((d, i) => {
  const x = ISSUE.value.left + i * issueSlot.value + (issueSlot.value - issueBarW.value) / 2
  let base = 0
  const segments = issueSeries.filter((s) => d[s.key] > 0).map((s) => {
    const y = issueY(base + d[s.key])
    // 2px surface gap between stacked segments.
    const seg = { ...s, y, height: Math.max(issueY(base) - y - (base ? 2 : 0), 1) }
    base += d[s.key]
    return seg
  })
  return { ...d, x, total: d.raised, segments }
}))
const hoveredIssueDay = ref(null)

// ---------------------------------------------------------------------------
// Teacher Allocation Overview — how the department's sheets are spread over
// its teachers (DashboardController::teacherAllocation()), no names.
// ---------------------------------------------------------------------------
const allocation = computed(() => summary.value.teacher_allocation)
const allocationPct = computed(() => (allocation.value.total_teachers ? Math.round((allocation.value.with_sheets / allocation.value.total_teachers) * 100) : 0))
const loadStages = computed(() => allocation.value.buckets.map((b) => ({ key: b.label, label: b.label, hint: `have ${b.label} sheets`, value: b.count })))

// ---------------------------------------------------------------------------
// Program / Course Allocation Insights — one tile per program + course.
// evaluated + pending + problem = sheets.
// ---------------------------------------------------------------------------
const TILE_STYLES = [
  { icon: 'leaf', tint: 'bg-violet-100', badge: 'bg-violet-500 text-white' },
  { icon: 'calc', tint: 'bg-blue-100', badge: 'bg-blue-500 text-white' },
  { icon: 'bars', tint: 'bg-amber-100', badge: 'bg-amber-500 text-white' },
  { icon: 'bank', tint: 'bg-rose-100', badge: 'bg-rose-500 text-white' },
  { icon: 'doc', tint: 'bg-emerald-100', badge: 'bg-emerald-500 text-white' },
]
const programFilter = ref('')
const programOptions = computed(() => [...new Set(summary.value.courses.map((c) => c.program).filter(Boolean))].sort().map((p) => ({ id: p, name: p })))
const courseTiles = computed(() => summary.value.courses.map((c, i) => ({
  ...TILE_STYLES[i % TILE_STYLES.length],
  key: `${c.program}|${c.course}`,
  name: c.course,
  program: c.program,
  sheets: c.sheets,
  evaluated: c.evaluated,
  pending: c.pending,
  issues: c.problem,
})))
const filteredCourseTiles = computed(() => courseTiles.value.filter((c) => !programFilter.value || c.program === programFilter.value))
// A new summary (other exam year / examination) may not have the picked program.
watch(summary, () => {
  if (programFilter.value && !programOptions.value.some((p) => p.id === programFilter.value)) programFilter.value = ''
})
</script>

<template>
  <p v-if="loading && !summary.progress.length" class="text-center text-sm text-muted py-10">Loading the dashboard&hellip;</p>
  <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>
  <div v-else-if="!summary.departments.length" class="bg-white rounded-2xl shadow-panel p-8 text-center">
    <p class="font-semibold text-gray-900">You are not mapped to any department yet.</p>
    <p class="text-[13px] text-muted mt-1">The HOD dashboard shows your department's data — ask an admin to add your department on the Teacher form.</p>
  </div>
  <div v-else class="flex flex-col gap-4">
    <!-- Whose data this is. -->
    <div class="flex items-center gap-2 text-[13px] text-gray-600 -mb-1">
      <svg class="w-4 h-4 text-brand-blue shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="2.5" /><path d="M12 7.5V12M4 20v-2a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v2M12 12l-5 3M12 12l5 3" /></svg>
      <span>Department: <span class="font-semibold text-gray-900">{{ departmentLabel }}</span></span>
    </div>

    <!-- KPI cards — department totals, then my own evaluation work. -->
    <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
      <div v-for="kpi in kpiRows.flat()" :key="kpi.label" class="rounded-2xl px-3.5 py-2.5 flex items-center gap-2.5" :class="kpi.tint">
        <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0" :class="kpi.badge">
          <svg v-if="kpi.icon === 'users'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
          <svg v-else-if="kpi.icon === 'usersCheck'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="8.5" cy="7" r="4" /><polyline points="17 11 19 13 23 9" /></svg>
          <svg v-else-if="kpi.icon === 'book'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
          <svg v-else-if="kpi.icon === 'doc'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>
          <svg v-else-if="kpi.icon === 'check'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
          <svg v-else-if="kpi.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
          <svg v-else-if="kpi.icon === 'warn'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          <svg v-else-if="kpi.icon === 'gear'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" /></svg>
          <svg v-else-if="kpi.icon === 'user'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
          <svg v-else-if="kpi.icon === 'bars'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
        </span>
        <div class="min-w-0">
          <p :title="kpi.label" class="text-[11.5px] font-medium text-gray-700 leading-tight truncate">{{ kpi.label }}</p>
          <p class="text-[18px] font-bold text-gray-900 leading-tight">{{ kpi.value }}</p>
        </div>
      </div>
    </section>

    <!-- Row: Department Evaluation Progress / Status / Course Wise Pending -->
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <!-- Department Evaluation Progress -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Department Evaluation Progress</h3>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <p class="text-[12px] text-muted">Assigned vs. evaluated sheets (Last 7 days)</p>
          <div class="flex items-center gap-4 text-[11px] text-gray-600">
            <span v-for="s in progressSeries" :key="s.key" class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" :style="{ background: s.color }"></span>{{ s.label }}</span>
          </div>
        </div>
        <div class="relative" @mouseleave="hoveredDay = null">
          <svg :viewBox="`0 0 ${LINE.w} ${LINE.h}`" class="w-full h-auto" role="img" aria-label="Assigned and evaluated sheets over the last 7 days">
            <defs>
              <linearGradient id="hodAssignedFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="C.assigned" stop-opacity="0.16" />
                <stop offset="100%" :stop-color="C.assigned" stop-opacity="0" />
              </linearGradient>
            </defs>
            <g v-for="t in LINE.ticks" :key="t">
              <line :x1="LINE.left" :x2="LINE.w - LINE.right" :y1="lineY(t)" :y2="lineY(t)" stroke="#f3f4f6" />
              <text :x="LINE.left - 8" :y="lineY(t) + 4" text-anchor="end" font-size="12" fill="#9ca3af">{{ t }}</text>
            </g>
            <path :d="areaPath('assigned')" fill="url(#hodAssignedFill)" />
            <line v-if="hoveredDay !== null" :x1="lineX(hoveredDay)" :x2="lineX(hoveredDay)" :y1="LINE.top" :y2="LINE.h - LINE.bottom" stroke="#d1d5db" stroke-dasharray="3 3" />
            <path v-for="s in progressSeries" :key="s.key" :d="linePath(s.key)" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" />
            <template v-for="s in progressSeries" :key="'d' + s.key">
              <circle v-for="(d, i) in progressDays" :key="i" :cx="lineX(i)" :cy="lineY(d[s.key])" :r="hoveredDay === i ? 5 : 4" :fill="s.color" stroke="#fff" stroke-width="2" />
            </template>
            <text v-for="(d, i) in progressDays" :key="'x' + i" :x="lineX(i)" :y="LINE.h - 8" text-anchor="middle" font-size="12" fill="#9ca3af">{{ d.label }}</text>
            <!-- Hover targets — one full-height column per day. -->
            <rect
              v-for="(d, i) in progressDays"
              :key="'h' + i"
              :x="lineX(i) - (LINE.w - LINE.left - LINE.right) / 12"
              :y="0"
              :width="(LINE.w - LINE.left - LINE.right) / 6"
              :height="LINE.h"
              fill="transparent"
              @mouseenter="hoveredDay = i"
              @click="hoveredDay = i"
            />
          </svg>
          <div
            v-if="hoveredDay !== null"
            class="absolute z-20 top-0 w-40 rounded-lg bg-white border border-gray-200 shadow-lg px-3 py-2 text-[12px] pointer-events-none"
            :style="hoveredDay > 3 ? { right: `${100 - (lineX(hoveredDay) / LINE.w) * 100 + 2}%` } : { left: `${(lineX(hoveredDay) / LINE.w) * 100 + 2}%` }"
          >
            <p class="font-semibold text-gray-900 mb-0.5">{{ progressDays[hoveredDay].label }}</p>
            <div v-for="s in progressSeries" :key="s.key" class="flex items-center justify-between gap-2 text-gray-700">
              <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: s.color }"></span>{{ s.label.replace(' Sheets', '') }}</span>
              <span class="font-semibold text-gray-900">{{ progressDays[hoveredDay][s.key] }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Department Evaluation Status -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83" /><path d="M22 12A10 10 0 0 0 12 2v10z" /></svg>
          <h3 class="font-semibold text-gray-900">Department Evaluation Status</h3>
        </div>
        <p class="text-[12px] text-muted mb-2">Distribution of allocated answer sheets</p>
        <div class="flex items-center gap-5">
          <svg viewBox="0 0 200 200" class="w-32 h-32 shrink-0">
            <circle v-if="!donutArcs.length" cx="100" cy="100" :r="donutRadius" fill="none" stroke="#f3f4f6" stroke-width="26" />
            <g transform="rotate(-90 100 100)">
              <circle v-for="arc in donutArcs" :key="arc.label" cx="100" cy="100" :r="donutRadius" fill="none" :stroke="arc.color" stroke-width="26" :stroke-dasharray="arc.dasharray" :stroke-dashoffset="arc.dashoffset">
                <title>{{ arc.label }}: {{ arc.value }} ({{ pct(arc.value, statusTotal) }})</title>
              </circle>
            </g>
            <text x="100" y="98" text-anchor="middle" class="fill-gray-900" font-size="28" font-weight="700">{{ statusTotal }}</text>
            <text x="100" y="120" text-anchor="middle" class="fill-gray-400" font-size="12">Total Sheets</text>
          </svg>
          <div class="flex flex-col gap-1.5 min-w-0 flex-1">
            <div v-for="s in statusSegments" :key="s.label" class="flex items-center gap-2 text-[12px]">
              <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: s.color }"></span>
              <span class="text-gray-600 truncate">{{ s.label }}</span>
              <span class="ml-auto font-bold text-gray-900 whitespace-nowrap">{{ fmt(s.value) }} <span class="font-medium text-muted">({{ pct(s.value, statusTotal) }})</span></span>
            </div>
            <!-- Resolved issues are already inside the buckets above (a
                 resolved sheet is evaluated or pending again), so not a slice. -->
            <div class="flex items-center gap-2 text-[12px] border-t border-gray-100 pt-1.5">
              <svg class="w-2.5 h-2.5 shrink-0" viewBox="0 0 24 24" fill="none" :stroke="C.resolved" stroke-width="4"><polyline points="20 6 9 17 4 12" /></svg>
              <span class="text-gray-600 truncate">Resolved Issues</span>
              <span class="ml-auto font-bold text-gray-900">{{ fmt(resolvedIssues) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Course Wise Pending Evaluation -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
          <h3 class="font-semibold text-gray-900">Course Wise Pending Evaluation</h3>
        </div>
        <p class="text-[12px] text-muted mb-3">
          Pending sheets by course<template v-if="summary.course_pending_total > coursePending.length"> — top {{ coursePending.length }} of {{ summary.course_pending_total }}</template>
        </p>
        <p v-if="!coursePending.length" class="text-[12px] text-muted text-center py-6">Nothing pending — every sheet is evaluated.</p>
        <div v-else class="flex flex-col gap-2.5">
          <div v-for="c in coursePending" :key="c.name">
            <div class="flex items-center justify-between gap-3 text-[12.5px] mb-1.5">
              <span :title="c.name" class="font-medium text-gray-700 truncate">{{ c.name }}</span>
              <span class="font-bold text-gray-900">{{ c.pending }}</span>
            </div>
            <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full rounded-full" :style="{ width: Math.max(4, (c.pending / coursePendingMax) * 100) + '%', background: C.pending }"></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Row: Teacher Progress / Teacher Allocation Overview / Issue Summary -->
    <section class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
      <!-- Teacher Progress — teachers by share of their sheets evaluated -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
          <h3 class="font-semibold text-gray-900">Teacher Progress</h3>
        </div>
        <p v-if="!teacherProgress.teachers" class="text-[12px] text-muted text-center py-6">No department sheets are assigned to a teacher yet.</p>
        <template v-else>
          <p class="text-[12px] text-muted mb-3">
            <span class="font-semibold text-gray-900">{{ fmt(teacherProgress.teachers) }}</span> teacher{{ teacherProgress.teachers === 1 ? '' : 's' }}
            · <span class="font-semibold text-gray-900">{{ fmt(teacherProgress.assigned) }}</span> sheets assigned
          </p>
          <StageColumnChart :stages="progressStages" unit="teacher" caption="Teachers by share of their sheets evaluated" />
        </template>
      </div>

      <!-- Teacher Allocation Overview — how the sheets are spread -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Teacher Allocation Overview</h3>
        </div>
        <p class="text-[12px] text-muted mb-3">How the department's sheets are spread over its teachers</p>
        <div class="grid grid-cols-3 gap-2 mb-3">
          <div class="rounded-xl bg-blue-50 px-2.5 py-2">
            <p class="text-[11px] text-gray-600 leading-tight">Teachers with sheets</p>
            <p class="text-[16px] font-bold text-gray-900 leading-tight mt-0.5">{{ fmt(allocation.with_sheets) }}<span class="text-[12px] font-medium text-muted"> / {{ fmt(allocation.total_teachers) }}</span></p>
            <div class="h-1.5 rounded-full bg-blue-100 overflow-hidden mt-1">
              <div class="h-full rounded-full bg-brand-blue" :style="{ width: allocationPct + '%' }"></div>
            </div>
          </div>
          <div class="rounded-xl bg-emerald-50 px-2.5 py-2">
            <p class="text-[11px] text-gray-600 leading-tight">Avg sheets / teacher</p>
            <p class="text-[16px] font-bold text-gray-900 leading-tight mt-0.5">{{ fmt(allocation.avg) }}</p>
          </div>
          <div class="rounded-xl bg-amber-50 px-2.5 py-2">
            <p class="text-[11px] text-gray-600 leading-tight">Lowest – highest</p>
            <p class="text-[16px] font-bold text-gray-900 leading-tight mt-0.5">{{ fmt(allocation.min) }} – {{ fmt(allocation.max) }}</p>
          </div>
        </div>
        <p v-if="!allocation.with_sheets" class="text-[12px] text-muted text-center py-6">No department sheets are assigned to a teacher yet.</p>
        <StageColumnChart v-else :stages="loadStages" unit="teacher" caption="Teachers by number of sheets assigned" />
      </div>

      <!-- Issue Summary -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          <h3 class="font-semibold text-gray-900">Issue Summary</h3>
        </div>
        <p class="text-[12px] text-muted mb-2">Current status of evaluation related issues</p>
        <div class="grid grid-cols-3 gap-2.5">
          <div v-for="tile in issueTiles" :key="tile.label" class="rounded-xl pt-2 pb-1 px-2 text-center" :class="tile.tint">
            <span class="w-7 h-7 rounded-full mx-auto flex items-center justify-center mb-1 text-white" :class="tile.badge">
              <svg v-if="tile.icon === 'warn'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
              <svg v-else-if="tile.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
              <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
            </span>
            <p class="text-[18px] font-bold text-gray-900 leading-tight">{{ tile.value }}</p>
            <p class="text-[11px] font-medium text-gray-600 leading-tight">{{ tile.label }}</p>
            <!-- 7-day sparkline -->
            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="w-full h-5 mt-0.5" aria-hidden="true">
              <polyline :points="sparkPoints(tile.spark)" fill="none" :stroke="tile.color" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </svg>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
          <p class="text-[12.5px] font-semibold text-gray-800">Issue Trend <span class="font-normal text-muted">(Last 7 days)</span></p>
          <div class="flex items-center gap-3 text-[11px] text-gray-600">
            <span v-for="s in issueSeries" :key="s.key" class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" :style="{ background: s.color }"></span>{{ s.label }}</span>
          </div>
        </div>
        <p class="text-[11px] text-muted mb-1">Issues raised each day, by current status</p>
        <div class="relative" @mouseleave="hoveredIssueDay = null">
          <svg :viewBox="`0 0 ${ISSUE.w} ${ISSUE.h}`" class="w-full h-auto" role="img" aria-label="Issues raised per day over the last 7 days">
            <g v-for="t in ISSUE.ticks" :key="t">
              <line :x1="ISSUE.left" :x2="ISSUE.w - ISSUE.right" :y1="issueY(t)" :y2="issueY(t)" stroke="#f3f4f6" />
              <text :x="ISSUE.left - 6" :y="issueY(t) + 4" text-anchor="end" font-size="11" fill="#9ca3af">{{ t }}</text>
            </g>
            <g v-for="(b, i) in issueBars" :key="b.label" @mouseenter="hoveredIssueDay = i" @click="hoveredIssueDay = i">
              <rect :x="b.x - issueSlot * 0.25" :y="0" :width="issueSlot" :height="ISSUE.h" fill="transparent" />
              <rect v-for="seg in b.segments" :key="seg.key" :x="b.x" :y="seg.y" :width="issueBarW" :height="seg.height" rx="3" :fill="seg.color" :opacity="hoveredIssueDay !== null && hoveredIssueDay !== i ? 0.6 : 1" />
              <text :x="b.x + issueBarW / 2" :y="ISSUE.h - 7" text-anchor="middle" font-size="11" fill="#9ca3af">{{ b.label }}</text>
            </g>
          </svg>
          <div
            v-if="hoveredIssueDay !== null"
            class="absolute z-20 top-0 w-40 rounded-lg bg-white border border-gray-200 shadow-lg px-3 py-2 text-[12px] pointer-events-none"
            :style="hoveredIssueDay > 3 ? { right: `${100 - (issueBars[hoveredIssueDay].x / ISSUE.w) * 100 + 2}%` } : { left: `${((issueBars[hoveredIssueDay].x + issueBarW) / ISSUE.w) * 100 + 2}%` }"
          >
            <p class="font-semibold text-gray-900 mb-0.5">{{ issueBars[hoveredIssueDay].label }} — {{ issueBars[hoveredIssueDay].total }} raised</p>
            <div v-for="s in issueSeries" :key="s.key" class="flex items-center justify-between gap-2 text-gray-700">
              <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm" :style="{ background: s.color }"></span>{{ s.label }}</span>
              <span class="font-semibold text-gray-900">{{ issueBars[hoveredIssueDay][s.key] }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Row: Program / Course Allocation Insights -->
    <section>
      <!-- Program / Course Allocation Insights -->
      <div class="bg-white rounded-2xl shadow-panel p-4">
        <div class="flex items-start gap-2 mb-3">
          <svg class="w-5 h-5 text-brand-blue mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
          <div class="min-w-0">
            <h3 class="font-semibold text-gray-900">Program / Course Allocation Insights</h3>
            <p class="text-[12px] text-muted">Allocated sheets across programs and courses</p>
          </div>
          <div class="ml-auto w-40 shrink-0">
            <SearchableSelect v-model="programFilter" dense :options="programOptions" placeholder="All Programs" />
          </div>
        </div>
        <p v-if="!filteredCourseTiles.length" class="text-[12px] text-muted text-center py-6">{{ courseTiles.length ? 'No courses in this program.' : 'No department answer sheets yet.' }}</p>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-2.5 max-h-[420px] overflow-y-auto pr-0.5">
          <div v-for="c in filteredCourseTiles" :key="c.key" class="rounded-xl px-3 py-2.5" :class="c.tint">
            <div class="flex items-start gap-2 mb-2">
              <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" :class="c.badge">
                <svg v-if="c.icon === 'leaf'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" /><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" /></svg>
                <svg v-else-if="c.icon === 'calc'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2" /><line x1="8" y1="6" x2="16" y2="6" /><line x1="8" y1="11" x2="8.01" y2="11" /><line x1="12" y1="11" x2="12.01" y2="11" /><line x1="16" y1="11" x2="16.01" y2="11" /><line x1="8" y1="15" x2="8.01" y2="15" /><line x1="12" y1="15" x2="12.01" y2="15" /><line x1="16" y1="15" x2="16.01" y2="15" /></svg>
                <svg v-else-if="c.icon === 'bars'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
                <svg v-else-if="c.icon === 'bank'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M4 21V9l8-5 8 5v12M9 21v-6h6v6" /></svg>
                <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>
              </span>
              <div class="min-w-0">
                <p :title="c.name" class="text-[12.5px] font-semibold text-gray-900 leading-tight line-clamp-2">{{ c.name }}</p>
                <p v-if="c.program" :title="c.program" class="text-[10.5px] text-gray-500 truncate">{{ c.program }}</p>
              </div>
            </div>
            <div class="flex items-end justify-between gap-2">
              <div>
                <p class="text-[20px] font-bold text-gray-900 leading-none">{{ c.sheets }}</p>
                <p class="text-[11px] text-muted mt-0.5">Sheets</p>
              </div>
              <div class="flex flex-col gap-0.5 text-[11px] text-gray-600">
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: C.evaluated }"></span><span class="font-semibold text-gray-900 w-4 text-right">{{ c.evaluated }}</span>Evaluated</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: C.pending }"></span><span class="font-semibold text-gray-900 w-4 text-right">{{ c.pending }}</span>Pending</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: C.problem }"></span><span class="font-semibold text-gray-900 w-4 text-right">{{ c.issues }}</span>Issues</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
