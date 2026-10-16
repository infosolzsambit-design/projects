<script setup>
// Teacher dashboard (designed_files/teacher_dashboard.png) — real data from
// GET /dashboard/teacher-summary (DashboardController::teacherSummary()):
// only the logged-in teacher's own answer sheets, scoped to the header's
// Exam Year / Examination like My Pending Course. Refetched whenever either
// header picker changes.
import { computed, onMounted, ref, watch } from 'vue'
import api from '../../utils/api'
import { useExamYearStore } from '../../stores/examYear'
import { useExamTypeStore } from '../../stores/examType'
import TeacherCoursePendingModal from './TeacherCoursePendingModal.vue'
import TeacherRecentActivityModal from './TeacherRecentActivityModal.vue'

const examYearStore = useExamYearStore()
const examTypeStore = useExamTypeStore()

const EMPTY = {
  kpis: { assigned: 0, evaluated: 0, pending: 0, problem: 0, unsolved: 0, resolved: 0, pending_courses: 0, avg_consume_seconds: null, max_consume_seconds: null, min_consume_seconds: null, avg_per_day: 0 },
  daily: [],
  status: { completed: 0, pending: 0, problem: 0 },
  course_pending: [],
  recent: [],
  week: [],
}
const summary = ref(EMPTY)
const loading = ref(true)
const loadError = ref('')

// Same scoping params as My Pending Course (the server enforces the scope
// for non-super-admins anyway).
function scopeParams() {
  const params = { exam_year: examYearStore.selectedYear }
  if (examTypeStore.selectedId !== null) params.exam_type_id = examTypeStore.selectedId
  return params
}

async function fetchSummary() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/dashboard/teacher-summary', { params: scopeParams() })
    summary.value = { ...EMPTY, ...res.data.data }
    // The week chart starts on the current week each time the summary loads.
    week.value = { start: summary.value.week_start, label: summary.value.week_label, isCurrent: true, days: summary.value.week }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load your dashboard.'
  } finally {
    loading.value = false
  }
}
onMounted(fetchSummary)
watch(() => [examYearStore.selectedYear, examTypeStore.selectedId], fetchSummary)

// --- Week picker for the weekly performance chart --------------------------
// ‹ / › move a week back / forward (never past the current week); only this
// chart reloads (GET /dashboard/teacher-week).
const week = ref({ start: null, label: '', isCurrent: true, days: [] })
const weekLoading = ref(false)
function shiftDate(isoDate, days) {
  const [y, m, d] = isoDate.split('-').map(Number)
  const date = new Date(Date.UTC(y, m - 1, d + days))
  return date.toISOString().slice(0, 10)
}
async function changeWeek(direction) {
  if (!week.value.start || weekLoading.value || (direction > 0 && week.value.isCurrent)) return
  weekLoading.value = true
  try {
    const res = await api.get('/dashboard/teacher-week', {
      params: { ...scopeParams(), week_start: shiftDate(week.value.start, direction * 7) },
      skipLoader: true,
    })
    const d = res.data.data
    week.value = { start: d.week_start, label: d.week_label, isCurrent: d.is_current, days: d.days }
  } catch {
    // Keep showing the week already on screen.
  } finally {
    weekLoading.value = false
  }
}

const kpis = computed(() => summary.value.kpis)
const daily = computed(() => summary.value.daily)
const recent = computed(() => summary.value.recent)

// "See All" modals — the two list cards show the first few rows only.
const showCoursePendingModal = ref(false)
const showRecentModal = ref(false)

// Seconds → "45 sec" under a minute, otherwise whole minutes ("18 min").
function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  return seconds < 60 ? `${seconds} sec` : `${Math.round(seconds / 60)} min`
}

// A "nice" axis for a chart: 4 steps of 1/2/5×10ⁿ covering the largest value.
function niceAxis(maxValue) {
  const raw = Math.max(maxValue, 1) / 4
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  // Counts are whole numbers — never a fractional step (no "0.5" on an empty week).
  const step = Math.max(1, [1, 2, 5, 10].map((m) => m * magnitude).find((v) => v >= raw))
  const top = Math.max(step, Math.ceil(maxValue / step) * step)
  const ticks = []
  for (let t = 0; t <= top + 1e-9; t += step) ticks.push(Math.round(t * 100) / 100)
  return { max: top, ticks }
}

