<script setup>
import { computed, nextTick, onMounted, ref, watch, watchEffect } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useSidebar } from '../../composables/useSidebar'
import { useBrandingStore } from '../../stores/branding'
import { useAuthStore } from '../../stores/auth'
import { useNotificationsStore } from '../../stores/notifications'

const authStore = useAuthStore()
const route = useRoute()
const branding = useBrandingStore()
const notificationsStore = useNotificationsStore()
const { isOpen, isDesktop, hoverOpen, hoverClose } = useSidebar()

// True once the sidebar's initial (collapsed) state has painted once, so the
// CSS transition rules only start applying after that — otherwise the very
// first collapse/expand would visibly animate in from a wrong starting
// state. Mirrors designed_files/js/script.js's is-ready handling.
const isReady = ref(false)
onMounted(() => {
  nextTick(() => requestAnimationFrame(() => { isReady.value = true }))
  // Silent — DashboardView's own fetchMe() surfaces the real error; this
  // one just needs authStore.user.permission_names populated for the
  // v-if="authStore.can(...)" checks below.
  authStore.fetchMe().catch(() => {})
  notificationsStore.loadUnresolvedCount()
})

function isActive(to, exact) {
  return exact ? route.path === to : route.path.startsWith(to)
}

const masterItems = [
  { to: '/master/courses', label: 'Course', permission: 'course-list' },
  { to: '/master/programs', label: 'Programs', permission: 'program-list' },
  { to: '/master/departments', label: 'Departments', permission: 'department-list' },
  { to: '/master/exam-terms', label: 'Exam Term', permission: 'exam-term-list' },
  { to: '/master/exam-types', label: 'Exam Type', permission: 'exam-type-list' },
]
const visibleMasterItems = computed(() => masterItems.filter((item) => !item.permission || authStore.can(item.permission)))

const masterActive = computed(() => route.path.startsWith('/master'))
const masterOpen = ref(masterActive.value)
watchEffect(() => {
  if (masterActive.value) masterOpen.value = true
})

// Measures the submenu's real rendered height (like designed_files/js/
// script.js's lmsSubmenu.scrollHeight) instead of hand-computing it from
// item count — a fixed-per-item formula drifts from the actual height
// (margins/gaps add up) and silently clips the last item.
//
// This has to be a watcher with flush: 'post', not a computed — a computed
// re-runs while Vue is still building the new render (the .is-collapsed
// class removal that un-hides this element hasn't reached the real DOM
// yet), so scrollHeight would still read as if collapsed/hidden. 'post'
// defers this until after that DOM patch actually happened.
const masterSubmenuContent = ref(null)
const masterMaxHeight = ref('0px')

function updateMasterMaxHeight() {
  masterMaxHeight.value = !isOpen.value || !masterOpen.value
    ? '0px'
    : `${masterSubmenuContent.value?.scrollHeight ?? 200}px`
}

watch([isOpen, masterOpen], updateMasterMaxHeight, { flush: 'post' })
onMounted(() => nextTick(updateMasterMaxHeight))

// Same collapsible-submenu pattern as Master above, for the "Evaluate
// Course" group — a teacher's own pending vs already-completed
// evaluations (see MyPendingCoursesView.vue / MyCompletedCoursesView.vue),
// previously two separate top-level links, now one parent menu with both
// as sub-items.
const evaluateCourseItems = [
  { to: '/my-pending-courses', label: 'Pending Course', permission: 'my-pending-course-list' },
  { to: '/my-completed-courses', label: 'Completed Course', permission: 'my-completed-course-list' },
]
const visibleEvaluateCourseItems = computed(() => evaluateCourseItems.filter((item) => authStore.can(item.permission)))

const evaluateCourseActive = computed(
  () => route.path.startsWith('/my-pending-courses') || route.path.startsWith('/my-completed-courses'),
)
const evaluateCourseOpen = ref(evaluateCourseActive.value)
watchEffect(() => {
  if (evaluateCourseActive.value) evaluateCourseOpen.value = true
})

const evaluateCourseSubmenuContent = ref(null)
const evaluateCourseMaxHeight = ref('0px')

