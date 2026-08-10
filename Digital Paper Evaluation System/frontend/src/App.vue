<script setup>
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <header class="site-header">
    <div class="brand">
      <RouterLink to="/">📝 Paper Evaluator</RouterLink>
    </div>
    <nav>
      <RouterLink to="/">Home</RouterLink>
      <RouterLink to="/upload">Center Upload</RouterLink>
      <RouterLink to="/review">Teacher Review</RouterLink>
      <RouterLink to="/teacher/register">Teacher Registration</RouterLink>
    </nav>
  </header>

  <main class="site-main">
    <RouterView />
  </main>
</template>

<style scoped>
.site-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 2rem;
  border-bottom: 1px solid var(--color-border);
}

.brand a {
  font-size: 1.25rem;
  font-weight: 700;
  text-decoration: none;
  color: var(--color-heading);
}

nav {
  display: flex;
  gap: 1.5rem;
}

nav a {
  text-decoration: none;
  color: var(--color-text);
  padding: 0.4rem 0.2rem;
  border-bottom: 2px solid transparent;
}

nav a.router-link-exact-active {
  border-bottom-color: hsla(160, 100%, 37%, 1);
  color: hsla(160, 100%, 37%, 1);
}

.site-main {
  max-width: 1400px;
  margin: 0 auto;
  padding: 2rem;
}
</style>
