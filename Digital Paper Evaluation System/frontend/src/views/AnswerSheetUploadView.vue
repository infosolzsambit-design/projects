<script setup>
// The exam-center flow for uploading a batch of scanned answer sheets
// against a question paper. Submit is a two-stage Check-then-Submit button
// (see submitButtonLabel below): Check runs every validation client-side
// (CSV column presence, Subject Barcode/Roll No uniqueness, row-count-vs-
// PDF-count, every PDF having a readable QR code, every barcode matching
// one) without saving anything; only once that's actually passed does the
// button become "Submit", which POSTs the packet + its rows + the matched
// PDFs to /answer-sheet-mappings (see QuestionAnswerSheetMappingController
// — it re-validates the same things server-side, since a direct API call
// could skip the Check step entirely). Any field/CSV/PDF change resets
// back to "Check" so a stale pass can never reach Submit.
//
// Field flow: picking a Question Paper both fetches its full structure
// (shown read-only in the right column, via the same QuestionNodeViewer.vue
// used by QuestionPaperViewView.vue) and auto-fills Course + Semester from
// that paper's own stored values — both stay ordinary editable fields
// afterward (Course explicitly can be overridden per the request; Semester
// follows the same "auto-filled but not locked" convention every other
// form in this app already uses for its own auto-filled fields). Program
// is independent — a paper→program relationship isn't derivable (programs
// and courses are many-to-many), so it's just its own required pick.
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import api from '../utils/api'
import { useToast } from '../composables/useToast'
import { useLoading } from '../composables/useLoading'
import { extractQrFromPdfFirstPage } from '../utils/qr'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import QuestionNodeViewer from '../components/questionPapers/QuestionNodeViewer.vue'

const router = useRouter()
const toast = useToast()
const { startProgress, setProgress, endProgress } = useLoading()

// --- Question paper dropdown ---------------------------------------------
const questionPapers = ref([]) // {id, name, course_id, exam_term_id, semester} — enough to drive the dropdown + auto-fill
const questionPapersLoading = ref(true)
const questionPapersError = ref('')
async function loadQuestionPapers() {
  questionPapersLoading.value = true
  questionPapersError.value = ''
  try {
    // No ?status=all unpaginated branch on this endpoint (unlike Course/
    // Department/Program/Student) — a generous per_page stands in for it
    // until this form's real submit work adds one, matching the "Laravel
    // index() endpoint pattern" this backend otherwise follows everywhere.
    const res = await api.get('/question-papers', { params: { status: 'ready', per_page: 200 } })
    questionPapers.value = res.data.data.items.map((paper) => ({
      id: paper.id,
      name: `${paper.course_name || 'Unknown course'} — ${paper.exam_year}, Sem ${paper.semester}`,
      course_id: paper.course_id,
      // Nullable on the paper itself — a paper set up before this field
      // existed simply has nothing to auto-fill from yet (see
      // onQuestionPaperChange() below).
      exam_term_id: paper.exam_term_id || '',
      semester: paper.semester,
    }))
  } catch (err) {
    questionPapersError.value = err.response?.data?.message || 'Could not load question papers.'
  } finally {
    questionPapersLoading.value = false
  }
}

// --- Selected paper's full structure, for the right-column preview -------
const selectedPaper = ref(null)
const paperLoading = ref(false)
const paperError = ref('')
async function loadSelectedPaper(id) {
  selectedPaper.value = null
  paperError.value = ''
  if (!id) return
  paperLoading.value = true
  try {
    const res = await api.get(`/question-papers/${id}`)
    selectedPaper.value = res.data.data
  } catch (err) {
    paperError.value = err.response?.data?.message || 'Could not load this question paper.'
  } finally {
    paperLoading.value = false
  }
}

const form = reactive({
  question_paper_id: '',
  course_id: '',
  exam_term_id: '',
  semester: '',
  program_name: '',
  packet_code: '',
})
const fieldErrors = reactive({ question_paper_id: '', course_id: '', exam_term_id: '', semester: '', program_name: '', packet_code: '' })
// Order matters — matches the form's own top-to-bottom field order, so the
// first of these (in this order) that has an error is the one that gets
// focused after a failed check.
const FIELD_ORDER = ['program_name', 'packet_code', 'question_paper_id', 'course_id', 'exam_term_id', 'semester']
const fieldRefs = {
  program_name: ref(null),
  packet_code: ref(null),
  question_paper_id: ref(null),
  course_id: ref(null),
  exam_term_id: ref(null),
  semester: ref(null),
}
function clearFieldError(field) {
  fieldErrors[field] = ''
  resetValidation() // anything that changes what was last checked needs a fresh Check
}

function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

