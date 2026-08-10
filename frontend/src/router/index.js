import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/upload',
      name: 'upload',
      component: () => import('../views/UploadView.vue'),
    },
    {
      path: '/review',
      name: 'review',
      component: () => import('../views/ReviewView.vue'),
    },
    {
      path: '/review/:id',
      name: 'review-detail',
      component: () => import('../views/ReviewDetailView.vue'),
    },
    {
      path: '/teacher/register',
      name: 'teacher-register',
      component: () => import('../views/TeacherRegisterView.vue'),
    },
  ],
})

export default router
