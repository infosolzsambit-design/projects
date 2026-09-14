<script setup>
// Everything involved in reading a question paper's PDF and building its
// structure tree — shared between "setting one up for the first time"
// (QuestionPaperSetupView.vue's stage 2, where `pdfSource` is the in-memory
// File just chosen and nothing is saved yet) and "editing an already-saved
// paper's structure later" (QuestionPaperConfigureView.vue, where
// `pdfSource` is the paper's stored PDF URL and `initialGroups` is what it
// already has). The two callers differ only in *where the data comes from*
// and *what the final save actually does* (POST vs PUT) — both handed in
// as props/a submit function — everything about reading the PDF, guessing
// a starting structure, editing it, and validating it lives here once.
import { computed, nextTick, onMounted, reactive, ref, shallowRef } from 'vue'
import { useToast } from '../../composables/useToast'
import { useConfirm } from '../../composables/useConfirm'
import api from '../../utils/api'
import SearchableSelect from '../common/SearchableSelect.vue'
import QuestionNodeEditor from './QuestionNodeEditor.vue'
import draggable from 'vuedraggable'
import { loadPdf, renderPageToCanvas } from '../../utils/pdf'
import { autoFillFromPdf } from '../../utils/questionPaperParser'
import { clearErrors, createBranch, createLeaf, mapNodeFromData, nodeToPayload, sumMarks, validateNode } from '../../utils/questionPaperNode'

const props = defineProps({
  // A URL string (existing paper, fetched from the server) or a pdf.js
  // "source" object such as `{ data: arrayBuffer }` (a File just chosen,
  // still only in the browser's memory) — loadPdf() already branches on
  // which one it got.
  pdfSource: { type: [String, Object], required: true },
  initialForm: {
    type: Object,
    default: () => ({ exam_year: '', course_id: '', exam_term_id: '', semester: '', full_marks: '', time_allotted: '' }),
  },
  // Raw server-shape groups (from an already-saved paper) — left empty for
  // a brand new one, which triggers the auto-fill-from-PDF guess below.
  initialGroups: { type: Array, default: () => [] },
  // async (payload) => apiResponse — the parent decides whether that's a
  // POST (new paper, created already-"ready") or a PUT (editing an
  // existing one's structure).
  submitFn: { type: Function, required: true },
  submitLabel: { type: String, default: 'Save Setup' },
})
const emit = defineEmits(['saved', 'cancel'])

const toast = useToast()
const { confirmDialog } = useConfirm()

const loading = ref(true)
const loadingDetail = ref('') // OCR progress text ("Reading page 2 of 3…") shown under the Loading placeholder
const loadError = ref('')
const saving = ref(false)
const formError = ref('')