function onQuestionPaperChange(id) {
  clearFieldError('question_paper_id')
  loadSelectedPaper(id)
  const picked = questionPapers.value.find((p) => String(p.id) === String(id))
  if (picked) {
    form.course_id = picked.course_id
    form.exam_term_id = picked.exam_term_id
    form.semester = picked.semester
    clearFieldError('course_id')
    clearFieldError('exam_term_id')
    clearFieldError('semester')
  }
}

// --- Course dropdown (auto-filled above; user can still change it) -------
const availableCourses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')
async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  try {
    const res = await api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    // SearchableSelect just renders each option's own `name` — appending
    // the code here (rather than changing SearchableSelect itself) keeps
    // this specific to this one dropdown.
    availableCourses.value = res.data.data.map((course) => ({
      ...course,
      name: course.code ? `${course.name} (${course.code})` : course.name,
    }))
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load courses.'
  } finally {
    coursesLoading.value = false
  }
}

// --- Program dropdown — same {id: name, name} trick StudentFormView.vue
// uses, since Program is matched by name, not id, everywhere else students
// carry a program.
const availablePrograms = ref([])
const programsLoading = ref(true)
const programsError = ref('')
async function loadPrograms() {
  programsLoading.value = true
  programsError.value = ''
  try {
    const res = await api.get('/programs', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availablePrograms.value = res.data.data.map((program) => ({ id: program.name, name: program.name }))
  } catch (err) {
    programsError.value = err.response?.data?.message || 'Could not load programs.'
  } finally {
    programsLoading.value = false
  }
}

const availableExamTerms = ref([])
const examTermsLoading = ref(true)
const examTermsError = ref('')
async function loadExamTerms() {
  examTermsLoading.value = true
  examTermsError.value = ''
  try {
    const res = await api.get('/exam-terms', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTerms.value = res.data.data
  } catch (err) {
    examTermsError.value = err.response?.data?.message || 'Could not load exam terms.'
  } finally {
    examTermsLoading.value = false
  }
}

onMounted(() => {
  loadQuestionPapers()
  loadCourses()
  loadPrograms()
  loadExamTerms()
})

// --- CSV upload (one file — student roll / answer-sheet-code mapping).
// Column set/order given directly by the exam center's own system export
// (see the screenshot this was built from) — kept here as the single
// source of truth for both the downloadable template's header row and,
// later, the real upload's column-matching once that's built. Human-
// readable "Proper Case" headers instead of the raw export's mixed
// snake_case/SHOUTING — someone filling this in by hand reads "Branch
// Name", not "branch_name". A few of the raw names are acronyms this
// project doesn't otherwise define (SBARCODE, ficode, Topsheet) — labeled
// here with a best-effort guess at what they stand for; flag if wrong and
// these are trivial to rename.
const CSV_COLUMNS = [
  'Branch Code',
  'Branch Name',
  'Subject Code',
  'Subject Name',
  'Semester',
  'Subject Barcode', // raw: SBARCODE
  'FI Code', // raw: ficode
  'Roll No',
  'Name',
  'Registration No',
  'Absent',
  'Locked Time',
  'Packet No',
  'Barcode',
  'Marks',
  'Top Sheet', // raw: Topsheet
]

// Maps each proper-case CSV header to the answer_sheets table's own
// snake_case column name — used when the validated rows actually get sent
// to the server (see submitToServer() below); CSV_COLUMNS above stays the
// single source of truth for the header text itself, this just says where
// each one lands in the database.
const ROW_FIELD_MAP = {
  'Branch Code': 'branch_code',
  'Branch Name': 'branch_name',
  'Subject Code': 'subject_code',
  'Subject Name': 'subject_name',
  Semester: 'semester',
  'Subject Barcode': 'subject_barcode',
  'FI Code': 'fi_code',
  'Roll No': 'roll_no',
  Name: 'name',
  'Registration No': 'registration_no',
  Absent: 'absent',
  'Locked Time': 'locked_time',
  'Packet No': 'packet_no',
  Barcode: 'barcode',
  Marks: 'marks',
  'Top Sheet': 'top_sheet',
}

function downloadCsvTemplate() {
  const worksheet = XLSX.utils.aoa_to_sheet([CSV_COLUMNS])
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Mapping')
  XLSX.writeFile(workbook, 'answer-sheet-mapping-template.csv', { bookType: 'csv' })
}

const csvFile = ref(null)
function onCsvChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  csvFile.value = file
  resetValidation()
}
function removeCsv() {
  csvFile.value = null
  resetValidation()
}

// --- PDF upload — a scan bunch this large (~3000 files expected) can't
// reasonably list every filename in the DOM, so this shows a running
// count/size plus a capped preview instead of the full list.
const pdfFiles = ref([])
const PDF_PREVIEW_LIMIT = 20
function onPdfsChange(event) {
  const files = Array.from(event.target.files || [])
  event.target.value = ''
  if (!files.length) return
  pdfFiles.value = [...pdfFiles.value, ...files]
  resetValidation()
}
function removePdf(index) {
  pdfFiles.value.splice(index, 1)
  resetValidation()
}
function clearAllPdfs() {
  pdfFiles.value = []
  resetValidation()
}
const pdfPreview = computed(() => pdfFiles.value.slice(0, PDF_PREVIEW_LIMIT))
const pdfHiddenCount = computed(() => Math.max(0, pdfFiles.value.length - PDF_PREVIEW_LIMIT))
const pdfTotalSize = computed(() => pdfFiles.value.reduce((sum, f) => sum + f.size, 0))
function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unitIndex = 0
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex++
  }
  return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

