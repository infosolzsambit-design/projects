<script setup>
import { usePapersStore } from '../stores/papers'

// This screen is teacher-facing: it must never read papersStore.studentMap.
// Papers are identified only by their QR / Serial number.
const papersStore = usePapersStore()

function totalMarks(paper) {
  return Object.values(paper.marks).reduce((sum, v) => sum + (Number(v) || 0), 0)
}

function formatDuration(seconds) {
  if (seconds == null) return '—'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}
</script>

<template>
  <section>
    <h1>Teacher Review</h1>
    <p class="hint">
      Papers are identified by QR / Serial number only. Student identity is not available on this
      screen.
    </p>

    <div v-if="!papersStore.papers.length" class="empty-state">
      <p>No papers uploaded yet.</p>
      <RouterLink to="/upload">Go to Center Upload &rarr;</RouterLink>
    </div>

    <table v-else class="papers-table">
      <thead>
        <tr>
          <th>QR / Serial</th>
          <th>Pages</th>
          <th>Marks</th>
          <th>Time Taken</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="paper in papersStore.papers" :key="paper.id">
          <td><code>{{ paper.id }}</code></td>
          <td>{{ paper.pageCount ?? '…' }}</td>
          <td>{{ totalMarks(paper) }}</td>
          <td>{{ formatDuration(paper.checkingDurationSeconds) }}</td>
          <td>
            <span class="badge" :class="paper.status.toLowerCase()">{{ paper.status }}</span>
          </td>
          <td>
            <RouterLink :to="`/review/${paper.id}`">
              {{ paper.status === 'Reviewed' ? 'View' : 'Check Paper' }}
            </RouterLink>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.hint {
  color: var(--color-text);
  opacity: 0.7;
  margin-bottom: 1.5rem;
}

.empty-state {
  padding: 2rem;
  border: 1px dashed var(--color-border);
  border-radius: 8px;
  text-align: center;
}

.empty-state a {
  color: hsla(160, 100%, 37%, 1);
}

.papers-table {
  width: 100%;
  border-collapse: collapse;
}

.papers-table th {
  text-align: left;
  padding: 0.6rem;
  border-bottom: 2px solid var(--color-border);
  color: var(--color-heading);
}

.papers-table td {
  padding: 0.6rem;
  border-bottom: 1px solid var(--color-border);
}

.papers-table code {
  background: var(--color-background-soft);
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
}

.badge {
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.badge.pending {
  background: hsla(38, 92%, 50%, 0.15);
  color: hsl(38, 92%, 40%);
}

.badge.reviewed {
  background: hsla(160, 100%, 37%, 0.15);
  color: hsla(160, 100%, 30%, 1);
}

.papers-table a {
  color: hsla(160, 100%, 37%, 1);
  text-decoration: none;
  font-weight: 600;
}

.papers-table a:hover {
  text-decoration: underline;
}
</style>
