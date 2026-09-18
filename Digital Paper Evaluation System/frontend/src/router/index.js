import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import DashboardLayout from '../layouts/DashboardLayout.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { guestOnly: true, title: 'Sign In' },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('../views/ResetPasswordView.vue'),
      meta: { title: 'Reset Password' },
    },
    // Deliberately outside DashboardLayout — the marking screen wants the
    // full viewport (no sidebar/header/footer chrome eating into it), same
    // as login/reset-password above. Still auth-gated (requiresAuth isn't
    // inherited here since there's no shared parent route to carry it).
    // `token` is a one-time evaluation_token minted fresh by
    // MyPendingCourseController::startEvaluation() on every "Start
    // Evaluate" click, not this sheet's own id — a copied/bookmarked link
    // stops working once it expires or a later click supersedes it (see
    // that controller's own docblock).
    {
      path: '/my-pending-courses/:token/evaluate',
      name: 'evaluate-paper',
      component: () => import('../views/EvaluatePaperView.vue'),
      meta: { requiresAuth: true, title: 'Evaluate Answer Sheet' },
    },
    {
      path: '/',
      component: DashboardLayout,
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: { name: 'dashboard' } },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('../views/DashboardView.vue'),
          meta: { title: 'Dashboard' },
        },
        {
          path: 'profile',
          name: 'profile',
          component: () => import('../views/ProfileView.vue'),
          meta: { title: 'Profile' },
        },
        {
          path: 'teachers',
          name: 'teachers',
          component: () => import('../views/TeachersView.vue'),
          meta: { title: 'Teachers' },
        },
        {
          path: 'teachers/create',
          name: 'teachers-create',
          component: () => import('../views/TeacherFormView.vue'),
          meta: { title: 'Add Teacher' },
        },
        {
          path: 'teachers/:id/edit',
          name: 'teachers-edit',
          component: () => import('../views/TeacherFormView.vue'),
          meta: { title: 'Edit Teacher' },
        },
        {
          path: 'teachers/bulk-upload',
          name: 'teachers-bulk-upload',
          component: () => import('../views/TeacherBulkUploadView.vue'),
          meta: { title: 'Bulk Upload Teachers' },
        },
        {
          path: 'question-papers',
          name: 'question-papers',
          component: () => import('../views/QuestionPapersView.vue'),
          meta: { title: 'Question Papers' },
        },
        {
          path: 'question-papers/setup',
          name: 'question-papers-setup',
          component: () => import('../views/QuestionPaperSetupView.vue'),
          meta: { title: 'Setup Question Paper' },
        },
        {
          path: 'question-papers/:id/configure',
          name: 'question-papers-configure',
          component: () => import('../views/QuestionPaperConfigureView.vue'),
          meta: { title: 'Configure Question Paper' },
        },
        {
          path: 'question-papers/:id/view',
          name: 'question-papers-view',
          component: () => import('../views/QuestionPaperViewView.vue'),
          meta: { title: 'View Question Paper' },
        },
        {
          path: 'answer-sheets',
          name: 'answer-sheets',
          component: () => import('../views/AnswerSheetsView.vue'),
          meta: { title: 'Answer Sheet Upload' },
        },
        {
          path: 'answer-sheets/upload',
          name: 'answer-sheets-upload',
          component: () => import('../views/AnswerSheetUploadView.vue'),
          meta: { title: 'Upload Answer Sheets' },
        },
        {
          path: 'assign-teacher',
          name: 'assign-teacher',
          component: () => import('../views/AssignTeacherView.vue'),
          meta: { title: 'Assign Teacher' },
        },
        {
          path: 'assigned-teachers',
          name: 'assigned-teachers',
          component: () => import('../views/AssignedTeachersView.vue'),
          meta: { title: 'Assigned Teacher List' },
        },
        {
          path: 'reports/teacher-wise-evaluation',
          name: 'reports-teacher-wise-evaluation',
          component: () => import('../views/reports/TeacherWiseEvaluationReportView.vue'),
          meta: { title: 'Teacher Wise Evaluation Report' },
        },
        {
          path: 'reports/answer-book-top-sheet',
          name: 'reports-answer-book-top-sheet',
          component: () => import('../views/reports/AnswerBookTopSheetReportView.vue'),
          meta: { title: 'Evaluation Answer Book / Top Sheet Report' },
        },
        {
          path: 'upload',
          name: 'upload',
          component: () => import('../views/UploadView.vue'),
          meta: { title: 'Center Upload' },
        },
        {
          path: 'review',
          name: 'review',
          component: () => import('../views/ReviewView.vue'),
          meta: { title: 'Teacher Review' },
        },
        {
          path: 'review/:id',
          name: 'review-detail',
          component: () => import('../views/ReviewDetailView.vue'),
          meta: { title: 'Review Detail' },
        },
        {
          path: 'teacher/register',
          name: 'teacher-register',
          component: () => import('../views/TeacherRegisterView.vue'),
          meta: { title: 'Teacher Registration' },
        },
        {
          path: 'students',
          name: 'students',
          component: () => import('../views/StudentsView.vue'),
          meta: { title: 'Students' },
        },
        {
          path: 'notifications',
          name: 'notifications',
          component: () => import('../views/NotificationsView.vue'),
          meta: { title: 'Notifications' },
        },
        {
          path: 'my-pending-courses',
          name: 'my-pending-courses',
          component: () => import('../views/MyPendingCoursesView.vue'),
          meta: { title: 'My Pending Course' },
        },
        {
          path: 'my-completed-courses',
          name: 'my-completed-courses',
          component: () => import('../views/MyCompletedCoursesView.vue'),
          meta: { title: 'My Completed Course' },
        },
        {
          path: 'students/create',
          name: 'students-create',
          component: () => import('../views/StudentFormView.vue'),
          meta: { title: 'Add Student' },
        },
        {
          path: 'students/:id/edit',
          name: 'students-edit',
          component: () => import('../views/StudentFormView.vue'),
          meta: { title: 'Edit Student' },
        },
        {
          path: 'students/bulk-upload',
          name: 'students-bulk-upload',
          component: () => import('../views/StudentBulkUploadView.vue'),
          meta: { title: 'Bulk Upload Students' },
        },
        {
          path: 'general-settings',
          name: 'general-settings',
          component: () => import('../views/GeneralSettingsView.vue'),
          meta: { title: 'General Settings' },
        },
        {
          path: 'configurations/users',
          name: 'configurations-users',
          component: () => import('../views/configurations/UsersView.vue'),
          meta: { title: 'Users' },
        },
        {
          path: 'configurations/users/create',
          name: 'configurations-users-create',
          component: () => import('../views/configurations/UserFormView.vue'),
          meta: { title: 'Add User' },
        },
        {
          path: 'configurations/users/:id/edit',
          name: 'configurations-users-edit',
          component: () => import('../views/configurations/UserFormView.vue'),
          meta: { title: 'Edit User' },
        },
        {
          path: 'configurations/roles',
          name: 'configurations-roles',
          component: () => import('../views/configurations/RolesView.vue'),
          meta: { title: 'Roles' },
        },
        {
          path: 'configurations/roles/create',
          name: 'configurations-roles-create',
          component: () => import('../views/configurations/RoleFormView.vue'),
          meta: { title: 'Add Role' },
        },
        {
          path: 'configurations/roles/:id/edit',
          name: 'configurations-roles-edit',
          component: () => import('../views/configurations/RoleFormView.vue'),
          meta: { title: 'Edit Role' },
        },
        {
          path: 'configurations/permission-groups',
          name: 'configurations-permission-groups',
          component: () => import('../views/configurations/PermissionGroupsView.vue'),
          meta: { title: 'Permission Groups' },
        },
        {
          path: 'configurations/permission-groups/create',
          name: 'configurations-permission-groups-create',
          component: () => import('../views/configurations/PermissionGroupFormView.vue'),
          meta: { title: 'Add Permission Group' },
        },
        {
          path: 'configurations/permission-groups/:id/edit',
          name: 'configurations-permission-groups-edit',
          component: () => import('../views/configurations/PermissionGroupFormView.vue'),
          meta: { title: 'Edit Permission Group' },
        },
        {
          path: 'configurations/permission-sub-groups',
          name: 'configurations-permission-sub-groups',
          component: () => import('../views/configurations/PermissionSubGroupsView.vue'),
          meta: { title: 'Permission Sub Groups' },
        },
        {
          path: 'configurations/permission-sub-groups/create',
          name: 'configurations-permission-sub-groups-create',
          component: () => import('../views/configurations/PermissionSubGroupFormView.vue'),
          meta: { title: 'Add Permission Sub Group' },
        },
        {
          path: 'configurations/permission-sub-groups/:id/edit',
          name: 'configurations-permission-sub-groups-edit',
          component: () => import('../views/configurations/PermissionSubGroupFormView.vue'),
          meta: { title: 'Edit Permission Sub Group' },
        },
        {
          path: 'configurations/permissions',
          name: 'configurations-permissions',
          component: () => import('../views/configurations/PermissionsView.vue'),
          meta: { title: 'Permissions' },
        },
        {
          path: 'configurations/permissions/create',
          name: 'configurations-permissions-create',
          component: () => import('../views/configurations/PermissionFormView.vue'),
          meta: { title: 'Add Permission' },
        },
        {
          path: 'configurations/permissions/:id/edit',
          name: 'configurations-permissions-edit',
          component: () => import('../views/configurations/PermissionFormView.vue'),
          meta: { title: 'Edit Permission' },
        },
        {
          path: 'configurations/email-logs',
          name: 'configurations-email-logs',
          component: () => import('../views/configurations/EmailLogsView.vue'),
          meta: { title: 'Email Logs' },
        },
        {
          path: 'master/courses',
          name: 'master-courses',
          component: () => import('../views/master/CoursesView.vue'),
          meta: { title: 'Courses' },
        },
        {
          path: 'master/courses/create',
          name: 'master-courses-create',
          component: () => import('../views/master/CourseFormView.vue'),
          meta: { title: 'Add Course' },
        },
        {
          path: 'master/courses/:id/edit',
          name: 'master-courses-edit',
          component: () => import('../views/master/CourseFormView.vue'),
          meta: { title: 'Edit Course' },
        },
        {
          path: 'master/courses/bulk-upload',
          name: 'master-courses-bulk-upload',
          component: () => import('../views/master/CourseBulkUploadView.vue'),
          meta: { title: 'Bulk Upload Courses' },
        },
        {
          path: 'master/programs',
          name: 'master-programs',
          component: () => import('../views/master/ProgramsView.vue'),
          meta: { title: 'Programs' },
        },
        {
          path: 'master/programs/create',
          name: 'master-programs-create',
          component: () => import('../views/master/ProgramFormView.vue'),
          meta: { title: 'Add Program' },
        },
        {
          path: 'master/programs/:id/edit',
          name: 'master-programs-edit',
          component: () => import('../views/master/ProgramFormView.vue'),
          meta: { title: 'Edit Program' },
        },
        {
          path: 'master/programs/bulk-upload',
          name: 'master-programs-bulk-upload',
          component: () => import('../views/master/ProgramBulkUploadView.vue'),
          meta: { title: 'Bulk Upload Programs' },
        },
        {
          path: 'master/departments',
          name: 'master-departments',
          component: () => import('../views/master/DepartmentsView.vue'),
          meta: { title: 'Departments' },
        },
        {
          path: 'master/departments/create',
          name: 'master-departments-create',
          component: () => import('../views/master/DepartmentFormView.vue'),
          meta: { title: 'Add Department' },
        },
        {
          path: 'master/departments/:id/edit',
          name: 'master-departments-edit',
          component: () => import('../views/master/DepartmentFormView.vue'),
          meta: { title: 'Edit Department' },
        },
        {
          path: 'master/departments/bulk-upload',
          name: 'master-departments-bulk-upload',
          component: () => import('../views/master/DepartmentBulkUploadView.vue'),
          meta: { title: 'Bulk Upload Departments' },
        },
        {
          path: 'master/exam-terms',
          name: 'master-exam-terms',
          component: () => import('../views/master/ExamTermsView.vue'),
          meta: { title: 'Exam Terms' },
        },
        {
          path: 'master/exam-terms/create',
          name: 'master-exam-terms-create',
          component: () => import('../views/master/ExamTermFormView.vue'),
          meta: { title: 'Add Exam Term' },
        },
        {
          path: 'master/exam-terms/:id/edit',
          name: 'master-exam-terms-edit',
          component: () => import('../views/master/ExamTermFormView.vue'),
          meta: { title: 'Edit Exam Term' },
        },
        {
          path: 'master/exam-types',
          name: 'master-exam-types',
          component: () => import('../views/master/ExamTypesView.vue'),
          meta: { title: 'Exam Types' },
        },
        {
          path: 'master/exam-types/create',
          name: 'master-exam-types-create',
          component: () => import('../views/master/ExamTypeFormView.vue'),
          meta: { title: 'Add Exam Type' },
        },
        {
          path: 'master/exam-types/:id/edit',
          name: 'master-exam-types-edit',
          component: () => import('../views/master/ExamTypeFormView.vue'),
          meta: { title: 'Edit Exam Type' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: { name: 'dashboard' } },
  ],
})

router.beforeEach((to) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && authStore.isAuthenticated) {
    return { name: 'dashboard' }
  }

  // Non-Super-Admin teachers with no e-signature on file yet get parked on
  // their own Profile page until they upload one — see
  // AuthController::withEffectivePermissions()'s own docblock for exactly
  // who this applies to (is_super_admin checked by role ID there, never by
  // name). Only enforceable once authStore.user is actually loaded — right
  // after login it already is; on a hard refresh it fills in the first
  // time some view calls fetchMe(), same as the per-page permission checks.
  if (authStore.user?.profile_completion_required && to.name !== 'profile' && to.name !== 'login') {
    return { name: 'profile' }
  }
})

// Each route carries its own meta.title (matching the per-page <title> in
// designed_files/*.html — e.g. dashboard.html is "Dashboard - CJ Paper
// Check") so the tab title tracks whichever page is actually showing,
// instead of index.html's static <title> staying stuck on one page forever.
// The actual document.title assignment lives in App.vue now, not here — it
// also needs the dynamic site title from stores/branding.js, which isn't
// necessarily loaded yet by the time this fires on the very first
// navigation (see the comment there for why that's a watcher, not this).

export default router