// --- Submit-time validation — checks the CSV and PDF bunch against each
// other before anything would actually be saved (saving itself still isn't
// wired up — see top docblock — this is the first real piece of it).
// Doesn't validate every CSV column yet, only what was asked for: Subject
// Barcode and Roll No uniqueness, the CSV row count against the PDF count,
// every PDF having a readable QR code on its first page, and the set of
// Subject Barcode values matching the set of QR codes actually found.
const REQUIRED_CSV_COLUMNS = ['Subject Barcode', 'Roll No']
const QR_PREVIEW_LIMIT = 30 // cap how many filenames/values a single failed check lists inline

function parseCsvFile(file) {
  return file.arrayBuffer().then((buffer) => {
    const workbook = XLSX.read(buffer, { type: 'array' })
    const sheet = workbook.Sheets[workbook.SheetNames[0]]
    return XLSX.utils.sheet_to_json(sheet, { defval: '' })
  })
}

// Case/whitespace-tolerant — the downloaded template's headers should
// match exactly, but someone hand-editing the file might not keep the
// exact casing every time.
function findColumnKey(row, columnName) {
  const target = columnName.trim().toLowerCase()
  return Object.keys(row).find((k) => k.trim().toLowerCase() === target)
}

// { rowNumber, value } for every row — rowNumber is 1-indexed counting the
// header as row 1, matching what someone would actually see if they opened
// the CSV in a spreadsheet app, for pointing back at a bad row.
function columnValues(rows, columnKey) {
  return rows.map((row, index) => ({ rowNumber: index + 2, value: String(row[columnKey] ?? '').trim() }))
}

function findDuplicateValues(entries) {
  const rowsByValue = new Map()
  entries.forEach(({ rowNumber, value }) => {
    if (!value) return
    if (!rowsByValue.has(value)) rowsByValue.set(value, [])
    rowsByValue.get(value).push(rowNumber)
  })
  return Array.from(rowsByValue.entries())
    .filter(([, rowNumbers]) => rowNumbers.length > 1)
    .map(([value, rowNumbers]) => `"${value}" (rows ${rowNumbers.join(', ')})`)
}

function capList(list) {
  if (list.length <= QR_PREVIEW_LIMIT) return list
  return [...list.slice(0, QR_PREVIEW_LIMIT), `…and ${list.length - QR_PREVIEW_LIMIT} more`]
}

const validating = ref(false)
const validationProgress = ref('')
const validationChecks = ref(null) // [{ label, status: 'pass' | 'fail', details: string[] }] once a run has completed
// null = not checked yet (or something changed since the last check —
// every field/CSV/PDF change handler calls resetValidation()), true = the
// last check passed (the submit button now does the real submit — see
// submitButtonLabel below), false = the last check found problems (button
// goes back to "Recheck" instead of silently offering "Submit" again).
const validationPassed = ref(null)
// Built once a check actually passes (see runValidation() below) — rows
// normalized to the answer_sheets table's own snake_case columns (via
// ROW_FIELD_MAP), and each PDF File keyed by the same barcode value its
// own QR decoded to. Kept around so the eventual Submit click can send
// them straight off without re-parsing the CSV or re-decoding every QR a
// second time — cleared on any resetValidation() so a stale payload from
// before a files/fields change can never actually get submitted.
const preparedRows = ref([])
const preparedPdfsByBarcode = ref({})
function resetValidation() {
  validationPassed.value = null
  preparedRows.value = []
  preparedPdfsByBarcode.value = {}
}

const submitting = ref(false)
const submitProgress = ref('')

// The submit button reads as "Check" the first time, "Recheck" after a
// failed check, and only ever "Submit" right after a check has actually
// passed against the *current* form/CSV/PDFs — never stale.
const submitButtonLabel = computed(() => {
  if (submitting.value) return submitProgress.value || 'Submitting…'
  if (validating.value) return validationProgress.value || 'Checking…'
  if (validationPassed.value === true) return 'Submit'
  if (validationPassed.value === false) return 'Recheck'
  return 'Check'
})

async function submitForm() {
  if (validationPassed.value === true) {
    await submitToServer()
    return
  }
  await runValidation()
}