function updateEvaluateCourseMaxHeight() {
  evaluateCourseMaxHeight.value = !isOpen.value || !evaluateCourseOpen.value
    ? '0px'
    : `${evaluateCourseSubmenuContent.value?.scrollHeight ?? 200}px`
}

watch([isOpen, evaluateCourseOpen], updateEvaluateCourseMaxHeight, { flush: 'post' })
onMounted(() => nextTick(updateEvaluateCourseMaxHeight))

// Same collapsible-submenu pattern as Master above, just for the
// "Assign Teacher" group (the assign-and-distribute page itself, plus its
// read-only "Assigned Teacher List" companion — see AssignedTeachersView.vue).
const assignTeacherItems = [
  { to: '/assign-teacher', label: 'Assign Teacher', permission: 'assign-answersheet-to-teacher' },
  { to: '/assigned-teachers', label: 'Assigned Teacher List', permission: 'assigned-teacher-list' },
]
const visibleAssignTeacherItems = computed(() => assignTeacherItems.filter((item) => authStore.can(item.permission)))

const assignTeacherActive = computed(
  () => route.path.startsWith('/assign-teacher') || route.path.startsWith('/assigned-teachers'),
)
const assignTeacherOpen = ref(assignTeacherActive.value)
watchEffect(() => {
  if (assignTeacherActive.value) assignTeacherOpen.value = true
})

const assignTeacherSubmenuContent = ref(null)
const assignTeacherMaxHeight = ref('0px')

function updateAssignTeacherMaxHeight() {
  assignTeacherMaxHeight.value = !isOpen.value || !assignTeacherOpen.value
    ? '0px'
    : `${assignTeacherSubmenuContent.value?.scrollHeight ?? 200}px`
}

watch([isOpen, assignTeacherOpen], updateAssignTeacherMaxHeight, { flush: 'post' })
onMounted(() => nextTick(updateAssignTeacherMaxHeight))

// Same collapsible-submenu pattern as Master/Assign Teacher above, for the
// "Report" group — right after Assign Teacher (see TeacherWise
// EvaluationReportView.vue / AnswerBookTopSheetReportView.vue).
const reportItems = [
  { to: '/reports/teacher-wise-evaluation', label: 'Teacher Wise Report', permission: 'teacher-wise-evaluation-report' },
  { to: '/reports/answer-book-top-sheet', label: 'Answer Book Report', permission: 'answer-book-report' },
]
const visibleReportItems = computed(() => reportItems.filter((item) => !item.permission || authStore.can(item.permission)))

const reportActive = computed(() => route.path.startsWith('/reports'))
const reportOpen = ref(reportActive.value)
watchEffect(() => {
  if (reportActive.value) reportOpen.value = true
})

const reportSubmenuContent = ref(null)
const reportMaxHeight = ref('0px')

function updateReportMaxHeight() {
  reportMaxHeight.value = !isOpen.value || !reportOpen.value
    ? '0px'
    : `${reportSubmenuContent.value?.scrollHeight ?? 200}px`
}

watch([isOpen, reportOpen], updateReportMaxHeight, { flush: 'post' })
onMounted(() => nextTick(updateReportMaxHeight))

// Same collapsible-submenu pattern as Master/Assign Teacher above, for the
// "Configurations" group — General Settings plus the Permission Group /
// Permission Sub Group master data.
const configurationsItems = [
  { to: '/configurations/users', label: 'Users', permission: 'user-list' },
  { to: '/configurations/roles', label: 'Roles', permission: 'role-list' },
  { to: '/configurations/permission-groups', label: 'Permission Group', permission: 'permission-group-list' },
  { to: '/configurations/permission-sub-groups', label: 'Permission Sub Group', permission: 'permission-sub-group-list' },
  { to: '/configurations/permissions', label: 'Permission', permission: 'permission-list' },
  // No `permission` key — deliberately open to any signed-in user (see
  // EmailLogsView.vue's own top docblock), unlike every other item here.
  { to: '/configurations/email-logs', label: 'Email Logs', permission: 'email-log-list' },
  { to: '/general-settings', label: 'General Settings', permission: 'general-settings' },
]
const visibleConfigurationsItems = computed(() => configurationsItems.filter((item) => !item.permission || authStore.can(item.permission)))

