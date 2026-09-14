<script setup>
import { nextTick, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import api from '../utils/api'
import { useToast } from '../composables/useToast'

const router = useRouter()
const toast = useToast()

// --- CSV template download.
function downloadTemplate() {
  const sample = [{ Name: 'Demo Student', 'Roll No': 'ROLL001', Semester: 1, Program: '' }]
  const worksheet = XLSX.utils.json_to_sheet(sample)
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Students')
  XLSX.writeFile(workbook, 'student-bulk-upload-template.csv', { bookType: 'csv' })
}

// --- CSV upload + parse.
const fileError = ref('')
const parsing = ref(false)

function findColumn(keys, needle) {
  return keys.find((k) => k.toLowerCase().replace(/[^a-z]/g, '').includes(needle))
}

function makeRow(raw) {
  const keys = Object.keys(raw)
  const nameKey = findColumn(keys, 'name')
  const rollNoKey = findColumn(keys, 'rollno')
  const semesterKey = findColumn(keys, 'semester')
  const programKey = findColumn(keys, 'program')

  return reactive({
    name: nameKey ? String(raw[nameKey] ?? '').trim() : '',
    roll_no: rollNoKey ? String(raw[rollNoKey] ?? '').trim() : '',
    semester: semesterKey ? String(raw[semesterKey] ?? '').trim() : '',
    program_name: programKey ? String(raw[programKey] ?? '').trim() : '',
    errors: {},
    valid: null,
  })
}

const rows = ref([])
const modalOpen = ref(false)

async function onFileChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  fileError.value = ''
  parsing.value = true
  try {
    const data = await file.arrayBuffer()
    const workbook = XLSX.read(data, { type: 'array' })
    const sheet = workbook.Sheets[workbook.SheetNames[0]]
    const raw = XLSX.utils.sheet_to_json(sheet, { defval: '' })

    if (!raw.length) {
      fileError.value = 'This file has no data rows.'
      return
    }
    if (raw.length > 500) {
      fileError.value = `This file has ${raw.length} rows — please upload 500 or fewer at a time.`
      return
    }

    rows.value = raw.map(makeRow)
    modalOpen.value = true
  } catch {
    fileError.value = 'Could not read this file. Please upload a valid .csv file.'
  } finally {
    parsing.value = false
  }
}

// --- Modal: validate / edit / submit.
const validating = ref(false)
const submitting = ref(false)
const hasValidatedOnce = ref(false)
const allValid = ref(false)
const modalError = ref('')

const fieldRefs = reactive({})
function setFieldRef(index, field, el) {
  if (!fieldRefs[index]) fieldRefs[index] = {}
  fieldRefs[index][field] = el
}
const ROW_FIELD_ORDER = ['name', 'roll_no', 'semester', 'program_name']

function focusFirstError() {
  const rowIndex = rows.value.findIndex((r) => r.valid === false)
  if (rowIndex === -1) return
  const field = ROW_FIELD_ORDER.find((f) => rows.value[rowIndex].errors[f])
  fieldRefs[rowIndex]?.[field]?.focus?.()
}

function markEdited(row, field) {
  if (field) row.errors[field] = ''
  row.valid = null
  allValid.value = false
}

function removeRow(index) {
  rows.value.splice(index, 1)
  allValid.value = false
  if (!rows.value.length) modalOpen.value = false
}

function toPayload() {
  return rows.value.map((r) => ({ name: r.name, roll_no: r.roll_no, semester: r.semester, program_name: r.program_name }))
}

function applyResults(results) {
  results.forEach((result, i) => {
    if (!rows.value[i]) return
    rows.value[i].errors = result.errors
    rows.value[i].valid = result.valid
  })
}

async function runValidate() {
  validating.value = true
  modalError.value = ''
  try {
    const res = await api.post('/students/bulk/validate', { rows: toPayload() })
    applyResults(res.data.data.rows)
    allValid.value = res.data.data.all_valid
    hasValidatedOnce.value = true
    if (!allValid.value) await nextTick().then(focusFirstError)
  } catch (err) {
    modalError.value = err.response?.data?.message || 'Could not validate these rows.'
  } finally {
    validating.value = false
  }
}

