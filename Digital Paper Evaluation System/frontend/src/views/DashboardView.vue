<script setup>
import { onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import AdminDashboardView from '../components/dashboard/AdminDashboardView.vue'
import TeacherDashboardView from '../components/dashboard/TeacherDashboardView.vue'
import HodDashboardView from '../components/dashboard/HodDashboardView.vue'

const authStore = useAuthStore()
const loading = ref(true)
const loadError = ref('')

onMounted(async () => {
  try {
    await authStore.fetchMe()
  } catch {
    loadError.value = 'Could not load your profile. Try signing in again.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <p v-if="loading" class="text-center text-sm text-muted py-10">Loading your dashboard&hellip;</p>
  <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

  <template v-else>

    <!---------------------------------------- Admin Dashboard ---------------------------------------->
    <AdminDashboardView v-if="authStore.can('admin-dashboard')" />
    <!---------------------------------------- Admin Dashboard End ---------------------------------------->
    <!---------------------------------------- HOD Dashboard  ---------------------------------------->
    <!-- designed_files/hod_dashboard.png — static data for now (see the component). -->
    <HodDashboardView v-if="authStore.can('hod-dashboard')" />
    <!---------------------------------------- HOD Dashboard End  ---------------------------------------->
    <!---------------------------------------- Teacher Dashboard  ---------------------------------------->
    <!-- designed_files/teacher_dashboard.png — static data for now (see the component). -->
    <TeacherDashboardView v-if="authStore.can('teacher-dashboard')" />
    <!---------------------------------------- Teacher Dashboard End  ---------------------------------------->

  </template>


</template>
