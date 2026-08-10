<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import api from '../../utils/api'

const programs = ref([])
const loading = ref(true)
const loadError = ref('')

const search = ref('')
const statusFilter = ref('') // '' | 'yes' | 'no'

const pagination = reactive({ current_page: 1, per_page: 20, total: 0, last_page: 1 })

const showModal = ref(false)
const editingProgram = ref(null) // null = create mode
const form = reactive({ name: '', department: '', code: '', status: true })
const formError = ref('')
const saving = ref(false)
const deletingId = ref(null)

async function fetchPrograms(page = 1) {
  loading.value = true
  loadError.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    if (search.value) params.search = search.value
    if (statusFilter.value !== '') params.is_active = statusFilter.value

    const res = await api.get('/programs', { params })
    programs.value = res.data.data.items
    Object.assign(pagination, res.data.data.pagination)
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not load programs.'
  } finally {
    loading.value = false
  }
}

let searchDebounce = null
watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => fetchPrograms(1), 350)
})
watch(statusFilter, () => fetchPrograms(1))

function openCreate() {
  editingProgram.value = null
  form.name = ''
  form.department = ''
  form.code = ''
  form.status = true
  formError.value = ''
  showModal.value = true
}

function openEdit(program) {
  editingProgram.value = program
  form.name = program.name
  form.department = program.department
  form.code = program.code
  form.status = program.status
  formError.value = ''
  showModal.value = true
}

function closeModal() {
  if (saving.value) return
  showModal.value = false
}

async function submitForm() {
  saving.value = true
  formError.value = ''
  try {
    if (editingProgram.value) {
      await api.put(`/programs/${editingProgram.value.id}`, form)
    } else {
      await api.post('/programs', form)
    }
    showModal.value = false
    await fetchPrograms(pagination.current_page)
  } catch (err) {
    formError.value = err.response?.data?.message || 'Could not save program.'
  } finally {
    saving.value = false
  }
}