// A real submission runs into the thousands of rows/PDFs — one request
// can't carry that (PHP's own max_file_uploads/post_max_size/
// upload_max_filesize cap a single request well below that even after
// raising them about as far as is sane — see StoreAnswerSheetRowsRequest's
// own docblock and public/.user.ini), so this is a two-phase upload:
// create the (still-empty) packet once, then stream its rows/PDFs in as
// many small batches, one request per batch. 200 rows/PDFs a batch keeps
// each request comfortably under every one of those raised caps
// (max_file_uploads=1000, post_max_size=2048M) with plenty of headroom,
// while still needing only ~15-25 requests for a 3000-5000-row submission
// rather than thousands of tiny ones.
const UPLOAD_BATCH_SIZE = 200

async function submitToServer() {
  submitting.value = true
  const total = preparedRows.value.length
  let mapping = null

  try {
    submitProgress.value = 'Creating packet…'
    startProgress('Creating packet…')

    const createResponse = await api.post('/answer-sheet-mappings', {
      question_paper_id: form.question_paper_id,
      course_id: form.course_id,
      exam_term_id: form.exam_term_id,
      semester: form.semester,
      program_name: form.program_name,
      packet_code: form.packet_code,
    })
    mapping = createResponse.data.data

    const batches = []
    for (let i = 0; i < preparedRows.value.length; i += UPLOAD_BATCH_SIZE) {
      batches.push(preparedRows.value.slice(i, i + UPLOAD_BATCH_SIZE))
    }

    let completed = 0
    for (const batch of batches) {
      const body = new FormData()
      body.append('rows', JSON.stringify(batch))
      batch.forEach((row) => {
        const file = preparedPdfsByBarcode.value[row.subject_barcode]
        if (file) body.append(`pdfs[${row.subject_barcode}]`, file)
      })

      await api.post(`/answer-sheet-mappings/${mapping.id}/rows`, body)

      completed += batch.length
      const label = `Uploading ${completed} of ${total} row(s)…`
      submitProgress.value = label
      setProgress((completed / total) * 100, label)
    }

    toast.success('Answer sheets uploaded successfully.')
    router.push({ name: 'answer-sheets' })
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not save this upload.')
    // The server found something the client-side Check missed (or the
    // files/fields quietly went stale some other way) — don't leave the
    // button reading "Submit" as if trying again would just work.
    validationPassed.value = false

    // A batch partway through failed — don't leave a half-filled packet
    // sitting there as if it were a complete one. There's no force-delete
    // endpoint exposed for this table yet (only tests reach that via the
    // model directly), so this soft-deletes it instead — cascades to
    // every row already saved (see QuestionAnswerSheetMapping::booted()),
    // which is enough to keep it out of index()'s listing.
    if (mapping?.id) {
      try {
        await api.delete(`/answer-sheet-mappings/${mapping.id}`)
      } catch {
        // Best-effort cleanup only — surfacing a second error here on top
        // of the real one above would just be confusing.
      }
    }
  } finally {
    submitting.value = false
    submitProgress.value = ''
    endProgress()
  }
}

