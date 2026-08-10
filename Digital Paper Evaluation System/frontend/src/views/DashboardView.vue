<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const router = useRouter()
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

async function logout() {
  await authStore.logout()
  router.push('/login')
}

const cards = [
  { to: '/upload', icon: '📤', title: 'Center Upload', desc: 'Upload the scanned paper bunch and the QR-to-student Excel mapping.' },
  { to: '/review', icon: '📝', title: 'Teacher Review', desc: 'Mark papers by QR / Serial number only — no student identity shown.' },
  { to: '/teacher/register', icon: '🪪', title: 'Teacher Registration', desc: 'One-time setup — register your name and a face photo.' },
]
</script>

<template>
  <section class="dashboard">
    <p v-if="loading" class="status-msg">Loading your dashboard&hellip;</p>
    <p v-else-if="loadError" class="status-msg error-text">{{ loadError }}</p>

    <template v-else>
      <div class="welcome-row">
        <div>
          <h1>Welcome, {{ authStore.user?.name }}</h1>
          <p class="hint">
            {{ authStore.user?.email }}
            <span v-if="authStore.user?.roles?.length"> &middot; {{ authStore.user.roles.map((r) => r.name).join(', ') }}</span>
          </p>
        </div>
        <button class="logout-btn" @click="logout">Log out</button>
      </div>

      <div class="cards">
        <RouterLink v-for="card in cards" :key="card.to" :to="card.to" class="card">
          <span class="card-icon">{{ card.icon }}</span>
          <h2>{{ card.title }}</h2>
          <p>{{ card.desc }}</p>
        </RouterLink>
      </div>
    </template>
  </section>
</template>

<style scoped>
.status-msg {
  text-align: center;
  opacity: 0.7;
  margin-top: 3rem;
}

.error-text {
  color: #e57373;
}

.welcome-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 2.5rem;
  flex-wrap: wrap;
}

.welcome-row h1 {
  font-size: 1.5rem;
  color: var(--color-heading);
}

.hint {
  color: var(--color-text);
  opacity: 0.7;
  margin-top: 0.3rem;
  font-size: 0.9rem;
}

.logout-btn {
  padding: 0.5rem 1.2rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  font-weight: 600;
  cursor: pointer;
}

.logout-btn:hover {
  border-color: hsla(0, 70%, 50%, 0.5);
  color: hsl(0, 70%, 55%);
}

.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
}

.card {
  display: block;
  padding: 1.75rem;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  text-decoration: none;
  color: var(--color-text);
  transition: 0.2s;
}

.card:hover {
  border-color: hsla(160, 100%, 37%, 1);
  transform: translateY(-2px);
}

.card-icon {
  font-size: 1.5rem;
}

.card h2 {
  color: var(--color-heading);
  font-size: 1.05rem;
  margin: 0.5rem 0 0.4rem;
}

.card p {
  font-size: 0.88rem;
  opacity: 0.75;
}
</style>