// ---------------------------------------------------------------------------
// KPI cards — six on the first row, five on the second.
// ---------------------------------------------------------------------------
const kpiRow1 = computed(() => [
  { value: kpis.value.assigned, label: 'Assigned Sheets', icon: 'send', tint: 'bg-emerald-100', badge: 'bg-emerald-500' },
  { value: kpis.value.evaluated, label: 'Total Sheets Evaluated', icon: 'check', tint: 'bg-sky-100', badge: 'bg-sky-500' },
  // Opens My Pending Course.
  { value: kpis.value.pending, label: 'Pending Evaluations', icon: 'hourglass', tint: 'bg-amber-100', badge: 'bg-amber-500', to: { name: 'my-pending-courses' } },
  // The three problem cards open My Problem Course.
  { value: kpis.value.problem, label: 'Total Problem Raised', icon: 'warn', tint: 'bg-rose-100', badge: 'bg-rose-500', to: { name: 'my-problem-courses' } },
  { value: kpis.value.unsolved, label: 'Unsolved Problems', icon: 'alert', tint: 'bg-orange-100', badge: 'bg-orange-500', to: { name: 'my-problem-courses' } },
  { value: kpis.value.resolved, label: 'Resolved Issues', icon: 'checkCircle', tint: 'bg-violet-100', badge: 'bg-violet-500', to: { name: 'my-problem-courses' } },
])
const kpiRow2 = computed(() => [
  { value: kpis.value.pending_courses, label: 'Pending Courses', icon: 'book', tint: 'bg-blue-100', badge: 'bg-blue-500' },
  { value: formatDuration(kpis.value.avg_consume_seconds), label: 'Average Consume Time', icon: 'clock', tint: 'bg-emerald-100', badge: 'bg-emerald-500' },
  { value: formatDuration(kpis.value.max_consume_seconds), label: 'Max Consume Time', icon: 'clock', tint: 'bg-rose-100', badge: 'bg-rose-500' },
  { value: formatDuration(kpis.value.min_consume_seconds), label: 'Minimum Consume Time', icon: 'clock', tint: 'bg-sky-100', badge: 'bg-sky-500' },
  { value: kpis.value.avg_per_day, label: 'Average / Day', icon: 'bars', tint: 'bg-violet-100', badge: 'bg-violet-500' },
])

// ---------------------------------------------------------------------------
// Evaluation Progress — line chart (SVG), completed vs pending per day.
// ---------------------------------------------------------------------------
const LINE = computed(() => ({
  w: 560, h: 230, left: 36, right: 24, top: 12, bottom: 30,
  ...niceAxis(Math.max(0, ...daily.value.flatMap((d) => [d.completed, d.pending]))),
}))
const lineX = (i) => LINE.value.left + (i * (LINE.value.w - LINE.value.left - LINE.value.right)) / Math.max(daily.value.length - 1, 1)
const lineY = (v) => LINE.value.top + (1 - v / LINE.value.max) * (LINE.value.h - LINE.value.top - LINE.value.bottom)
const lineBase = computed(() => LINE.value.h - LINE.value.bottom)
function linePath(key) {
  return daily.value.map((d, i) => `${i ? 'L' : 'M'}${lineX(i).toFixed(1)},${lineY(d[key]).toFixed(1)}`).join(' ')
}
function areaPath(key) {
  if (!daily.value.length) return ''
  return `${linePath(key)} L${lineX(daily.value.length - 1).toFixed(1)},${lineBase.value} L${lineX(0).toFixed(1)},${lineBase.value} Z`
}
const completedLine = computed(() => linePath('completed'))
const pendingLine = computed(() => linePath('pending'))
const completedArea = computed(() => areaPath('completed'))
const pendingArea = computed(() => areaPath('pending'))

// ---------------------------------------------------------------------------
// Evaluation Status — donut. Disjoint buckets: completed + pending + problem
// (open issue) = assigned, so the ring and percentages add up to 100%.
// ---------------------------------------------------------------------------
const donutSegments = computed(() => {
  const s = summary.value.status
  const total = kpis.value.assigned || 1
  const pct = (n) => `${((n / total) * 100).toFixed(1)}%`
  return [
    { label: 'Completed', value: s.completed, pct: pct(s.completed), color: '#22c55e' },
    { label: 'Pending', value: s.pending, pct: pct(s.pending), color: '#f9a228' },
    { label: 'Problem Cases', value: s.problem, pct: pct(s.problem), color: '#f43f5e' },
  ]
})
const donutRadius = 70
const donutCircumference = 2 * Math.PI * donutRadius
const donutArcs = computed(() => {
  const segments = donutSegments.value
  const sum = segments.reduce((n, s) => n + s.value, 0)
  if (!sum) return []
  let offset = 0
  return segments.map((segment) => {
    const length = (segment.value / sum) * donutCircumference
    const arc = { ...segment, dasharray: `${length} ${donutCircumference - length}`, dashoffset: -offset }
    offset += length
    return arc
  })
})