async function runValidation() {
  validationChecks.value = null
  validationPassed.value = null

  let hasFieldError = false
  FIELD_ORDER.forEach((field) => {
    if (!form[field]) {
      fieldErrors[field] = 'Required.'
      hasFieldError = true
    }
  })
  if (hasFieldError) {
    toast.error('Please fill in the required packet details above.')
    focusFirstError()
    return
  }
  if (!csvFile.value) {
    toast.error('Please upload the CSV mapping first.')
    return
  }
  if (!pdfFiles.value.length) {
    toast.error('Please upload the scanned answer-sheet PDFs first.')
    return
  }

  validating.value = true
  const checks = []

  try {
    // --- Parse the CSV and check it has the columns this validation needs.
    validationProgress.value = 'Reading the CSV…'
    let rows = []
    try {
      rows = await parseCsvFile(csvFile.value)
    } catch {
      checks.push({ label: 'Read the CSV file', status: 'fail', details: ['Could not read this file — make sure it\'s a valid .csv.'] })
      validationChecks.value = checks
      validationPassed.value = false
      return
    }

    const barcodeKey = rows.length ? findColumnKey(rows[0], 'Subject Barcode') : null
    const rollNoKey = rows.length ? findColumnKey(rows[0], 'Roll No') : null
    const columnKeys = { 'Subject Barcode': barcodeKey, 'Roll No': rollNoKey }
    const missingColumns = REQUIRED_CSV_COLUMNS.filter((name) => !columnKeys[name])
    if (!rows.length || missingColumns.length) {
      checks.push({
        label: 'CSV has the required columns',
        status: 'fail',
        details: !rows.length ? ['This CSV has no data rows.'] : [`Missing column(s): ${missingColumns.join(', ')}.`],
      })
      validationChecks.value = checks
      validationPassed.value = false
      return
    }
    checks.push({ label: 'CSV has the required columns', status: 'pass', details: [] })

    // --- Subject Barcode uniqueness.
    const barcodeEntries = columnValues(rows, barcodeKey)
    const emptyBarcodes = barcodeEntries.filter((e) => !e.value).map((e) => `row ${e.rowNumber}`)
    const duplicateBarcodes = findDuplicateValues(barcodeEntries)
    checks.push({
      label: 'Subject Barcode values are unique',
      status: emptyBarcodes.length || duplicateBarcodes.length ? 'fail' : 'pass',
      details: [
        ...(emptyBarcodes.length ? [`Missing a barcode: ${capList(emptyBarcodes).join(', ')}.`] : []),
        ...(duplicateBarcodes.length ? [`Duplicate barcodes: ${capList(duplicateBarcodes).join('; ')}.`] : []),
      ],
    })

    // --- Roll No uniqueness.
    const rollNoEntries = columnValues(rows, rollNoKey)
    const emptyRollNos = rollNoEntries.filter((e) => !e.value).map((e) => `row ${e.rowNumber}`)
    const duplicateRollNos = findDuplicateValues(rollNoEntries)
    checks.push({
      label: 'Roll No values are unique',
      status: emptyRollNos.length || duplicateRollNos.length ? 'fail' : 'pass',
      details: [
        ...(emptyRollNos.length ? [`Missing a roll no: ${capList(emptyRollNos).join(', ')}.`] : []),
        ...(duplicateRollNos.length ? [`Duplicate roll nos: ${capList(duplicateRollNos).join('; ')}.`] : []),
      ],
    })

    // --- Semester must be a whole number 1-12 when given (nullable column
    // — an empty cell is fine, a non-numeric or out-of-range one isn't).
    // Matches StoreAnswerSheetRowsRequest's own rows.*.semester rule
    // exactly, so a row that passes this check never turns around and
    // fails at the real Submit.
    const semesterKey = rows.length ? findColumnKey(rows[0], 'Semester') : null
    if (semesterKey) {
      const semesterEntries = columnValues(rows, semesterKey)
      const invalidSemesters = semesterEntries
        .filter((e) => e.value && (!/^\d+$/.test(e.value) || Number(e.value) < 1 || Number(e.value) > 12))
        .map((e) => `row ${e.rowNumber} ("${e.value}")`)
      checks.push({
        label: 'Semester is a whole number (1-12)',
        status: invalidSemesters.length ? 'fail' : 'pass',
        details: invalidSemesters.length ? [`Not a valid semester: ${capList(invalidSemesters).join(', ')}.`] : [],
      })
    }

    // --- Row count vs PDF count.
    const pdfCount = pdfFiles.value.length
    checks.push({
      label: 'CSV row count matches the number of PDFs',
      status: rows.length === pdfCount ? 'pass' : 'fail',
      details: rows.length === pdfCount ? [] : [`CSV has ${rows.length} row(s) but ${pdfCount} PDF(s) were uploaded.`],
    })

    // --- Read every PDF's own QR code. A batch this size can run into the
    // thousands, so this runs a small bounded worker pool (QR_CONCURRENCY
    // at a time) rather than either fully sequential (far too slow at
    // 3000-5000 files) or all-at-once (that many PDF documents decoding
    // simultaneously would be a lot to hold in memory/CPU at once).
    const QR_CONCURRENCY = 6
    const files = pdfFiles.value
    const qrByFile = new Array(files.length) // { fileName, value: string|null }, indexed same as `files`
    let qrCompleted = 0
    let nextFileIndex = 0

    async function qrWorker() {
      while (nextFileIndex < files.length) {
        const i = nextFileIndex++
        const file = files[i]
        let value = null
        try {
          value = await extractQrFromPdfFirstPage(file)
        } catch {
          value = null // an unreadable/corrupt PDF counts the same as "no QR found" for this check
        }
        qrByFile[i] = { fileName: file.name, value }
        qrCompleted++
        validationProgress.value = `Reading QR codes — ${qrCompleted} of ${files.length}…`
      }
    }
    await Promise.all(Array.from({ length: Math.min(QR_CONCURRENCY, files.length) }, qrWorker))
    validationProgress.value = ''

    const pdfByBarcode = {} // barcode -> File, for submitToServer() once everything passes
    qrByFile.forEach((entry, i) => {
      if (entry.value) pdfByBarcode[entry.value] = files[i]
    })

    const missingQr = qrByFile.filter((f) => !f.value).map((f) => f.fileName)
    checks.push({
      label: 'Every PDF has a readable QR code',
      status: missingQr.length ? 'fail' : 'pass',
      details: missingQr.length ? [`No QR code found in: ${capList(missingQr).join(', ')}.`] : [],
    })

    // --- Subject Barcode (CSV) vs QR code (PDFs) — both directions, using
    // only the PDFs that actually had a readable QR (already reported above).
    const csvBarcodes = new Set(barcodeEntries.map((e) => e.value).filter(Boolean))
    const pdfBarcodes = new Set(qrByFile.filter((f) => f.value).map((f) => f.value))
    const barcodesWithNoPdf = capList([...csvBarcodes].filter((b) => !pdfBarcodes.has(b)))
    const pdfsWithNoBarcode = capList(
      qrByFile.filter((f) => f.value && !csvBarcodes.has(f.value)).map((f) => `${f.fileName} (${f.value})`),
    )
    checks.push({
      label: 'Every CSV barcode matches a PDF\'s QR code',
      status: barcodesWithNoPdf.length || pdfsWithNoBarcode.length ? 'fail' : 'pass',
      details: [
        ...(barcodesWithNoPdf.length ? [`In the CSV but no matching PDF: ${barcodesWithNoPdf.join(', ')}.`] : []),
        ...(pdfsWithNoBarcode.length ? [`In a PDF's QR but not in the CSV: ${pdfsWithNoBarcode.join(', ')}.`] : []),
      ],
    })

    validationChecks.value = checks
    const allPassed = checks.every((c) => c.status === 'pass')
    validationPassed.value = allPassed
    if (allPassed) {
      // Normalize each row from the CSV's original "Proper Case" headers
      // to the answer_sheets table's own snake_case columns (ROW_FIELD_MAP)
      // — built once here, right before Submit becomes reachable, rather
      // than re-parsed at submit time.
      preparedRows.value = rows.map((row) => {
        const normalized = {}
        for (const [header, field] of Object.entries(ROW_FIELD_MAP)) {
          const key = findColumnKey(row, header)
          const value = key ? String(row[key] ?? '').trim() : ''
          // The CSV spells this "Yes"/"No" — the backend's own validation
          // wants an actual boolean, not that literal text.
          normalized[field] = field === 'absent' ? /^y/i.test(value) : value
        }
        return normalized
      })
      preparedPdfsByBarcode.value = pdfByBarcode
      toast.success('All checks passed — click Submit to save.')
    } else {
      toast.error('Some checks failed — see the results below.')
    }
  } finally {
    validating.value = false
    validationProgress.value = ''
  }
}

