<script setup>
import { ref } from 'vue'
import * as XLSX from 'xlsx'
import { usePapersStore } from '../stores/papers'
import { loadPdf } from '../utils/pdf'

const papersStore = usePapersStore()
const excelError = ref('')
const pdfBusy = ref(false)

async function onPdfChange(event) {
  const files = Array.from(event.target.files || [])
  event.target.value = ''
  if (!files.length) return

  pdfBusy.value = true
  const added = papersStore.addPapers(files)

  for (const paper of added) {
    try {
      const pdf = await loadPdf(paper.fileUrl)
      papersStore.setPageCount(paper.id, pdf.numPages)
    } catch (err) {
      console.error('Failed to load PDF', paper.fileName, err)
      papersStore.setPageCount(paper.id, 0)
      papersStore.setPdfError(paper.id, err?.message || String(err))
    }
  }
  pdfBusy.value = false
}

function findColumn(keys, needle) {
  return keys.find((k) => k.toLowerCase().includes(needle))
}

async function onExcelChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  excelError.value = ''
  try {
    const data = await file.arrayBuffer()
    const workbook = XLSX.read(data, { type: 'array' })
    const sheet = workbook.Sheets[workbook.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json(sheet, { defval: '' })

    const mapped = rows
      .map((row) => {
        const keys = Object.keys(row)
        const serialKey = findColumn(keys, 'serial') || findColumn(keys, 'qr') || keys[0]
        const nameKey = findColumn(keys, 'name')
        const rollKey = findColumn(keys, 'roll')
        const subjectKey = findColumn(keys, 'subject')

        return {
          serial: String(row[serialKey] ?? '').trim(),
          name: nameKey ? String(row[nameKey]).trim() : '',
          roll: rollKey ? String(row[rollKey]).trim() : '',
          subject: subjectKey ? String(row[subjectKey]).trim() : '',
        }
      })
      .filter((row) => row.serial)

    if (!mapped.length) {
      excelError.value = 'No valid rows found. Make sure the sheet has a "Serial No" column.'
      return
    }

    papersStore.setStudentMap(mapped)
  } catch {
    excelError.value = 'Could not read this file. Please upload a valid .xlsx file.'
  }
}

function downloadSampleTemplate() {
  const rows = papersStore.papers.length
    ? papersStore.papers.map((p, i) => ({
        'Serial No': p.id,
        'Student Name': `Student ${i + 1}`,
        'Roll No': `R${1000 + i}`,
        Subject: 'Mathematics',
      }))
    : [
        { 'Serial No': '0001', 'Student Name': 'Rahul Sharma', 'Roll No': 'R1001', Subject: 'Mathematics' },
        { 'Serial No': '0002', 'Student Name': 'Priya Verma', 'Roll No': 'R1002', Subject: 'Physics' },
      ]

  const worksheet = XLSX.utils.json_to_sheet(rows)
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Mapping')
  XLSX.writeFile(workbook, 'student-mapping-sample.xlsx')
}
</script>

<template>
  <section>
    <h1>Center Upload</h1>
    <p class="hint">
      Upload the scanned answer-script bunch and the QR-to-student mapping. The Teacher Review
      screen only ever shows the QR / Serial number below &mdash; never the student's name.
    </p>

    <div class="upload-grid">
      <div class="upload-card">
        <h2>1. Upload Paper Bunch (PDFs)</h2>
        <p class="card-desc">
          Select multiple PDF files &mdash; each PDF is one student's scanned answer script and
          can have multiple pages. Each file is auto-assigned a QR / Serial number on upload.
        </p>
        <input type="file" accept="application/pdf" multiple @change="onPdfChange" />
        <p v-if="pdfBusy" class="status-msg">Reading PDF page counts&hellip;</p>

        <table v-if="papersStore.papers.length" class="mini-table">
          <thead>
            <tr>
              <th>QR / Serial</th>
              <th>File</th>
              <th>Pages</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="paper in papersStore.papers" :key="paper.id">
              <td><code>{{ paper.id }}</code></td>
              <td>{{ paper.fileName }}</td>
              <td>
                <span v-if="paper.pdfError" class="error-msg" :title="paper.pdfError">⚠ failed to load</span>
                <span v-else>{{ paper.pageCount ?? '…' }}</span>
              </td>
              <td>
                <button class="link-btn" @click="papersStore.removePaper(paper.id)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="upload-card">
        <h2>2. Upload Student Mapping (Excel)</h2>
        <p class="card-desc">
          Columns expected: <code>Serial No</code>, <code>Student Name</code>, <code>Roll No</code>,
          <code>Subject</code>.
          <button class="link-btn" @click="downloadSampleTemplate">Download sample template</button>
        </p>
        <input type="file" accept=".xlsx,.xls" @change="onExcelChange" />
        <p v-if="excelError" class="error-msg">{{ excelError }}</p>

        <table v-if="papersStore.studentMap.length" class="mini-table">
          <thead>
            <tr>
              <th>Serial</th>
              <th>Name</th>
              <th>Roll</th>
              <th>Subject</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in papersStore.studentMap" :key="row.serial">
              <td><code>{{ row.serial }}</code></td>
              <td>{{ row.name }}</td>
              <td>{{ row.roll }}</td>
              <td>{{ row.subject }}</td>
            </tr>
          </tbody>
        </table>

        <p class="admin-note">
          ⚠️ This mapping is visible on this Center Upload screen only. It is never sent to, or
          shown on, the Teacher Review screen.
        </p>
      </div>
    </div>

    <RouterLink v-if="papersStore.papers.length" to="/review" class="cta">
      Go to Teacher Review &rarr;
    </RouterLink>
  </section>
</template>

<style scoped>
.hint {
  color: var(--color-text);
  opacity: 0.8;
  max-width: 760px;
  margin-bottom: 2rem;
}

.upload-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
}

.upload-card {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 1.5rem;
}

.upload-card h2 {
  color: var(--color-heading);
  margin-bottom: 0.5rem;
  font-size: 1.1rem;
}

.card-desc {
  color: var(--color-text);
  opacity: 0.75;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}

.card-desc code {
  background: var(--color-background-soft);
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
}

.status-msg {
  margin-top: 0.6rem;
  font-size: 0.85rem;
  opacity: 0.7;
}

.error-msg {
  margin-top: 0.6rem;
  font-size: 0.85rem;
  color: #d33;
}

.mini-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 1.2rem;
  font-size: 0.9rem;
}

.mini-table th {
  text-align: left;
  padding: 0.4rem;
  border-bottom: 2px solid var(--color-border);
  color: var(--color-heading);
}

.mini-table td {
  padding: 0.4rem;
  border-bottom: 1px solid var(--color-border);
}

.mini-table code {
  background: var(--color-background-soft);
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
}

.link-btn {
  background: none;
  border: none;
  color: hsla(160, 100%, 37%, 1);
  cursor: pointer;
  font-size: 0.85rem;
  text-decoration: underline;
  padding: 0;
}

.admin-note {
  margin-top: 1rem;
  font-size: 0.82rem;
  opacity: 0.7;
}

.cta {
  display: inline-block;
  margin-top: 2rem;
  padding: 0.6rem 1.5rem;
  border-radius: 6px;
  background: hsla(160, 100%, 37%, 1);
  color: white;
  font-weight: 600;
  text-decoration: none;
}

.cta:hover {
  opacity: 0.9;
}

@media (max-width: 780px) {
  .upload-grid {
    grid-template-columns: 1fr;
  }
}
</style>