const form = reactive({ ...props.initialForm })
const fieldErrors = reactive({ exam_year: '', course_id: '', exam_term_id: '', semester: '', full_marks: '', time_allotted: '' })
// Order matters — matches the form's own top-to-bottom field order, so the
// first of these (in this order) that has an error is the one that gets
// focused after a failed save. Scoped to these flat packet-detail fields
// only — a group/question validation error (see validateNode()) already
// shows inline on the affected node itself, deep inside the recursive
// QuestionNodeEditor.vue tree, which has no single "first field" to focus.
const FIELD_ORDER = ['exam_year', 'course_id', 'exam_term_id', 'semester', 'time_allotted', 'full_marks']
const fieldRefs = {
  exam_year: ref(null),
  course_id: ref(null),
  exam_term_id: ref(null),
  semester: ref(null),
  time_allotted: ref(null),
  full_marks: ref(null),
}
function focusFirstError() {
  const field = FIELD_ORDER.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

const availableCourses = ref([])
const coursesLoading = ref(true)
const coursesError = ref('')
async function loadCourses() {
  coursesLoading.value = true
  coursesError.value = ''
  try {
    const res = await api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    availableCourses.value = res.data.data
  } catch (err) {
    coursesError.value = err.response?.data?.message || 'Could not load courses.'
  } finally {
    coursesLoading.value = false
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

// --- Structure tree — "setting up all this things": no two papers group
// their questions the same way (compulsory, "answer any N of M",
// OR-alternatives that can nest inside a sub-part, ...), so this is a
// free-form recursive builder, not a fixed template. `groups` holds the
// top-level nodes (depth 0); each is edited (and recurses into its own
// children, including drag-to-reorder) via QuestionNodeEditor.vue. ---
const groups = reactive([])

function newGroup() {
  const group = createBranch('all')
  group.children.push(createLeaf())
  return group
}

// Set right after a fresh (never-configured) paper's structure gets guessed
// from its PDF, so the template can nudge the person to check it rather
// than assume it's already correct — see autoFillFromPdf(). autoFilledViaOcr
// additionally tracks whether the guess came from OCR on a scanned page
// image rather than the PDF's own text layer, since OCR is meaningfully
// less reliable and the review prompt says so more strongly.
const autoFilledNotice = ref(false)
const autoFilledViaOcr = ref(false)
const rescanning = ref(false)

function ocrProgressLabel(page, total) {
  return `Reading page ${page} of ${total} with OCR (scanned pages take longer than typed ones)…`
}

function addGroup() {
  groups.push(newGroup())
}
function removeGroup(index) {
  groups.splice(index, 1)
}

function clearFieldError(field) {
  fieldErrors[field] = ''
}

const totalMarks = computed(() => groups.reduce((sum, group) => sum + sumMarks(group), 0))

// --- PDF preview — same utils/pdf.js canvas-rendering approach as
// ReviewDetailView.vue, just read-only (no overlay/annotation layer needed
// here). Rendered once at a fixed, comfortably-readable scale rather than
// letting the user zoom — this is a reference panel while filling in the
// form beside it, not a checking screen. ---
const pdfDoc = shallowRef(null)
const pdfLoading = ref(true)
const pdfError = ref('')
const pageCount = ref(0)
const pageNumbers = computed(() => Array.from({ length: pageCount.value }, (_, i) => i + 1))
const canvasEls = {}
function setCanvasEl(page, el) {
  if (el) canvasEls[page] = el
  else delete canvasEls[page]
}

async function renderAllPdfPages() {
  for (const page of pageNumbers.value) {
    const canvas = canvasEls[page]
    if (!canvas) continue
    try {
      await renderPageToCanvas(pdfDoc.value, page, canvas, { scale: 1.3 })
    } catch (err) {
      pdfError.value = err?.message || String(err)
    }
  }
}

async function rescanPdf() {
  const hasContent = groups.some((g) => g.label.trim() || g.children.some((q) => q.label.trim() || q.marks || q.children.length))
  if (hasContent) {
    const confirmed = await confirmDialog({
      title: 'Re-scan PDF',
      message: 'This reads the PDF text again and replaces the groups and questions below with a fresh guess. Anything you\'ve typed or corrected here will be lost.',
      confirmText: 'Re-scan',
    })
    if (!confirmed) return
  }
  rescanning.value = true
  try {
    const guess = await autoFillFromPdf(pdfDoc.value, {
      onOcrProgress: (page, total) => { loadingDetail.value = ocrProgressLabel(page, total) },
    })
    if (!guess.groups.length) {
      toast.error("Couldn't find a recognizable group/question layout in this PDF — set it up manually below.")
      return
    }
    groups.splice(0, groups.length, ...guess.groups.map(mapNodeFromData))
    if (guess.full_marks) form.full_marks = guess.full_marks
    if (guess.time_allotted) form.time_allotted = guess.time_allotted
    autoFilledNotice.value = true
    autoFilledViaOcr.value = guess.usedOcr
    toast.success('Re-scanned the PDF — please review the groups and questions below.')
  } finally {
    rescanning.value = false
    loadingDetail.value = ''
  }
}

onMounted(async () => {
  loadCourses()
  loadExamTerms()
  loading.value = true
  loadError.value = ''
  try {
    pdfDoc.value = await loadPdf(props.pdfSource)
    pageCount.value = pdfDoc.value.numPages

    if (props.initialGroups.length) {
      // Structure was already set up and saved — always trust that over
      // re-parsing the PDF, since it may have been hand-corrected already.
      groups.splice(0, groups.length, ...props.initialGroups.map(mapNodeFromData))
    } else {
      // Nothing set up yet — read the PDF's own text to guess a starting
      // structure so there's usually something to review instead of a
      // blank form. Best-effort only: falls back to one empty group when
      // the PDF doesn't match any recognized pattern.
      const guess = await autoFillFromPdf(pdfDoc.value, {
        onOcrProgress: (page, total) => { loadingDetail.value = ocrProgressLabel(page, total) },
      })
      if (guess.groups.length) {
        groups.splice(0, groups.length, ...guess.groups.map(mapNodeFromData))
        if (guess.full_marks && !form.full_marks) form.full_marks = guess.full_marks
        if (guess.time_allotted && !form.time_allotted) form.time_allotted = guess.time_allotted
        autoFilledNotice.value = true
        autoFilledViaOcr.value = guess.usedOcr
      } else {
        groups.push(newGroup())
      }
      loadingDetail.value = ''
    }

    // The canvas elements only exist once BOTH the outer "loading" gate
    // (v-if="loading"/v-else around the whole two-column layout) and the
    // inner "pdfLoading" gate (v-if="pdfLoading"/v-else around just the PDF
    // panel's placeholder) have flipped false — nextTick() only waits for a
    // re-render that's already been queued, so both flags must flip *before*
    // it, not in a finally block that runs after renderAllPdfPages(), or the
    // render loop finds no registered canvas refs and silently no-ops
    // (blank boxes, no error).
    loading.value = false
    pdfLoading.value = false
    await nextTick()
    await renderAllPdfPages()
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'Could not load this PDF.'
    pdfLoading.value = false
    loading.value = false
    loadingDetail.value = ''
  }
})

// --- Validation + save — mirrors the backend's own recursive walk (see
// ValidatesQuestionPaperNodes on the backend) so client-side and
// server-side validation never drift: both recurse node by node checking
// the same rules, and server error keys ("groups.0.children.1...") use the
// exact same "groups" + "children" path shape this component's own tree
// does, so applyServerErrors() below can walk straight back to the node
// that failed at whatever depth it's at. ---
function validate() {
  let ok = true
  if (!form.exam_year || String(form.exam_year).length !== 4) {
    fieldErrors.exam_year = 'Enter a valid 4-digit exam year.'
    ok = false
  }
  if (!form.course_id) {
    fieldErrors.course_id = 'Course is required.'
    ok = false
  }
  if (!form.exam_term_id) {
    fieldErrors.exam_term_id = 'Exam term is required.'
    ok = false
  }
  if (!form.semester) {
    fieldErrors.semester = 'Semester is required.'
    ok = false
  }

  if (!groups.length) {
    formError.value = 'Add at least one group.'
    ok = false
  }

  groups.forEach((group) => {
    if (!validateNode(group)) ok = false
  })

  return ok
}

// Walks a dotted error path ("0.children.1.children.0.marks", with the
// leading "groups." already stripped by the caller) back onto the matching
// node in `nodesArray`, at whatever depth it points to.
function applyNodeError(nodesArray, pathParts, message) {
  const node = nodesArray[Number(pathParts[0])]
  if (!node) return

  if (pathParts.length === 2) {
    const field = pathParts[1]
    if (field === 'label') node.labelError = message
    else if (field === 'choose_count') node.chooseCountError = message
    else if (field === 'marks') node.marksError = message
    else if (field === 'children') node.childrenError = message
    return
  }

  if (pathParts[1] === 'children') applyNodeError(node.children, pathParts.slice(2), message)
}

function applyServerErrors(err) {
  const data = err.response?.data
  if (data?.errors) {
    Object.entries(data.errors).forEach(([key, messages]) => {
      if (key.startsWith('groups.')) {
        applyNodeError(groups, key.split('.').slice(1), messages[0])
        return
      }
      if (key in fieldErrors) fieldErrors[key] = messages[0]
    })
  }
  formError.value = data?.message || 'Could not save this question paper.'
  focusFirstError()
}

async function submitForm() {
  formError.value = ''
  Object.keys(fieldErrors).forEach((key) => (fieldErrors[key] = ''))
  groups.forEach(clearErrors)

  if (!validate()) {
    focusFirstError()
    return
  }

  saving.value = true
  try {
    const result = await props.submitFn({
      exam_year: Number(form.exam_year),
      course_id: form.course_id,
      exam_term_id: form.exam_term_id,
      semester: Number(form.semester),
      full_marks: form.full_marks ? Number(form.full_marks) : null,
      time_allotted: form.time_allotted || null,
      groups: groups.map(nodeToPayload),
    })
    emit('saved', result)
  } catch (err) {
    applyServerErrors(err)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div v-if="loading" class="text-center py-10">
      <p class="text-sm text-muted">Loading&hellip;</p>
      <p v-if="loadingDetail" class="text-xs text-muted mt-1">{{ loadingDetail }}</p>
    </div>
    <p v-else-if="loadError" class="text-center text-sm text-brand py-10">{{ loadError }}</p>

    <template v-else>
      <div
        v-if="autoFilledNotice"
        class="flex items-start gap-2.5 rounded-2xl px-4 py-3 mb-4 text-[13px]"
        :class="autoFilledViaOcr ? 'bg-brand/10 border border-brand/30 text-gray-700' : 'bg-badge/10 border border-badge/30 text-gray-700'"
      >
        <svg class="w-4 h-4 mt-0.5 shrink-0" :class="autoFilledViaOcr ? 'text-brand' : 'text-badge'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
        <span v-if="autoFilledViaOcr" class="flex-1">This looked like a scanned PDF with no selectable text, so we used OCR to read it — that's far less reliable than typed text, so please check every group, question number, and mark carefully against the PDF alongside before saving.</span>
        <span v-else class="flex-1">We've read the groups, questions, and marks straight off this PDF as a starting point — please check them against the PDF alongside and correct anything that's wrong before saving.</span>
        <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600" @click="autoFilledNotice = false" aria-label="Dismiss">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-4 items-start">
        <!-- PDF preview -->
        <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5 lg:sticky lg:top-6 lg:max-h-[calc(100vh-110px)] overflow-y-auto">
          <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900">Question Paper PDF</h2>
            <button
              v-if="!pdfLoading && !pdfError"
              type="button"
              :disabled="rescanning"
              class="shrink-0 h-8 inline-flex items-center gap-1.5 rounded-lg border border-input-border bg-white text-[12px] font-semibold text-gray-600 px-3 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              @click="rescanPdf"
            >
              <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': rescanning }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10" /><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" /></svg>
              {{ rescanning ? (loadingDetail || 'Scanning…') : 'Re-scan PDF' }}
            </button>
          </div>
          <p v-if="pdfLoading" class="text-sm text-muted text-center py-8">Loading PDF&hellip;</p>
          <p v-else-if="pdfError" class="text-sm text-brand text-center py-8">{{ pdfError }}</p>
          <div v-else class="flex flex-col gap-3">
            <div v-for="page in pageNumbers" :key="page" class="rounded-2xl border border-input-border overflow-hidden bg-page-bg">
              <canvas :ref="(el) => setCanvasEl(page, el)" class="w-full h-auto block"></canvas>
            </div>
          </div>
        </section>

        <!-- Setup form -->
        <form class="space-y-4" @submit.prevent="submitForm">
          <section class="bg-white rounded-[28px] shadow-card p-4 sm:p-5">
            <h2 class="text-[16px] sm:text-[18px] font-semibold text-gray-900 mb-1">Exam Details</h2>
            <p class="text-[13px] text-muted mb-3">Full Marks and Time Allotted come straight from the PDF's own header.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="flex flex-col gap-1.5">
                <label for="exam_year" class="text-[13px] text-label">Exam Year <span class="text-brand">*</span></label>
                <input
                  id="exam_year"
                  :ref="(el) => (fieldRefs.exam_year.value = el)"
                  v-model="form.exam_year"
                  type="number"
                  @input="clearFieldError('exam_year')"
                  class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                />
                <p v-if="fieldErrors.exam_year" class="text-[12px] text-brand">{{ fieldErrors.exam_year }}</p>
              </div>
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
              <div class="flex flex-col gap-1.5">
                <label for="time_allotted" class="text-[13px] text-label">Time Allotted</label>
                <input
                  id="time_allotted"
                  :ref="(el) => (fieldRefs.time_allotted.value = el)"
                  v-model="form.time_allotted"
                  type="text"
                  placeholder="e.g. 3 hours"
                  class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                />
              </div>
              <div class="flex flex-col gap-1.5">
                <label for="full_marks" class="text-[13px] text-label">Full Marks</label>
                <input
                  id="full_marks"
                  :ref="(el) => (fieldRefs.full_marks.value = el)"
                  v-model="form.full_marks"
                  type="number"
                  min="1"
                  placeholder="e.g. 80"
                  class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                />
                <p class="text-[12px] text-muted">Sum of question marks below: {{ totalMarks }}</p>
              </div>
            </div>
          </section>

          <!-- Groups — each is depth 0 of the recursive structure tree, and
               drag-to-reorder here too (see the drag-handle inside
               QuestionNodeEditor.vue's own depth-0 template, which is what
               makes each rendered "item" below draggable). -->
          <draggable
            :model-value="groups"
            item-key="key"
            handle=".drag-handle"
            tag="div"
            :force-fallback="true"
            :group="{ name: 'root-groups', pull: false, put: false }"
            class="space-y-4"
            @update:model-value="(val) => groups.splice(0, groups.length, ...val)"
          >
            <template #item="{ element: group, index: groupIndex }">
              <QuestionNodeEditor
                :node="group"
                :depth="0"
                :removable="groups.length > 1"
                @remove="removeGroup(groupIndex)"
              />
            </template>
          </draggable>

          <button
            type="button"
            class="w-full h-11 rounded-2xl border-2 border-dashed border-input-border text-[13px] font-semibold text-gray-600 hover:border-brand-blue hover:text-brand-blue transition-colors inline-flex items-center justify-center gap-2"
            @click="addGroup"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
            Add Group
          </button>

          <p v-if="formError" class="text-[13px] text-brand text-center sm:text-right">{{ formError }}</p>

          <div class="flex flex-wrap gap-3 justify-center sm:justify-end pb-2">
            <button
              type="button"
              class="min-w-[120px] px-6 py-3 rounded-full border border-input-border bg-white text-sm font-semibold text-gray-700 hover:border-brand-blue hover:text-brand-blue transition-colors disabled:opacity-60"
              :disabled="saving"
              @click="emit('cancel')"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="min-w-[160px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.05em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="saving"
            >
              {{ saving ? 'Saving…' : submitLabel }}
            </button>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>