async function submitRows() {
  submitting.value = true
  modalError.value = ''
  try {
    const count = rows.value.length
    await api.post('/students/bulk/store', { rows: toPayload() })
    router.push({ name: 'students' })
    toast.success(`${count} student${count === 1 ? '' : 's'} imported successfully.`)
  } catch (err) {
    const data = err.response?.data
    if (data?.errors?.rows) {
      applyResults(data.errors.rows)
      allValid.value = false
      await nextTick().then(focusFirstError)
    }
    modalError.value = data?.message || 'Could not import these students.'
  } finally {
    submitting.value = false
  }
}

function closeModal() {
  modalOpen.value = false
  rows.value = []
  hasValidatedOnce.value = false
  allValid.value = false
  modalError.value = ''
}

function cancel() {
  router.push({ name: 'students' })
}
</script>

<template>
  <div>
    <div class="max-w-[900px] mx-auto">
      <!-- Page header, matching the rest of the app's icon-badge + title header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="17 8 12 3 7 8" />
                <line x1="12" y1="3" x2="12" y2="15" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Bulk Upload Students</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">Create many students at once from a CSV file.</p>
          </div>
        </div>
        <button
          type="button"
          class="mt-0.5 shrink-0 h-8 inline-flex items-center gap-1.5 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
          @click="cancel"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
          Back
        </button>
      </div>

      <div class="space-y-4">
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">1. Download the CSV template</h2>
          <p class="text-[13px] text-muted mb-3">
            Columns: <span class="font-medium text-gray-700">Name, Roll No, Semester, Program</span>. Name, Roll No, and Semester are required — Roll No must be unique, and Program is optional but must exactly match an existing program's name when given.
          </p>
          <button
            type="button"
            class="h-10 inline-flex items-center gap-2 rounded-xl border border-input-border bg-white text-sm font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
            @click="downloadTemplate"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
            Download CSV Template
          </button>
        </section>

        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">2. Upload your filled CSV</h2>
          <p class="text-[13px] text-muted mb-3">Your data will open in a review screen where you can check, fix, and confirm it before anything is created.</p>

          <label
            class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-input-border bg-page-bg/60 px-4 py-8 text-center cursor-pointer hover:border-brand-blue transition-colors"
            :class="{ 'opacity-60 pointer-events-none': parsing }"
          >
            <svg class="w-8 h-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
            <span class="text-sm font-medium text-gray-700">{{ parsing ? 'Reading file…' : 'Click to choose a .csv file' }}</span>
            <span class="text-[12px] text-muted">Up to 500 rows per upload.</span>
            <input type="file" accept=".csv" class="hidden" :disabled="parsing" @change="onFileChange" />
          </label>
          <p v-if="fileError" class="text-[12px] text-brand mt-2">{{ fileError }}</p>
        </section>
      </div>
    </div>

    <!-- Review modal -->
    <div v-if="modalOpen" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="closeModal">
      <div class="bg-white rounded-[24px] shadow-card w-full max-w-3xl max-h-[92vh] flex flex-col overflow-hidden">
        <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-soft">
          <div>
            <h2 class="text-lg font-bold text-black">Review Uploaded Students</h2>
            <p class="text-[13px] text-muted mt-0.5">
              {{ rows.length }} row{{ rows.length === 1 ? '' : 's' }}.
              <span v-if="hasValidatedOnce">{{ rows.filter((r) => r.valid === true).length }} valid, {{ rows.filter((r) => r.valid === false).length }} need attention.</span>
              Edit any cell, then Validate.
            </p>
          </div>
          <button type="button" class="text-gray-400 hover:text-gray-700 transition-colors" aria-label="Close" @click="closeModal">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
          </button>
        </div>

        <div class="flex-1 overflow-y-auto overflow-x-hidden px-5 py-3">
          <div class="overflow-x-auto">
            <table class="w-full min-w-[540px] text-left border-separate border-spacing-0">
              <thead>
                <tr class="bg-subject-header text-white text-[11px] font-medium">
                  <th class="px-2 py-1.5 rounded-tl-xl">#</th>
                  <th class="px-2 py-1.5 min-w-[180px]">Name</th>
                  <th class="px-2 py-1.5 min-w-[120px]">Roll No</th>
                  <th class="px-2 py-1.5 min-w-[100px]">Semester</th>
                  <th class="px-2 py-1.5 min-w-[160px]">Program</th>
                  <th class="px-2 py-1.5 text-center min-w-[80px]">Status</th>
                  <th class="px-2 py-1.5 rounded-tr-xl w-8"></th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <tr v-for="(row, index) in rows" :key="index" class="align-top text-[12px] even:bg-gray-50">
                  <td class="px-2 py-1.5 text-muted">{{ index + 1 }}</td>
                  <td class="px-2 py-1.5">
                    <input
                      :ref="(el) => setFieldRef(index, 'name', el)"
                      v-model="row.name"
                      type="text"
                      class="w-full h-8 px-2 rounded-lg bg-input-bg text-[12px] outline-none border"
                      :class="row.errors.name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                      @input="markEdited(row, 'name')"
                    />
                    <p v-if="row.errors.name" class="text-[10px] text-brand mt-0.5">{{ row.errors.name }}</p>
                  </td>
                  <td class="px-2 py-1.5">
                    <input
                      :ref="(el) => setFieldRef(index, 'roll_no', el)"
                      v-model="row.roll_no"
                      type="text"
                      class="w-full h-8 px-2 rounded-lg bg-input-bg text-[12px] outline-none border"
                      :class="row.errors.roll_no ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                      @input="markEdited(row, 'roll_no')"
                    />
                    <p v-if="row.errors.roll_no" class="text-[10px] text-brand mt-0.5">{{ row.errors.roll_no }}</p>
                  </td>
                  <td class="px-2 py-1.5">
                    <input
                      :ref="(el) => setFieldRef(index, 'semester', el)"
                      v-model="row.semester"
                      type="text"
                      class="w-full h-8 px-2 rounded-lg bg-input-bg text-[12px] outline-none border"
                      :class="row.errors.semester ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                      @input="markEdited(row, 'semester')"
                    />
                    <p v-if="row.errors.semester" class="text-[10px] text-brand mt-0.5">{{ row.errors.semester }}</p>
                  </td>
                  <td class="px-2 py-1.5">
                    <input
                      :ref="(el) => setFieldRef(index, 'program_name', el)"
                      v-model="row.program_name"
                      type="text"
                      placeholder="Optional"
                      class="w-full h-8 px-2 rounded-lg bg-input-bg text-[12px] outline-none border"
                      :class="row.errors.program_name ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                      @input="markEdited(row, 'program_name')"
                    />
                    <p v-if="row.errors.program_name" class="text-[10px] text-brand mt-0.5">{{ row.errors.program_name }}</p>
                  </td>
                  <td class="px-2 py-1.5 text-center">
                    <span v-if="row.valid === true" class="inline-flex items-center gap-1 text-[10px] font-medium text-green-700 bg-green-100 rounded-full px-1.5 py-0.5">
                      <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12" /></svg>
                      Valid
                    </span>
                    <span v-else-if="row.valid === false" class="inline-flex items-center gap-1 text-[10px] font-medium text-brand bg-brand/10 rounded-full px-1.5 py-0.5">
                      <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                      Fix
                    </span>
                    <span v-else class="text-[10px] text-muted">&mdash;</span>
                  </td>
                  <td class="px-2 py-1.5 text-center">
                    <button
                      type="button"
                      class="w-6 h-6 rounded-full hover:bg-gray-100 text-gray-400 hover:text-brand transition-colors inline-flex items-center justify-center"
                      aria-label="Remove row"
                      @click="removeRow(index)"
                    >
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6M14 11v6" /></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="px-5 py-4 border-t border-soft flex flex-wrap items-center justify-between gap-3">
          <p v-if="modalError" class="text-[13px] text-brand">{{ modalError }}</p>
          <p v-else-if="hasValidatedOnce && allValid" class="text-[13px] text-green-700 font-medium">All rows look good — ready to submit.</p>
          <span v-else></span>

          <div class="flex flex-wrap gap-3">
            <button
              type="button"
              class="min-w-[110px] px-5 py-2.5 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="validating || submitting"
              @click="closeModal"
            >
              Cancel
            </button>
            <button
              type="button"
              class="min-w-[110px] px-5 py-2.5 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="validating || submitting || !rows.length"
              @click="runValidate"
            >
              {{ validating ? 'Validating…' : 'Validate' }}
            </button>
            <button
              type="button"
              class="min-w-[140px] px-6 py-2.5 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="!allValid || !hasValidatedOnce || validating || submitting"
              @click="submitRows"
            >
              {{ submitting ? 'Submitting…' : 'Submit' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