const configurationsActive = computed(
  () => route.path.startsWith('/general-settings') || route.path.startsWith('/configurations'),
)
const configurationsOpen = ref(configurationsActive.value)
watchEffect(() => {
  if (configurationsActive.value) configurationsOpen.value = true
})

const configurationsSubmenuContent = ref(null)
const configurationsMaxHeight = ref('0px')

function updateConfigurationsMaxHeight() {
  configurationsMaxHeight.value = !isOpen.value || !configurationsOpen.value
    ? '0px'
    : `${configurationsSubmenuContent.value?.scrollHeight ?? 200}px`
}

watch([isOpen, configurationsOpen], updateConfigurationsMaxHeight, { flush: 'post' })
onMounted(() => nextTick(updateConfigurationsMaxHeight))
</script>

<template>
  <aside id="sidebar"
    class="z-40 h-screen max-h-screen w-[250px] bg-sidebar-gradient text-white flex flex-col overflow-hidden overflow-x-hidden pb-20"
    :class="{ 'is-collapsed': isDesktop && !isOpen, 'is-open': !isDesktop && isOpen, 'is-ready': isReady }"
    @mouseenter="hoverOpen" @mouseleave="hoverClose">
    <!-- Logo -->
    <div
      class="sidebar-logo-wrap relative shrink-0 flex items-center justify-center text-center bg-white h-14 w-full px-4 lg:h-[240px] lg:w-[240px] lg:rounded-full lg:-top-[120px] lg:-left-10 lg:px-0 lg:-mb-[60px]">
      <RouterLink to="/dashboard" class="inline-flex items-center justify-center">
        <img :src="branding.headerLogoFullUrl" :alt="branding.siteTitleValue" width="178" height="102"
          class="sidebar-logo-full relative h-10 sm:h-11 lg:h-14 lg:top-10 w-auto object-contain"
          @error="($event.target.onerror = null), ($event.target.src = '/images/logo-image.png')" />
        <img :src="branding.headerLogoIconUrl" alt="" width="40" height="40" class="sidebar-logo-icon h-8 w-8 object-contain"
          aria-hidden="true" @error="($event.target.onerror = null), ($event.target.src = '/images/logo-image.png')" />
      </RouterLink>
    </div>

    <nav
      class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden overscroll-contain px-4 pt-2 pb-2 space-y-1 [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,0.45)_rgba(255,255,255,0.12)] [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-track]:bg-white/10 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-white/40">

      <RouterLink v-if="authStore.can('admin-dashboard') || authStore.can('hod-dashboard') || authStore.can('teacher-dashboard')" to="/dashboard"
        class="relative z-[1] flex items-center gap-3 rounded-xl py-2.5 pl-4 pr-5 text-[13px] font-medium transition-colors"
        :class="isActive('/dashboard', true) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" />
        </svg>
        <span class="sidebar-label">Dashboard</span>
      </RouterLink>
      <!-- Tied to visibleMasterItems itself (not each permission repeated
           here) so a permission-less item like Exam Type — always visible —
           keeps this button showing even when every *other* Master item's
           own permission is missing. -->
      <button v-if="visibleMasterItems.length" type="button" :aria-expanded="masterOpen" aria-controls="master-submenu"
        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="masterActive ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'"
        @click="masterOpen = !masterOpen">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <ellipse cx="12" cy="5" rx="9" ry="3" />
          <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" />
          <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3" />
        </svg>
        <span class="sidebar-label flex-1 text-left">Master</span>
        <svg class="sidebar-chevron w-3.5 h-3.5 shrink-0 transition-transform duration-300 ease-in-out"
          :class="{ 'rotate-180': masterOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div id="master-submenu" class="sidebar-submenu overflow-hidden transition-[max-height] duration-300 ease-in-out"
        :style="{ maxHeight: masterMaxHeight }">
        <div ref="masterSubmenuContent" class="mt-1 ml-4 space-y-1 pb-1">
          <RouterLink v-for="item in visibleMasterItems" :key="item.to" :to="item.to"
            class="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors text-[12px] font-medium"
            :class="route.path === item.to ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10'">
            <span class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0"></span>
            {{ item.label }}
          </RouterLink>
        </div>
      </div>

      <RouterLink v-if="authStore.can('teacher-list')" to="/teachers"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/teachers', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M22 10v6M2 10l10-5 10 5-10 5-10-5z" />
          <path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5" />
        </svg>
        <span class="sidebar-label">Teachers</span>
      </RouterLink>

      <RouterLink v-if="authStore.can('student-list')" to="/students"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/students', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
        <span class="sidebar-label">Students</span>
      </RouterLink>

      <RouterLink v-if="authStore.can('notification-list')" to="/notifications"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/notifications', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <span class="relative shrink-0">
          <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          <!-- Still visible when the sidebar's collapsed to icons only
               (no room for the pill badge below there) — a plain dot is
               enough to say "something needs attention". -->
          <span v-if="notificationsStore.unresolvedCount > 0" class="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-brand ring-2 ring-white/20"></span>
        </span>
        <span class="sidebar-label flex-1">Notifications</span>
        <span
          v-if="notificationsStore.unresolvedCount > 0"
          class="sidebar-label inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-brand text-white text-[10px] font-semibold leading-none"
        >
          {{ notificationsStore.unresolvedCount > 99 ? '99+' : notificationsStore.unresolvedCount }}
        </span>
      </RouterLink>

      <button v-if="authStore.can('my-pending-course-list') || authStore.can('my-completed-course-list')" type="button" :aria-expanded="evaluateCourseOpen" aria-controls="evaluate-course-submenu"
        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="evaluateCourseActive ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'"
        @click="evaluateCourseOpen = !evaluateCourseOpen">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
          <circle cx="11" cy="14" r="2.5" />
          <path d="M13 16l1.5 1.5" />
        </svg>
        <span class="sidebar-label flex-1 text-left">Evaluate Course</span>
        <svg class="sidebar-chevron w-3.5 h-3.5 shrink-0 transition-transform duration-300 ease-in-out"
          :class="{ 'rotate-180': evaluateCourseOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div id="evaluate-course-submenu" class="sidebar-submenu overflow-hidden transition-[max-height] duration-300 ease-in-out"
        :style="{ maxHeight: evaluateCourseMaxHeight }">
        <div ref="evaluateCourseSubmenuContent" class="mt-1 ml-4 space-y-1 pb-1">
          <RouterLink v-for="item in visibleEvaluateCourseItems" :key="item.to" :to="item.to"
            class="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors text-[12px] font-medium"
            :class="route.path === item.to ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10'">
            <span class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0"></span>
            {{ item.label }}
          </RouterLink>
        </div>
      </div>

      <RouterLink v-if="authStore.can('question-paper-list')" to="/question-papers"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/question-papers', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
          <line x1="8" y1="13" x2="16" y2="13" />
          <line x1="8" y1="17" x2="16" y2="17" />
        </svg>
        <span class="sidebar-label">Question Papers</span>
      </RouterLink>

      <RouterLink v-if="authStore.can('answer-sheet-list')" to="/answer-sheets"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/answer-sheets', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 3v12M12 3l-4 4M12 3l4 4" />
          <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
        </svg>
        <span class="sidebar-label">Answer Sheet Upload</span>
      </RouterLink>

      <button v-if="authStore.can('assign-answersheet-to-teacher') || authStore.can('assigned-teacher-list')" type="button" :aria-expanded="assignTeacherOpen" aria-controls="assign-teacher-submenu"
        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="assignTeacherActive ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'"
        @click="assignTeacherOpen = !assignTeacherOpen">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="9" y="2" width="6" height="4" rx="1" />
          <path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4" />
          <path d="M9 14l2 2 4-4" />
        </svg>
        <span class="sidebar-label flex-1 text-left">Assign Teacher</span>
        <svg class="sidebar-chevron w-3.5 h-3.5 shrink-0 transition-transform duration-300 ease-in-out"
          :class="{ 'rotate-180': assignTeacherOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div id="assign-teacher-submenu" class="sidebar-submenu overflow-hidden transition-[max-height] duration-300 ease-in-out"
        :style="{ maxHeight: assignTeacherMaxHeight }">
        <div ref="assignTeacherSubmenuContent" class="mt-1 ml-4 space-y-1 pb-1">
          <RouterLink v-for="item in visibleAssignTeacherItems" :key="item.to" :to="item.to"
            class="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors text-[12px] font-medium"
            :class="route.path === item.to ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10'">
            <span class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0"></span>
            {{ item.label }}
          </RouterLink>
        </div>
      </div>

      <button v-if="authStore.can('teacher-wise-evaluation-report') || authStore.can('answer-book-report')" type="button" :aria-expanded="reportOpen" aria-controls="report-submenu"
        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="reportActive ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'"
        @click="reportOpen = !reportOpen">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 3v18h18" />
          <path d="M18 17V9" />
          <path d="M13 17V5" />
          <path d="M8 17v-3" />
        </svg>
        <span class="sidebar-label flex-1 text-left">Report</span>
        <svg class="sidebar-chevron w-3.5 h-3.5 shrink-0 transition-transform duration-300 ease-in-out"
          :class="{ 'rotate-180': reportOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      <div id="report-submenu" class="sidebar-submenu overflow-hidden transition-[max-height] duration-300 ease-in-out"
        :style="{ maxHeight: reportMaxHeight }">
        <div ref="reportSubmenuContent" class="mt-1 ml-4 space-y-1 pb-1">
          <RouterLink v-for="item in visibleReportItems" :key="item.to" :to="item.to"
            class="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors text-[12px] font-medium"
            :class="route.path === item.to ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10'">
            <span class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0"></span>
            {{ item.label }}
          </RouterLink>
        </div>
      </div>

      <button v-if="authStore.can('user-list') || authStore.can('role-list') || authStore.can('permission-group-list') || authStore.can('permission-sub-group-list') || authStore.can('permission-list') || authStore.can('general-settings')" type="button" :aria-expanded="configurationsOpen" aria-controls="configurations-submenu"
        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="configurationsActive ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'"
        @click="configurationsOpen = !configurationsOpen">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
        <span class="sidebar-label flex-1 text-left">Configurations</span>
        <svg class="sidebar-chevron w-3.5 h-3.5 shrink-0 transition-transform duration-300 ease-in-out"
          :class="{ 'rotate-180': configurationsOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      
      <div id="configurations-submenu" class="sidebar-submenu overflow-hidden transition-[max-height] duration-300 ease-in-out"
        :style="{ maxHeight: configurationsMaxHeight }">
        <div ref="configurationsSubmenuContent" class="mt-1 ml-4 space-y-1 pb-1">
          <RouterLink v-for="item in visibleConfigurationsItems" :key="item.to" :to="item.to"
            class="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors text-[12px] font-medium"
            :class="route.path === item.to ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10'">
            <span class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0"></span>
            {{ item.label }}
          </RouterLink>
        </div>
      </div>

      <!-- <RouterLink to="/upload"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/upload', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 3v12M12 3l-4 4M12 3l4 4" />
          <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
        </svg>
        <span class="sidebar-label">Center Upload</span>
      </RouterLink> -->

      <!-- <RouterLink to="/review"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/review', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 11l3 3L22 4" />
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
        <span class="sidebar-label">Teacher Review</span>
      </RouterLink> -->

      <!-- <RouterLink to="/teacher/register"
        class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors text-[13px] font-medium"
        :class="isActive('/teacher/register', false) ? 'bg-page-bg text-gray-900 font-semibold' : 'text-white/95 hover:bg-white/10'">
        <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
          <circle cx="9.5" cy="7" r="4" />
          <path d="M19 8v6M22 11h-6" />
        </svg>
        <span class="sidebar-label">Teacher Registration</span>
      </RouterLink> -->

      

      
    </nav>
  </aside>
</template>
