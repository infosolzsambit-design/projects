<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

const login = ref('')
const password = ref('')
const status = ref('idle') // idle | loading | error
const errorMessage = ref('')

const highlights = [
  'QR-based paper identification — student identity never shown to reviewers',
  'Camera face verification before checking starts or finishes',
  'Interactive, page-by-page annotation for every submitted script',
]

async function submit() {
  status.value = 'loading'
  errorMessage.value = ''
  try {
    await authStore.login(login.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    router.push(redirect)
  } catch (err) {
    status.value = 'error'
    errorMessage.value = err.response?.data?.message || 'Could not log in. Please try again.'
  }
}
</script>

<template>
  <section class="login-page">
    <div class="brand-panel">
      <div class="brand-mark">P</div>
      <h1>Digital Paper<br />Evaluation System</h1>
      <p class="tagline">A secure, anonymized workflow for evaluating exam scripts online.</p>
      <ul class="highlights">
        <li v-for="item in highlights" :key="item">
          <span class="dot"></span>{{ item }}
        </li>
      </ul>
    </div>

    <div class="form-panel">
      <div class="login-card">
        <h2>Sign in</h2>
        <p class="hint">Use your email or username to continue.</p>

        <form @submit.prevent="submit">
          <label>
            Email or Username
            <input v-model="login" type="text" autocomplete="username" required autofocus />
          </label>

          <label>
            Password
            <input v-model="password" type="password" autocomplete="current-password" required />
          </label>

          <p v-if="status === 'error'" class="error-msg">{{ errorMessage }}</p>

          <button type="submit" :disabled="status === 'loading'">
            {{ status === 'loading' ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>
      </div>
    </div>
  </section>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
}

.brand-panel {
  background: linear-gradient(160deg, #151a2e 0%, #0f1322 100%);
  color: #e7e9f5;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 4rem;
  gap: 1.25rem;
}

.brand-mark {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: hsla(160, 100%, 42%, 1);
  color: #0f1322;
  font-weight: 800;
  font-size: 1.3rem;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 0.5rem;
}

.brand-panel h1 {
  font-size: 2rem;
  line-height: 1.25;
  font-weight: 700;
  color: #fff;
}

.tagline {
  color: rgba(231, 233, 245, 0.7);
  font-size: 1rem;
  max-width: 420px;
}

.highlights {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  margin-top: 1.25rem;
  max-width: 420px;
}

.highlights li {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  font-size: 0.88rem;
  color: rgba(231, 233, 245, 0.85);
  line-height: 1.5;
}

.dot {
  flex-shrink: 0;
  margin-top: 0.45rem;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: hsla(160, 100%, 55%, 1);
}

.form-panel {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  background: var(--color-background);
}

.login-card {
  width: 100%;
  max-width: 380px;
}

.login-card h2 {
  font-size: 1.6rem;
  color: var(--color-heading);
  margin-bottom: 0.4rem;
}

.hint {
  color: var(--color-text);
  opacity: 0.7;
  font-size: 0.9rem;
  margin-bottom: 2rem;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-weight: 600;
  color: var(--color-heading);
  font-size: 0.9rem;
}

input {
  font-weight: normal;
  font-size: 1rem;
  padding: 0.65rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

input:focus {
  outline: 2px solid hsla(160, 100%, 37%, 0.5);
  outline-offset: 1px;
}

button {
  margin-top: 0.5rem;
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 8px;
  background: hsla(160, 100%, 37%, 1);
  color: white;
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
}

button:hover:not(:disabled) {
  opacity: 0.9;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error-msg {
  color: #e57373;
  font-size: 0.85rem;
  margin: -0.4rem 0;
}

@media (max-width: 860px) {
  .login-page {
    grid-template-columns: 1fr;
  }

  .brand-panel {
    padding: 2.5rem 2rem;
    gap: 0.9rem;
  }

  .brand-panel h1 {
    font-size: 1.5rem;
  }

  .highlights {
    display: none;
  }
}
</style>