// ---------------------------------------------------------------------------
// Course Wise Pending Evaluation — horizontal bars.
// ---------------------------------------------------------------------------
const COURSE_COLORS = ['bg-rose-500', 'bg-amber-400', 'bg-sky-500', 'bg-violet-500', 'bg-emerald-500', 'bg-blue-500']
const courseBars = computed(() => {
  const rows = summary.value.course_pending
  const max = Math.max(...rows.map((s) => s.pending), 1)
  return rows.map((s, i) => ({
    ...s,
    color: COURSE_COLORS[i % COURSE_COLORS.length],
    width: Math.max(4, Math.round((s.pending / max) * 100)),
  }))
})

// ---------------------------------------------------------------------------
// Issue Summary tiles.
// ---------------------------------------------------------------------------
const issueTiles = computed(() => [
  { value: kpis.value.problem, label: 'Problem Raised', icon: 'warnCircle', tint: 'bg-rose-100', text: 'text-brand' },
  { value: kpis.value.resolved, label: 'Resolved', icon: 'check', tint: 'bg-emerald-100', text: 'text-success' },
])

// ---------------------------------------------------------------------------
// This Week's Performance — vertical bars (SVG).
// ---------------------------------------------------------------------------
const BAR = computed(() => ({
  w: 430, h: 190, left: 28, right: 6, top: 18, bottom: 38,
  ...niceAxis(Math.max(0, ...week.value.days.map((d) => d.count))),
}))
const barSlot = computed(() => (BAR.value.w - BAR.value.left - BAR.value.right) / Math.max(week.value.days.length, 1))
const barWidth = computed(() => barSlot.value * 0.68)
const barY = (v) => BAR.value.top + (1 - v / BAR.value.max) * (BAR.value.h - BAR.value.top - BAR.value.bottom)
const weekBars = computed(() => week.value.days.map((d, i) => ({
  ...d,
  x: BAR.value.left + i * barSlot.value + (barSlot.value - barWidth.value) / 2,
  y: barY(d.count),
  height: BAR.value.h - BAR.value.bottom - barY(d.count),
})))
</script>

