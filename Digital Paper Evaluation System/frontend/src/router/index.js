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
      meta: { guestOnly: true },
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
        },
        {
          path: 'upload',
          name: 'upload',
          component: () => import('../views/UploadView.vue'),
        },
        {
          path: 'review',
          name: 'review',
          component: () => import('../views/ReviewView.vue'),
        },
        {
          path: 'review/:id',
          name: 'review-detail',
          component: () => import('../views/ReviewDetailView.vue'),
        },
        {
          path: 'teacher/register',
          name: 'teacher-register',
          component: () => import('../views/TeacherRegisterView.vue'),
        },
        {
          path: 'master/courses',
          name: 'master-courses',
          component: () => import('../views/master/CoursesView.vue'),
        },
        {
          path: 'master/programs',
          name: 'master-programs',
          component: () => import('../views/master/ProgramsView.vue'),
        },
        {
          path: 'master/departments',
          name: 'master-departments',
          component: () => import('../views/master/DepartmentsView.vue'),
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
})

export default router