function cancel() {
  router.push({ name: 'answer-sheets' })
}
</script>

<template>
  <div>
    <div class="max-w-[1500px] mx-auto">
      <!-- Page header -->
      <div class="flex items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3">
          <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
            <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
              <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 3v12M12 3l-4 4M12 3l4 4" />
                <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4" />
              </svg>
            </span>
          </span>
          <div>
            <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Upload Answer Sheets</h1>
            <p class="mt-1 text-[13px] sm:text-sm text-muted">Pick the question paper this packet belongs to, then upload the CSV mapping and the scanned PDFs.</p>
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

      <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-4 items-start">
        <!-- Left: the form -->
        <form class="flex flex-col gap-4" @submit.prevent="submitForm">
          <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Packet Details</h2>
            <p class="text-[13px] text-muted mb-3">Course and Semester are filled in from the question paper you pick — change them if this packet is different.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="flex flex-col gap-1.5">
                <label for="program" class="text-[13px] text-label">Program <span class="text-brand">*</span></label>
                <SearchableSelect
                  id="program"
                  :ref="(el) => (fieldRefs.program_name.value = el)"
                  v-model="form.program_name"
                  :options="availablePrograms"
                  :loading="programsLoading"
                  :error="!!fieldErrors.program_name"
                  placeholder="Select program"
                  search-placeholder="Search programs…"
                  @change="clearFieldError('program_name')"
                />
                <p v-if="programsError" class="text-[12px] text-brand">{{ programsError }}</p>
                <p v-else-if="fieldErrors.program_name" class="text-[12px] text-brand">{{ fieldErrors.program_name }}</p>
              </div>

              <div class="flex flex-col gap-1.5">
                <label for="packet_code" class="text-[13px] text-label">Packet Code <span class="text-brand">*</span></label>
                <input
                  id="packet_code"
                  :ref="(el) => (fieldRefs.packet_code.value = el)"
                  v-model="form.packet_code"
                  type="text"
                  placeholder="e.g. PKT-2026-0007"
                  @input="clearFieldError('packet_code')"
                  class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.packet_code ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <p v-if="fieldErrors.packet_code" class="text-[12px] text-brand">{{ fieldErrors.packet_code }}</p>
              </div>
            </div>
            <div class="flex flex-col gap-1.5 mb-3">
              <label for="question_paper" class="text-[13px] text-label">Question Paper <span class="text-brand">*</span></label>
              <SearchableSelect
                id="question_paper"
                :ref="(el) => (fieldRefs.question_paper_id.value = el)"
                v-model="form.question_paper_id"
                :options="questionPapers"
                :loading="questionPapersLoading"
                :error="!!fieldErrors.question_paper_id"
                placeholder="Select question paper"
                search-placeholder="Search question papers…"
                @change="onQuestionPaperChange"
              />
              <p v-if="questionPapersError" class="text-[12px] text-brand">{{ questionPapersError }}</p>
              <p v-else-if="fieldErrors.question_paper_id" class="text-[12px] text-brand">{{ fieldErrors.question_paper_id }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-[1.4fr_1.2fr_0.7fr] gap-3 mb-3">
              <div class="flex flex-col gap-1.5">
                <label for="course" class="text-[13px] text-label">Course <span class="text-brand">*</span></label>
                <SearchableSelect
                  id="course"
                  :ref="(el) => (fieldRefs.course_id.value = el)"
                  v-model="form.course_id"
                  :options="availableCourses"
                  :loading="coursesLoading"
                  :error="!!fieldErrors.course_id"
                  placeholder="Select course"
                  search-placeholder="Search courses…"
                  @change="clearFieldError('course_id')"
                />
                <p v-if="coursesError" class="text-[12px] text-brand">{{ coursesError }}</p>
                <p v-else-if="fieldErrors.course_id" class="text-[12px] text-brand">{{ fieldErrors.course_id }}</p>
              </div>

              <div class="flex flex-col gap-1.5">
                <label for="exam_term" class="text-[13px] text-label">Exam Term <span class="text-brand">*</span></label>
                <SearchableSelect
                  id="exam_term"
                  :ref="(el) => (fieldRefs.exam_term_id.value = el)"
                  v-model="form.exam_term_id"
                  :options="availableExamTerms"
                  :loading="examTermsLoading"
                  :error="!!fieldErrors.exam_term_id"
                  placeholder="Select exam term"
                  search-placeholder="Search exam terms…"
                  @change="clearFieldError('exam_term_id')"
                />
                <p v-if="examTermsError" class="text-[12px] text-brand">{{ examTermsError }}</p>
                <p v-else-if="fieldErrors.exam_term_id" class="text-[12px] text-brand">{{ fieldErrors.exam_term_id }}</p>
              </div>

              <div class="flex flex-col gap-1.5">
                <label for="semester" class="text-[13px] text-label">Semester <span class="text-brand">*</span></label>
                <input
                  id="semester"
                  :ref="(el) => (fieldRefs.semester.value = el)"
                  v-model="form.semester"
                  type="number"
                  min="1"
                  @input="clearFieldError('semester')"
                  class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.semester ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <p v-if="fieldErrors.semester" class="text-[12px] text-brand">{{ fieldErrors.semester }}</p>
              </div>
            </div>

            
          </section>

          <!-- CSV upload -->
          <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Student &amp; Answer-Sheet Mapping (CSV)</h2>
            <p class="text-[13px] text-muted mb-3">Roll numbers, other student details, and each answer-sheet code (read off the QR on its cover page).</p>

            <div class="flex items-center gap-3 mb-4 pb-4 border-b border-soft">
              <p class="text-[13px] font-medium text-gray-700 shrink-0">1. Download the template</p>
              <button
                type="button"
                class="h-9 inline-flex items-center gap-2 rounded-xl border border-input-border bg-white text-[13px] font-semibold text-gray-700 px-4 hover:border-brand-blue hover:text-brand-blue transition-colors"
                @click="downloadCsvTemplate"
              >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                Sample Template
              </button>
            </div>

            <p class="text-[13px] font-medium text-gray-700 mb-2">2. Upload your filled CSV</p>
            <label
              v-if="!csvFile"
              class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-input-border bg-page-bg/60 px-4 py-8 text-center cursor-pointer hover:border-brand-blue transition-colors"
            >
              <svg class="w-8 h-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
              <span class="text-sm font-medium text-gray-700">Click to choose a .csv file</span>
              <input type="file" accept=".csv" class="hidden" @change="onCsvChange" />
            </label>
            <div v-else class="flex items-center justify-between gap-3 rounded-2xl border border-input-border bg-page-bg/60 px-4 py-3">
              <div class="flex items-center gap-2.5 min-w-0">
                <svg class="w-5 h-5 text-brand-blue shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
                <span class="text-sm text-gray-800 truncate">{{ csvFile.name }}</span>
              </div>
              <button type="button" class="shrink-0 text-[12px] font-semibold text-brand hover:underline" @click="removeCsv">Remove</button>
            </div>
          </section>

          <!-- PDF upload -->
          <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Scanned Answer Sheets (PDFs)</h2>
            <p class="text-[13px] text-muted mb-3">Select the whole bunch at once — one multi-page PDF per student, each with a QR code on its cover page.</p>

            <label
              class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-input-border bg-page-bg/60 px-4 py-8 text-center cursor-pointer hover:border-brand-blue transition-colors"
            >
              <svg class="w-8 h-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
              <span class="text-sm font-medium text-gray-700">Click to choose PDF files</span>
              <span class="text-[12px] text-muted">Multiple files at once — a few thousand is fine.</span>
              <input type="file" accept="application/pdf" multiple class="hidden" @change="onPdfsChange" />
            </label>

            <div v-if="pdfFiles.length" class="mt-3">
              <div class="flex items-center justify-between gap-3 mb-2">
                <p class="text-[13px] text-gray-700">
                  <span class="font-semibold">{{ pdfFiles.length }}</span> file{{ pdfFiles.length === 1 ? '' : 's' }} selected
                  <span class="text-muted">({{ formatBytes(pdfTotalSize) }})</span>
                </p>
                <button type="button" class="text-[12px] font-semibold text-brand hover:underline" @click="clearAllPdfs">Clear all</button>
              </div>
              <ul class="max-h-56 overflow-y-auto rounded-xl border border-input-border divide-y divide-soft">
                <li v-for="(file, index) in pdfPreview" :key="index" class="flex items-center justify-between gap-3 px-3 py-2 text-[12px] text-gray-700">
                  <span class="truncate">{{ file.name }}</span>
                  <button type="button" class="shrink-0 text-gray-400 hover:text-brand transition-colors" aria-label="Remove file" @click="removePdf(index)">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                  </button>
                </li>
                <li v-if="pdfHiddenCount" class="px-3 py-2 text-[12px] text-muted text-center">+{{ pdfHiddenCount }} more file{{ pdfHiddenCount === 1 ? '' : 's' }}</li>
              </ul>
            </div>
          </section>

          <!-- Validation results — appears after Submit is clicked once -->
          <section v-if="validationChecks" class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-3">Validation Results</h2>
            <div class="flex flex-col gap-2.5">
              <div v-for="check in validationChecks" :key="check.label" class="flex gap-2.5">
                <svg v-if="check.status === 'pass'" class="w-4 h-4 mt-0.5 shrink-0 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12" /></svg>
                <svg v-else class="w-4 h-4 mt-0.5 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                <div class="min-w-0">
                  <p class="text-[13px] font-medium" :class="check.status === 'pass' ? 'text-gray-800' : 'text-brand'">{{ check.label }}</p>
                  <p v-for="(detail, i) in check.details" :key="i" class="text-[12px] text-muted mt-0.5 break-words">{{ detail }}</p>
                </div>
              </div>
            </div>
          </section>

          <div class="flex flex-wrap gap-3 justify-center sm:justify-end pb-2">
            <button
              type="button"
              class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors"
              :disabled="validating || submitting"
              @click="cancel"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="min-w-[160px] px-6 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="validating || submitting"
            >
              {{ submitButtonLabel }}
            </button>
          </div>
        </form>

        <!-- Right: selected question paper's structure -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5 lg:sticky lg:top-6 lg:max-h-[calc(100vh-110px)] overflow-y-auto">
          <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-3">Question Paper Design</h2>

          <p v-if="!form.question_paper_id" class="text-sm text-muted text-center py-10">Select a question paper to see its structure here.</p>
          <p v-else-if="paperLoading" class="text-sm text-muted text-center py-10">Loading&hellip;</p>
          <p v-else-if="paperError" class="text-sm text-brand text-center py-10">{{ paperError }}</p>
          <template v-else-if="selectedPaper">
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-[13px] mb-4 pb-4 border-b border-soft">
              <div>
                <p class="text-muted text-[11px] mb-0.5">Exam Year</p>
                <p class="font-semibold text-gray-800">{{ selectedPaper.exam_year }}</p>
              </div>
              <div>
                <p class="text-muted text-[11px] mb-0.5">Exam Term</p>
                <p class="font-semibold text-gray-800">{{ selectedPaper.exam_term_name || '—' }}</p>
              </div>
              <div>
                <p class="text-muted text-[11px] mb-0.5">Semester</p>
                <p class="font-semibold text-gray-800">{{ selectedPaper.semester }}</p>
              </div>
              <div>
                <p class="text-muted text-[11px] mb-0.5">Full Marks</p>
                <p class="font-semibold text-gray-800">{{ selectedPaper.full_marks ?? '—' }}</p>
              </div>
              <div>
                <p class="text-muted text-[11px] mb-0.5">Time Allotted</p>
                <p class="font-semibold text-gray-800">{{ selectedPaper.time_allotted ?? '—' }}</p>
              </div>
            </div>
            <div class="flex flex-col gap-3">
              <QuestionNodeViewer v-for="group in selectedPaper.groups" :key="group.id" :node="group" :depth="0" />
              <p v-if="!selectedPaper.groups?.length" class="text-center text-sm text-muted py-10">This paper has no structure set up yet.</p>
            </div>
          </template>
        </section>
      </div>
    </div>
  </div>
</template>
