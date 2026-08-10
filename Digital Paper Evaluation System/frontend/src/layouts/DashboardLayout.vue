<script setup>
import { computed, ref, watchEffect } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()

const navItems = [
  { to: '/dashboard', label: 'Dashboard', icon: '&#9635;', exact: true },
  { to: '/upload', label: 'Center Upload', icon: '&#8593;', exact: false },
  { to: '/review', label: 'Teacher Review', icon: '&#9998;', exact: false },
  { to: '/teacher/register', label: 'Teacher Registration', icon: '&#9679;', exact: false },
]

const masterItems = [
  { to: '/master/courses', label: 'Course', icon: '&#9636;' },
  { to: '/master/programs', label: 'Programs', icon: '&#9638;' },
]

function isActive(item) {
  return item.exact ? route.path === item.to : route.path.startsWith(item.to)
}

const masterOpen = ref(route.path.startsWith('/master'))
watchEffect(() => {
  if (route.path.startsWith('/master')) masterOpen.value = true
})

const initials = computed(() => {
  const name = authStore.user?.name || ''
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('') || '?'
})

async function logout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <span class="brand-mark">P</span>
        <span class="brand-name">Paper Evaluator</span>
      </div>

      <nav class="side-nav">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: isActive(item) }"
        >
          <span class="nav-icon" v-html="item.icon"></span>
          <span>{{ item.label }}</span>
        </RouterLink>

        <button
          type="button"
          class="nav-item nav-group-toggle"
          :class="{ active: route.path.startsWith('/master') }"
          @click="masterOpen = !masterOpen"
        >
          <span class="nav-icon">&#9783;</span>
          <span>Master</span>
          <span class="chevron" :class="{ open: masterOpen }">&#8250;</span>
        </button>

        <div v-show="masterOpen" class="nav-subgroup">
          <RouterLink
            v-for="item in masterItems"
            :key="item.to"
            :to="item.to"
            class="nav-item nav-subitem"
            :class="{ active: isActive(item) }"
          >
            <span class="nav-icon" v-html="item.icon"></span>
            <span>{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="sidebar-footer">
        <div class="user-card">
          <span class="avatar">{{ initials }}</span>
          <div class="user-meta">
            <span class="user-name">{{ authStore.user?.name || '…' }}</span>
            <span class="user-role" v-if="authStore.user?.roles?.length">
              {{ authStore.user.roles.map((r) => r.name).join(', ') }}
            </span>
          </div>
        </div>
        <button class="logout-btn" @click="logout">Log out</button>
      </div>
    </aside>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 260px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, #151a2e 0%, #0f1322 100%);
  color: #e7e9f5;
  padding: 1.5rem 1.1rem;
  position: sticky;
  top: 0;
  height: 100vh;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.4rem 0.5rem 1.75rem;
}

.brand-mark {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  background: hsla(160, 100%, 42%, 1);
  color: #0f1322;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
}

.brand-name {
  font-weight: 700;
  font-size: 1.02rem;
  letter-spacing: 0.01em;
}

.side-nav {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  color: rgba(231, 233, 245, 0.72);
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
  transition: background-color 0.15s, color 0.15s;
}

.nav-item:hover {
  background: rgba(255, 255, 255, 0.06);
  color: #ffffff;
}

.nav-item.active {
  background: hsla(160, 100%, 42%, 0.16);
  color: hsl(160, 85%, 68%);
  font-weight: 600;
}

.nav-icon {
  width: 1.1rem;
  text-align: center;
  font-size: 0.85rem;
  opacity: 0.9;
}

.nav-group-toggle {
  width: 100%;
  border: none;
  cursor: pointer;
  font-family: inherit;
}

.chevron {
  margin-left: auto;
  font-size: 0.85rem;
  opacity: 0.6;
  transition: transform 0.15s;
}

.chevron.open {
  transform: rotate(90deg);
}

.nav-subgroup {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  margin: 0.15rem 0 0.15rem 0.9rem;
  padding-left: 0.65rem;
  border-left: 1px solid rgba(255, 255, 255, 0.1);
}

.nav-subitem {
  font-size: 0.85rem;
  padding: 0.5rem 0.75rem;
}

.sidebar-footer {
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.user-card {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.8rem;
  flex-shrink: 0;
}

.user-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.user-name {
  font-size: 0.85rem;
  font-weight: 600;
  color: #fff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-role {
  font-size: 0.72rem;
  color: rgba(231, 233, 245, 0.55);
  text-transform: capitalize;
}

.logout-btn {
  align-self: flex-start;
  border: none;
  background: transparent;
  color: rgba(231, 233, 245, 0.6);
  font-size: 0.78rem;
  cursor: pointer;
  padding: 0.15rem 0;
}

.logout-btn:hover {
  color: #fff;
}

.content {
  flex: 1;
  min-width: 0;
  padding: 2.25rem 2.5rem;
  max-width: 1400px;
}

@media (max-width: 860px) {
  .app-shell {
    flex-direction: column;
  }

  .sidebar {
    position: relative;
    width: 100%;
    height: auto;
    flex-direction: row;
    align-items: center;
    padding: 0.75rem 1rem;
    gap: 1rem;
  }

  .brand {
    padding: 0;
  }

  .side-nav {
    flex-direction: row;
    flex: 1;
    overflow-x: auto;
  }

  .sidebar-footer {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
    flex-direction: row;
    align-items: center;
  }

  .user-meta {
    display: none;
  }

  .content {
    padding: 1.5rem;
  }
}
</style>