async function removeProgram(program) {
  if (!confirm(`Delete program "${program.name}"? This can be undone by an admin later.`)) return
  deletingId.value = program.id
  try {
    await api.delete(`/programs/${program.id}`)
    if (programs.value.length === 1 && pagination.current_page > 1) {
      await fetchPrograms(pagination.current_page - 1)
    } else {
      await fetchPrograms(pagination.current_page)
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Could not delete program.'
  } finally {
    deletingId.value = null
  }
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchPrograms(page)
}

onMounted(() => fetchPrograms(1))
</script>

<template>
  <section class="programs-page">
    <div class="page-header">
      <div>
        <h1>Programs</h1>
        <p class="hint">Master data &mdash; manage the list of programs available across the system.</p>
      </div>
      <button class="primary-btn" @click="openCreate">+ Add Program</button>
    </div>

    <div class="toolbar">
      <input v-model="search" type="text" placeholder="Search by name, department or code…" class="search-input" />
      <select v-model="statusFilter" class="status-select">
        <option value="">All Status</option>
        <option value="yes">Active</option>
        <option value="no">Inactive</option>
      </select>
    </div>

    <p v-if="loadError" class="error-banner">{{ loadError }}</p>

    <div class="table-card">
      <table class="programs-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Department</th>
            <th>Code</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="5" class="empty-cell">Loading&hellip;</td>
          </tr>
          <tr v-else-if="!programs.length">
            <td colspan="5" class="empty-cell">No programs found.</td>
          </tr>
          <tr v-for="program in programs" :key="program.id" v-else>
            <td>{{ program.name }}</td>
            <td>{{ program.department }}</td>
            <td><code>{{ program.code }}</code></td>
            <td>
              <span class="badge" :class="program.status ? 'active' : 'inactive'">
                {{ program.status ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="actions-cell">
              <button class="link-btn" @click="openEdit(program)">Edit</button>
              <button
                class="link-btn danger"
                :disabled="deletingId === program.id"
                @click="removeProgram(program)"
              >
                {{ deletingId === program.id ? 'Deleting…' : 'Delete' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="pagination.last_page > 1" class="pagination">
      <button :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">
        &larr; Prev
      </button>
      <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} &middot; {{ pagination.total }} total</span>
      <button :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">
        Next &rarr;
      </button>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
      <div class="modal-card">
        <h2>{{ editingProgram ? 'Edit Program' : 'Add Program' }}</h2>

        <form @submit.prevent="submitForm">
          <label>
            Name
            <input v-model="form.name" type="text" required autofocus />
          </label>

          <label>
            Department
            <input v-model="form.department" type="text" required />
          </label>

          <label>
            Code
            <input v-model="form.code" type="text" required />
          </label>

          <label class="status-toggle">
            <input v-model="form.status" type="checkbox" />
            Active
          </label>

          <p v-if="formError" class="error-msg">{{ formError }}</p>

          <div class="modal-actions">
            <button type="button" class="secondary-btn" :disabled="saving" @click="closeModal">Cancel</button>
            <button type="submit" class="primary-btn" :disabled="saving">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.75rem;
  flex-wrap: wrap;
}

.page-header h1 {
  font-size: 1.5rem;
  color: var(--color-heading);
}

.hint {
  color: var(--color-text);
  opacity: 0.7;
  font-size: 0.9rem;
  margin-top: 0.3rem;
}

.primary-btn {
  padding: 0.6rem 1.3rem;
  border: none;
  border-radius: 8px;
  background: hsla(160, 100%, 37%, 1);
  color: white;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.primary-btn:hover:not(:disabled) {
  opacity: 0.9;
}

.primary-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.toolbar {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.search-input {
  flex: 1;
  min-width: 220px;
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

.status-select {
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

.error-banner {
  color: #e57373;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}

.table-card {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  overflow: hidden;
}

.programs-table {
  width: 100%;
  border-collapse: collapse;
}

.programs-table th {
  text-align: left;
  padding: 0.75rem 1rem;
  background: var(--color-background-soft);
  color: var(--color-heading);
  font-size: 0.85rem;
  border-bottom: 1px solid var(--color-border);
}

.programs-table td {
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.92rem;
}

.programs-table tr:last-child td {
  border-bottom: none;
}

.programs-table code {
  background: var(--color-background-soft);
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
}

.empty-cell {
  text-align: center;
  padding: 2rem;
  opacity: 0.6;
}

.badge {
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 600;
}

.badge.active {
  background: hsla(160, 100%, 37%, 0.15);
  color: hsla(160, 100%, 30%, 1);
}

.badge.inactive {
  background: hsla(0, 70%, 50%, 0.12);
  color: hsl(0, 70%, 45%);
}

.actions-cell {
  text-align: right;
  white-space: nowrap;
}

.link-btn {
  border: none;
  background: transparent;
  color: hsla(160, 100%, 37%, 1);
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
  padding: 0.2rem 0.5rem;
}

.link-btn.danger {
  color: hsl(0, 70%, 50%);
}

.link-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1.25rem;
  font-size: 0.85rem;
}

.pagination button {
  padding: 0.4rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-background-soft);
  color: var(--color-text);
  cursor: pointer;
}

.pagination button:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.modal-card {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 1.75rem;
  width: 400px;
  max-width: 90vw;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
}

.modal-card h2 {
  color: var(--color-heading);
  font-size: 1.15rem;
  margin-bottom: 1.25rem;
}

.modal-card form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal-card label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-weight: 600;
  color: var(--color-heading);
  font-size: 0.88rem;
}

.modal-card input[type='text'] {
  font-weight: normal;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-background-soft);
  color: var(--color-text);
}

.status-toggle {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem !important;
}

.error-msg {
  color: #e57373;
  font-size: 0.85rem;
  margin: 0;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 0.25rem;
}

.secondary-btn {
  padding: 0.6rem 1.2rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: transparent;
  color: var(--color-text);
  font-weight: 600;
  cursor: pointer;
}

.secondary-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
