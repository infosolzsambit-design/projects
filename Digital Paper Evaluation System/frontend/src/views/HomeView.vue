<script setup>
</script>

<template>
  <section class="hero">
    <h1>Digital Paper Evaluation</h1>
    <p>A demo workflow for uploading exam papers and evaluating them online.</p>

    <div class="cards">
      <RouterLink to="/upload" class="card">
        <h2>Center Upload</h2>
        <p>Admin side &mdash; upload the scanned paper bunch (PDFs) and the QR-to-student Excel mapping.</p>
      </RouterLink>

      <RouterLink to="/review" class="card">
        <h2>Teacher Review</h2>
        <p>Teacher side &mdash; mark papers by QR / Serial number only, no student identity shown.</p>
      </RouterLink>

      <RouterLink to="/teacher/register" class="card">
        <h2>Teacher Registration</h2>
        <p>One-time setup &mdash; register your name and a face photo, used to verify your identity before checking papers.</p>
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.hero {
  text-align: center;
}

.hero p {
  color: var(--color-text);
  opacity: 0.8;
  margin-bottom: 2.5rem;
}

.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
}

.card {
  display: block;
  padding: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  text-decoration: none;
  color: var(--color-text);
  transition: 0.2s;
}

.card:hover {
  border-color: hsla(160, 100%, 37%, 1);
  transform: translateY(-2px);
}

.card h2 {
  color: var(--color-heading);
  margin-bottom: 0.5rem;
}

@media (max-width: 640px) {
  .cards {
    grid-template-columns: 1fr;
  }
}
</style>
