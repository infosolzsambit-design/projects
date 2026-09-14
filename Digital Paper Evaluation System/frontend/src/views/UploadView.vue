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
  <div>
    <!-- Page header -->
    <div class="flex items-start gap-3 mb-4">
      <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
        <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
          <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M12 3v12M12 3l-4 4M12 3l4 4" />
            <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
          </svg>
        </span>
      </span>
      <div>
        <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Center Upload</h1>
        <p class="mt-1 text-[13px] sm:text-sm text-muted max-w-2xl">
          Upload the scanned answer-script bunch and the QR-to-student mapping. The Teacher Review
          screen only ever shows the QR / Serial number below — never the student's name.
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      <!-- 1. PDFs -->
      <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
        <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">1. Upload Paper Bunch (PDFs)</h2>
        <p class="text-[13px] text-muted mb-3">
          Select multiple PDF files — each PDF is one student's scanned answer script and can have
          multiple pages. Each file is auto-assigned a QR / Serial number on upload.
        </p>

        <label class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-input-border bg-page-bg/60 px-4 py-8 text-center cursor-pointer hover:border-brand-blue transition-colors">
          <svg class="w-8 h-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          <span class="text-sm font-medium text-gray-700">Click to choose PDF files</span>
          <span class="text-[12px] text-muted">You can select several at once</span>
          <input type="file" accept="application/pdf" multiple class="hidden" @change="onPdfChange" />
        </label>
        <p v-if="pdfBusy" class="text-[12px] text-muted mt-2">Reading PDF page counts&hellip;</p>

        <div v-if="papersStore.papers.length" class="mt-4 overflow-x-auto rounded-xl border border-soft">
          <table class="w-full text-left text-[13px]">
            <thead>
              <tr class="bg-subject-header text-white">
                <th class="px-3 py-2 font-medium">QR / Serial</th>
                <th class="px-3 py-2 font-medium">File</th>
                <th class="px-3 py-2 font-medium">Pages</th>
                <th class="px-3 py-2 font-medium"></th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-for="paper in papersStore.papers" :key="paper.id" class="border-b border-gray-100 last:border-b-0 even:bg-gray-50">
                <td class="px-3 py-2"><code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">{{ paper.id }}</code></td>
                <td class="px-3 py-2 truncate max-w-[180px]">{{ paper.fileName }}</td>
                <td class="px-3 py-2">
                  <span v-if="paper.pdfError" class="text-brand text-[12px]" :title="paper.pdfError">⚠ failed to load</span>
                  <span v-else>{{ paper.pageCount ?? '…' }}</span>
                </td>
                <td class="px-3 py-2 text-right">
                  <button type="button" class="text-[12px] font-semibold text-brand hover:underline" @click="papersStore.removePaper(paper.id)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 2. Excel -->
      <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
        <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">2. Upload Student Mapping (Excel)</h2>
        <p class="text-[13px] text-muted mb-3">
          Columns expected:
          <code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">Serial No</code>,
          <code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">Student Name</code>,
          <code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">Roll No</code>,
          <code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">Subject</code>.
        </p>

        <button
          type="button"
          class="h-9 inline-flex items-center gap-2 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors mb-3"
          @click="downloadSampleTemplate"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
          Download Sample Template
        </button>

        <label class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-input-border bg-page-bg/60 px-4 py-8 text-center cursor-pointer hover:border-brand-blue transition-colors">
          <svg class="w-8 h-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
          <span class="text-sm font-medium text-gray-700">Click to choose an .xlsx file</span>
          <input type="file" accept=".xlsx,.xls" class="hidden" @change="onExcelChange" />
        </label>
        <p v-if="excelError" class="text-[12px] text-brand mt-2">{{ excelError }}</p>

        <div v-if="papersStore.studentMap.length" class="mt-4 overflow-x-auto rounded-xl border border-soft">
          <table class="w-full text-left text-[13px]">
            <thead>
              <tr class="bg-subject-header text-white">
                <th class="px-3 py-2 font-medium">Serial</th>
                <th class="px-3 py-2 font-medium">Name</th>
                <th class="px-3 py-2 font-medium">Roll</th>
                <th class="px-3 py-2 font-medium">Subject</th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <tr v-for="row in papersStore.studentMap" :key="row.serial" class="border-b border-gray-100 last:border-b-0 even:bg-gray-50">
                <td class="px-3 py-2"><code class="bg-input-bg rounded px-1.5 py-0.5 text-[12px]">{{ row.serial }}</code></td>
                <td class="px-3 py-2">{{ row.name }}</td>
                <td class="px-3 py-2">{{ row.roll }}</td>
                <td class="px-3 py-2">{{ row.subject }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 rounded-xl bg-soft border border-chip-border px-3.5 py-2.5 text-[12px] text-brand-blue flex items-start gap-2">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
          <span>This mapping is visible on this Center Upload screen only. It is never sent to, or shown on, the Teacher Review screen.</span>
        </div>
      </section>
    </div>

    <div v-if="papersStore.papers.length" class="flex justify-end mt-4">
      <RouterLink
        to="/review"
        class="h-11 inline-flex items-center gap-2 rounded-xl bg-btn-gradient text-white text-sm font-semibold px-6 hover:opacity-90 transition-opacity"
      >
        Go to Teacher Review
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
      </RouterLink>
    </div>
  </div>
</template>