<template>
  <p v-if="loading && !summary.daily.length" class="text-center text-sm text-muted py-10">Loading your dashboard&hellip;</p>
  <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>
  <div v-else class="flex flex-col gap-4">
    <!-- KPI cards — six on the first row, five on the second, same card as
         the admin dashboard. The six-card row gives longer labels more width
         (like the admin dashboard's own rows) so every label fits on one line. -->
    <section v-for="(row, r) in [kpiRow1, kpiRow2]" :key="r" class="grid grid-cols-2 sm:grid-cols-3 gap-3" :class="row.length === 6 ? 'xl:grid-cols-[175fr_222fr_205fr_210fr_190fr_180fr]' : 'xl:grid-cols-5'">
      <!-- A card with `to` is a link (same hover lift as the admin dashboard's cards). -->
      <component
        :is="kpi.to ? 'RouterLink' : 'div'"
        v-for="kpi in row"
        :key="kpi.label"
        :to="kpi.to"
        class="rounded-2xl px-4 py-3.5 flex items-center gap-3"
        :class="[kpi.tint, kpi.to ? 'transition-transform hover:-translate-y-0.5' : '']"
      >
        <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 text-white" :class="kpi.badge">
          <svg v-if="kpi.icon === 'send'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg>
          <svg v-else-if="kpi.icon === 'check'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
          <svg v-else-if="kpi.icon === 'hourglass'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 2h14M5 22h14M5 2c0 6 6 6 6 10s-6 4-6 10M19 2c0 6-6 6-6 10s6 4 6 10" /></svg>
          <svg v-else-if="kpi.icon === 'warn'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
          <svg v-else-if="kpi.icon === 'alert'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
          <svg v-else-if="kpi.icon === 'checkCircle'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="8 12 11 15 16 9" /></svg>
          <svg v-else-if="kpi.icon === 'book'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
          <svg v-else-if="kpi.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
          <svg v-else-if="kpi.icon === 'bars'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
        </span>
        <div class="min-w-0">
          <p class="text-[12px] font-medium text-gray-700 leading-tight whitespace-nowrap overflow-hidden text-ellipsis">{{ kpi.label }}</p>
          <p class="text-[19px] font-bold text-gray-900 leading-tight">{{ kpi.value }}</p>
        </div>
      </component>
    </section>

    <!-- Row 3: Evaluation Progress / Evaluation Status / Course Wise Pending Evaluation -->
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <!-- Evaluation Progress -->
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Evaluation Progress</h3>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <p class="text-[12px] text-muted">Daily evaluation activity (Last 7 days)</p>
          <div class="flex items-center gap-4 text-[11px] text-gray-600">
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-brand-blue"></span>Completed</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>Pending</span>
          </div>
        </div>
        <svg :viewBox="`0 0 ${LINE.w} ${LINE.h}`" class="w-full h-auto" role="img" aria-label="Completed and pending evaluations over the last 7 days">
          <defs>
            <linearGradient id="tdCompletedFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#2f56c0" stop-opacity="0.18" />
              <stop offset="100%" stop-color="#2f56c0" stop-opacity="0" />
            </linearGradient>
            <linearGradient id="tdPendingFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#f9a228" stop-opacity="0.16" />
              <stop offset="100%" stop-color="#f9a228" stop-opacity="0" />
            </linearGradient>
          </defs>
          <g v-for="t in LINE.ticks" :key="t">
            <line :x1="LINE.left" :x2="LINE.w - LINE.right" :y1="lineY(t)" :y2="lineY(t)" stroke="#f3f4f6" />
            <text :x="LINE.left - 8" :y="lineY(t) + 4" text-anchor="end" font-size="12" fill="#9ca3af">{{ t }}</text>
          </g>
          <path :d="pendingArea" fill="url(#tdPendingFill)" />
          <path :d="completedArea" fill="url(#tdCompletedFill)" />
          <path :d="pendingLine" fill="none" stroke="#f9a228" stroke-width="2.2" stroke-linejoin="round" />
          <path :d="completedLine" fill="none" stroke="#2f56c0" stroke-width="2.2" stroke-linejoin="round" />
          <circle v-for="(d, i) in daily" :key="'p' + i" :cx="lineX(i)" :cy="lineY(d.pending)" r="3.5" fill="#f9a228" stroke="#fff" stroke-width="1.5">
            <title>{{ d.label }} — Pending: {{ d.pending }}</title>
          </circle>
          <circle v-for="(d, i) in daily" :key="'c' + i" :cx="lineX(i)" :cy="lineY(d.completed)" r="3.5" fill="#2f56c0" stroke="#fff" stroke-width="1.5">
            <title>{{ d.label }} — Completed: {{ d.completed }}</title>
          </circle>
          <text v-for="(d, i) in daily" :key="'x' + i" :x="lineX(i)" :y="LINE.h - 8" text-anchor="middle" font-size="12" fill="#9ca3af">{{ d.label }}</text>
        </svg>
      </div>

      <!-- Evaluation Status -->
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83" /><path d="M22 12A10 10 0 0 0 12 2v10z" /></svg>
          <h3 class="font-semibold text-gray-900">Evaluation Status</h3>
        </div>
        <p class="text-[12px] text-muted mb-4">Distribution of your assigned sheets</p>
        <div class="flex items-center gap-5">
          <svg viewBox="0 0 200 200" class="w-32 h-32 sm:w-36 sm:h-36 shrink-0">
            <g transform="rotate(-90 100 100)">
              <circle v-for="arc in donutArcs" :key="arc.label" cx="100" cy="100" :r="donutRadius" fill="none" :stroke="arc.color" stroke-width="26" :stroke-dasharray="arc.dasharray" :stroke-dashoffset="arc.dashoffset">
                <title>{{ arc.label }}: {{ arc.value }} ({{ arc.pct }})</title>
              </circle>
            </g>
            <text x="100" y="96" text-anchor="middle" class="fill-gray-900" font-size="26" font-weight="700">{{ kpis.assigned }}</text>
            <text x="100" y="118" text-anchor="middle" class="fill-gray-400" font-size="11">Assigned Sheets</text>
          </svg>
          <div class="flex flex-col gap-2.5 min-w-0">
            <div v-for="segment in donutSegments" :key="segment.label" class="flex items-start gap-2">
              <span class="w-3 h-3 rounded-full mt-1 shrink-0" :style="{ background: segment.color }"></span>
              <div class="min-w-0">
                <p class="text-[12px] text-gray-600 truncate">{{ segment.label }}</p>
                <p class="text-[14px] font-bold text-gray-900">{{ segment.value }} <span class="text-[12px] font-medium text-muted">({{ segment.pct }})</span></p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Course Wise Pending Evaluation -->
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
          <h3 class="font-semibold text-gray-900">Course Wise Pending Evaluation</h3>
          <button v-if="summary.course_pending_has_more" type="button" class="ml-auto shrink-0 text-[11.5px] font-semibold text-brand-blue hover:underline" @click="showCoursePendingModal = true">
            See All
          </button>
        </div>
        <p class="text-[12px] text-muted mb-4">Pending sheets by course</p>
        <div class="flex flex-col gap-3.5">
          <p v-if="!courseBars.length" class="text-[12px] text-muted text-center py-6">Nothing pending — every assigned sheet is evaluated.</p>
          <div v-for="s in courseBars" :key="s.name">
            <div class="flex items-center justify-between gap-3 text-[12.5px] mb-1.5">
              <span class="font-medium text-gray-700 truncate">{{ s.name }}</span>
              <span class="font-bold text-gray-900">{{ s.pending }}</span>
            </div>
            <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full rounded-full transition-all" :class="s.color" :style="{ width: s.width + '%' }"></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Row 4: Issue Summary / Recent Evaluation Activity / This Week's Performance —
         Issue Summary narrower, the activity table wider. -->
    <section class="grid grid-cols-1 xl:grid-cols-[0.85fr_1.65fr_1fr] gap-4">
      <!-- Issue Summary — same tiles as the admin dashboard -->
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">Issue Summary</h3>
        </div>
        <p class="text-[12px] text-muted mb-4">Status of raised issues in your evaluations</p>
        <div class="grid grid-cols-2 gap-2.5">
          <!-- Both tiles open My Problem Course (same as the admin dashboard's Issue Summary tiles). -->
          <RouterLink
            v-for="tile in issueTiles"
            :key="tile.label"
            :to="{ name: 'my-problem-courses' }"
            class="rounded-2xl p-3 text-center transition-transform hover:-translate-y-0.5"
            :class="tile.tint"
          >
            <span class="w-9 h-9 rounded-full bg-white/70 mx-auto flex items-center justify-center mb-2" :class="tile.text">
              <svg v-if="tile.icon === 'warnCircle'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
              <svg v-else-if="tile.icon === 'clock'" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
              <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>
            </span>
            <p class="text-[11px] font-medium text-gray-600 leading-tight whitespace-nowrap">{{ tile.label }}</p>
            <p class="text-[20px] font-bold text-gray-900 mt-0.5">{{ tile.value }}</p>
          </RouterLink>
        </div>
      </div>

      <!-- Recent Evaluation Activity — same table style as Teacher Workload -->
      <div class="bg-white rounded-2xl shadow-panel p-5 overflow-hidden">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></svg>
          <h3 class="font-semibold text-gray-900">Recent Evaluation Activity</h3>
          <button v-if="summary.recent_has_more" type="button" class="ml-auto shrink-0 text-[11.5px] font-semibold text-brand-blue hover:underline" @click="showRecentModal = true">
            See All
          </button>
        </div>
        <p class="text-[12px] text-muted mb-3">Your courses with their evaluation window</p>
        <div class="overflow-x-auto -mx-1">
          <table class="w-full text-left text-[12px]">
            <thead>
              <tr class="text-muted">
                <th class="px-1 py-1.5 font-medium w-px whitespace-nowrap">#</th>
                <th class="px-1 py-1.5 font-medium">Course</th>
                <th class="px-1 py-1.5 font-medium whitespace-nowrap">Start Date</th>
                <th class="px-1 py-1.5 font-medium whitespace-nowrap">End Date</th>
                <th class="px-1 py-1.5 font-medium text-center whitespace-nowrap">Status</th>
                <th class="px-1 py-1.5 font-medium text-center w-px whitespace-nowrap">Pending</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recent.length">
                <td colspan="6" class="px-1 py-6 text-center text-muted">No answer sheets assigned to you yet.</td>
              </tr>
              <tr v-for="(row, i) in recent" :key="i" class="border-t border-gray-100">
                <td class="px-1 py-1.5 text-gray-500">{{ i + 1 }}</td>
                <!-- Takes the remaining width; shortened with … only when it doesn't fit (full name on hover). -->
                <td class="px-1 py-1.5 font-medium text-gray-800 w-full max-w-0 truncate" :title="row.course">{{ row.course }}</td>
                <td class="px-1 py-1.5 text-gray-700 whitespace-nowrap">{{ row.start }}</td>
                <td class="px-1 py-1.5 text-gray-700 whitespace-nowrap">{{ row.end }}</td>
                <td class="px-1 py-1.5 text-center whitespace-nowrap">
                  <span v-if="row.pending > 0" class="inline-flex justify-center rounded-full bg-amber-50 text-badge font-semibold px-2 py-0.5">Pending</span>
                  <span v-else class="inline-flex justify-center rounded-full bg-emerald-50 text-success font-semibold px-2 py-0.5">Completed</span>
                </td>
                <!-- How many sheets are still pending — just "-" once completed. -->
                <td class="px-1 py-1.5 text-center">
                  <span v-if="row.pending > 0" class="inline-flex min-w-[28px] justify-center rounded-full bg-amber-50 text-badge font-semibold px-1.5 py-0.5">{{ row.pending }}</span>
                  <span v-else class="text-gray-400">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- This Week's Performance -->
      <div class="bg-white rounded-2xl shadow-panel p-5">
        <div class="flex items-center gap-2 mb-0.5">
          <svg class="w-5 h-5 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>
          <h3 class="font-semibold text-gray-900">{{ week.isCurrent ? "This Week's Performance" : 'Weekly Performance' }}</h3>
        </div>
        <!-- Week picker: ‹ previous week · date range · next week › -->
        <div class="flex items-center justify-between gap-2 mb-2">
          <p class="text-[12px] text-muted">Evaluation count per day</p>
          <div class="flex items-center gap-1 shrink-0">
            <button type="button" class="w-6 h-6 rounded-md border border-input-border flex items-center justify-center text-gray-600 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-40 disabled:cursor-not-allowed" :disabled="weekLoading" aria-label="Previous week" @click="changeWeek(-1)">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6" /></svg>
            </button>
            <span class="min-w-[124px] text-center text-[11.5px] font-semibold text-gray-700 whitespace-nowrap">{{ week.label }}</span>
            <button type="button" class="w-6 h-6 rounded-md border border-input-border flex items-center justify-center text-gray-600 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-40 disabled:cursor-not-allowed" :disabled="weekLoading || week.isCurrent" :title="week.isCurrent ? 'This is the current week' : 'Next week'" aria-label="Next week" @click="changeWeek(1)">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6" /></svg>
            </button>
          </div>
        </div>
        <svg :viewBox="`0 0 ${BAR.w} ${BAR.h}`" class="w-full h-auto transition-opacity" :class="weekLoading ? 'opacity-50' : ''" role="img" aria-label="Evaluations per day for the selected week">
          <defs>
            <linearGradient id="tdBarFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#2f56c0" />
              <stop offset="100%" stop-color="#2f56c0" stop-opacity="0.35" />
            </linearGradient>
          </defs>
          <g v-for="t in BAR.ticks" :key="t">
            <line :x1="BAR.left" :x2="BAR.w - BAR.right" :y1="barY(t)" :y2="barY(t)" stroke="#f3f4f6" />
            <text :x="BAR.left - 7" :y="barY(t) + 4" text-anchor="end" font-size="12" fill="#9ca3af">{{ t }}</text>
          </g>
          <g v-for="b in weekBars" :key="b.day">
            <rect :x="b.x" :y="b.y" :width="barWidth" :height="b.height" rx="4" fill="url(#tdBarFill)">
              <title>{{ b.day }} {{ b.date }} — {{ b.count }} evaluated</title>
            </rect>
            <text :x="b.x + barWidth / 2" :y="b.y - 6" text-anchor="middle" font-size="12" font-weight="700" fill="#111827">{{ b.count }}</text>
            <text :x="b.x + barWidth / 2" :y="BAR.h - 22" text-anchor="middle" font-size="12" fill="#6b7280">{{ b.day }}</text>
            <text :x="b.x + barWidth / 2" :y="BAR.h - 8" text-anchor="middle" font-size="11" fill="#9ca3af">{{ b.date }}</text>
          </g>
        </svg>
      </div>
    </section>

    <TeacherCoursePendingModal v-if="showCoursePendingModal" @close="showCoursePendingModal = false" />
    <TeacherRecentActivityModal v-if="showRecentModal" @close="showRecentModal = false" />
  </div>
</template>
