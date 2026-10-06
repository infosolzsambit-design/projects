<script setup>
// Distribute a course/semester's pending answer sheets across teachers.
// "Distribute Equally" (distributeEqually()) is still pure client-side
// math — it only seeds each teacher row's own assignQuantity, which the
// table below renders as an editable field, so the admin can hand-edit any
// of those numbers before finally clicking "Assign". "Assign" itself
// (assignPapers()) is real: it posts the final {teacher_id, quantity} list
// to POST /assign-teacher, which persists it by stamping teacher_id on
// that many still-pending AnswerSheet rows (see AssignTeacherService's own
// docblock on the backend) — no separate "equal vs individual" mode to
// switch between.
//
// "Search" (runSearch()) is real, not mocked — there's no dedicated
// filtered-list endpoint on the backend yet for
// question_answer_sheet_mappings (see QuestionAnswerSheetMappingController
// ::index()'s own docblock: "no ?status=all/search/sort branches yet"), so
// this loads every mapping + every question paper once (generous per_page,
// same stopgap AnswerSheetUploadView.vue's own question-paper dropdown
// already uses) and filters them in memory by the five picked criteria.
// Program/Exam Term/Course/Semester live directly on a mapping; Exam Year
// only lives on its linked question paper, so that one's matched by
// joining through question_paper_id client-side.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '../utils/api'
import { useAuthStore } from '../stores/auth'
import { useBrandingStore } from '../stores/branding'
import { useToast } from '../composables/useToast'
import { formatDateTime } from '../utils/date'
import SearchableSelect from '../components/common/SearchableSelect.vue'
import DatePicker from '../components/common/DatePicker.vue'
import TeacherAllocationModal from '../components/teachers/TeacherAllocationModal.vue'
import SendAssignmentEmailModal from '../components/teachers/SendAssignmentEmailModal.vue'
import { useProgramsStore } from '../stores/programs'
import { courseLabel } from '../utils/course'
import { ordinal } from '../utils/ordinal'

const toast = useToast()
const authStore = useAuthStore()
const brandingStore = useBrandingStore()

const SEMESTER_OPTIONS = Array.from({ length: 12 }, (_, i) => i + 1)
// Same searchable dropdown as the other filters; shown as 1st, 2nd, …
const semesterOptions = SEMESTER_OPTIONS.map((n) => ({ id: n, name: ordinal(n) }))

// --- Filter dropdowns ------------------------------------------------------
const filters = reactive({
  program_name: '',
  exam_term_id: '',
  exam_type_id: '',
  course_id: '',
  semester: '',
  exam_year: new Date().getFullYear(),
  // Optional — only packets uploaded under this department count as pending
  // (question_answer_sheet_mappings.department_id). Separate from the
  // teacher list's own department filter below.
  department_id: '',
})
const fieldErrors = reactive({ program_name: '', exam_term_id: '', exam_type_id: '', course_id: '', semester: '', exam_year: '' })
const fieldRefs = {
  program_name: ref(null),
  exam_term_id: ref(null),
  exam_type_id: ref(null),
  course_id: ref(null),
  semester: ref(null),
  exam_year: ref(null),
}
function clearFieldError(field) {
  fieldErrors[field] = ''
}

// Order matters — matches the filter row's own left-to-right field order,
// so the first of these (in this order) that has an error is the one that
// gets focused after a failed search.
function focusFirstError() {
  const field = REQUIRED_FILTERS.find((key) => fieldErrors[key])
  fieldRefs[field]?.value?.focus()
}

const availablePrograms = ref([])
const programsLoading = ref(true)
const programsStore = useProgramsStore()
async function loadPrograms() {
  programsLoading.value = true
  try {
    // Shown as "Name (Label)"; the value stays the plain program name.
    await programsStore.load(true)
    availablePrograms.value = programsStore.options()
  } catch {
    // Non-fatal — the dropdown just stays empty; the page itself doesn't
    // depend on this succeeding to render.
  } finally {
    programsLoading.value = false
  }
}

const availableCourses = ref([])
const coursesLoading = ref(true)
async function loadCourses() {
  coursesLoading.value = true
  try {
    const res = await api.get('/courses', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code', 'type'] } })
    availableCourses.value = res.data.data.map((course) => ({
      ...course,
      name: courseLabel(course.name, course.code, course.type),
    }))
  } catch {
    // Same as loadPrograms() above.
  } finally {
    coursesLoading.value = false
  }
}

const availableExamTerms = ref([])
const examTermsLoading = ref(true)
async function loadExamTerms() {
  examTermsLoading.value = true
  try {
    const res = await api.get('/exam-terms', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTerms.value = res.data.data
  } catch {
    // Same as loadPrograms() above.
  } finally {
    examTermsLoading.value = false
  }
}

const availableExamTypes = ref([])
const examTypesLoading = ref(true)
async function loadExamTypes() {
  examTypesLoading.value = true
  try {
    const res = await api.get('/exam-types', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
    availableExamTypes.value = res.data.data
  } catch {
    // Same as loadPrograms() above.
  } finally {
    examTypesLoading.value = false
  }
}

// --- Real backing data for the search (see the file's own docblock) ------
const allMappings = ref([])
const allPapers = ref([])
const backingDataLoading = ref(true)
const backingDataError = ref('')
async function loadBackingData() {
  backingDataLoading.value = true
  backingDataError.value = ''
  try {
    const [mappingsRes, papersRes] = await Promise.all([
      api.get('/answer-sheet-mappings', { params: { per_page: 200 } }),
      api.get('/question-papers', { params: { status: 'ready', per_page: 200 } }),
    ])
    allMappings.value = mappingsRes.data.data.items
    allPapers.value = papersRes.data.data.items
  } catch (err) {
    backingDataError.value = err.response?.data?.message || 'Could not load answer sheet data.'
  } finally {
    backingDataLoading.value = false
  }
}

const papersById = computed(() => new Map(allPapers.value.map((p) => [p.id, p])))

// --- Department filter for the teacher list below (independent of the
// exam-detail search above — this just narrows which teachers are even
// offered for distribution, e.g. to a course's own department). ---
const availableDepartments = ref([])
const departmentsLoading = ref(true)
async function loadDepartments() {
  departmentsLoading.value = true
  try {
    // A non-super-admin's own teacher list below is always restricted to
    // their own department anyway (see loadTeachers()'s own
    // department_scope=self, enforced server-side regardless of this
    // dropdown — see TeacherController::index()'s own docblock), so there's
    // no point offering them every other department here; it'd just be a
    // list of options that always return zero results.
    if (!authStore.user?.is_super_admin) {
      availableDepartments.value = authStore.user?.department_id
        ? [{ id: authStore.user.department_id, name: authStore.user.department || 'My Department' }]
        : []
      return
    }

    const res = await api.get('/departments', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'code'] } })
    // Same "Name (CODE)" convention as loadCourses() above — shown only
    // when a department actually has a code, since it's an optional field.
    availableDepartments.value = res.data.data.map((department) => ({
      ...department,
      name: department.code ? `${department.name} (${department.code})` : department.name,
    }))
  } catch {
    // Non-fatal — same reasoning as loadPrograms() above.
  } finally {
    departmentsLoading.value = false
  }
}
// Search panel's Department: every department (an older packet may belong
// to one that's since been switched off).
const packetDepartments = ref([])
const packetDepartmentsLoading = ref(true)
async function loadPacketDepartments() {
  packetDepartmentsLoading.value = true
  try {
    const res = await api.get('/departments', { params: { status: 'all', table_fields: ['name', 'code'] } })
    packetDepartments.value = res.data.data.map((d) => ({ ...d, name: d.code ? `${d.name} (${d.code})` : d.name }))
  } catch {
    packetDepartments.value = []
  } finally {
    packetDepartmentsLoading.value = false
  }
}
const packetDepartmentOptions = computed(() => [{ id: '', name: 'All Departments' }, ...packetDepartments.value])

// A synthetic "All Departments" option up front — SearchableSelect has no
// built-in way to clear back to "nothing picked" once something's chosen,
// so this is the only way back to an unfiltered teacher list.
const departmentFilterOptions = computed(() => [{ id: '', name: 'All Departments' }, ...availableDepartments.value])
const departmentFilter = ref('')

// --- Teachers --------------------------------------------------------------
const teachers = ref([])
const teachersLoading = ref(true)
async function loadTeachers() {
  teachersLoading.value = true
  try {
    const params = { per_page: 200, is_active: 'yes', department_scope: 'self' }
    if (departmentFilter.value) params.department_id = departmentFilter.value
    const res = await api.get('/teachers', { params })
    teachers.value = res.data.data.items.map((t) => ({
      id: t.id,
      name: t.name,
      emp_code: t.emp_code || '—',
      designation: t.designation || '—',
      department: t.department || '—',
      selected: true, // every active teacher starts selected — deselect to exclude one
      assignQuantity: 0,
      // Sheets still waiting (nobody started them) in shared pools this teacher is in.
      poolPendingCount: t.pool_waiting_answer_sheet_count || 0,
      allocatedCount: t.allocated_answer_sheet_count || 0,
      completedCount: t.completed_answer_sheet_count || 0,
    }))
  } catch {
    // Non-fatal — same reasoning as loadPrograms() above.
  } finally {
    teachersLoading.value = false
  }
}

// Opens TeacherAllocationModal.vue for whichever teacher's "Already
// Allocated" number was clicked — see that component's own docblock.
const allocationModalTeacher = ref(null)
function openAllocationModal(teacher) {
  allocationModalTeacher.value = teacher
}
function closeAllocationModal() {
  allocationModalTeacher.value = null
}

const teacherSearch = ref('')
const filteredTeachers = computed(() => {
  const q = teacherSearch.value.trim().toLowerCase()
  if (!q) return teachers.value
  return teachers.value.filter((t) => t.name.toLowerCase().includes(q) || t.department.toLowerCase().includes(q))
})
const selectedTeachers = computed(() => teachers.value.filter((t) => t.selected))
const allTeachersSelected = computed(() => teachers.value.length > 0 && selectedTeachers.value.length === teachers.value.length)
function toggleSelectAllTeachers() {
  const next = !allTeachersSelected.value
  teachers.value.forEach((t) => (t.selected = next))
}

// Guards against a false "no permission" flash on a hard refresh — see
// master/CoursesView.vue for why.
const permissionChecked = ref(false)
onMounted(async () => {
  if (!authStore.user) await authStore.fetchMe().catch(() => {})
  permissionChecked.value = true
  if (!authStore.can('assign-answersheet-to-teacher')) return
  loadPrograms()
  loadCourses()
  loadExamTerms()
  loadExamTypes()
  loadDepartments()
  loadPacketDepartments()
  loadBackingData()
  loadTeachers()
})

// --- Search ------------------------------------------------------------
const REQUIRED_FILTERS = ['program_name', 'exam_term_id', 'exam_type_id', 'course_id', 'semester', 'exam_year']
const searched = ref(false)
const matchingMappings = ref([])

// Shared by runSearch() and by onAssigned() below — the exact same
// five-field match, just re-run against whatever allMappings currently
// holds (a fresh fetch after an Assign, so pending counts are current).
function recomputeMatchingMappings() {
  matchingMappings.value = allMappings.value.filter((m) => {
    const paper = papersById.value.get(m.question_paper_id)
    return (
      m.program_name === filters.program_name &&
      String(m.exam_term_id) === String(filters.exam_term_id) &&
      String(m.exam_type_id) === String(filters.exam_type_id) &&
      String(m.course_id) === String(filters.course_id) &&
      Number(m.semester) === Number(filters.semester) &&
      (!filters.department_id || String(m.department_id) === String(filters.department_id)) &&
      !!paper &&
      Number(paper.exam_year) === Number(filters.exam_year)
    )
  })
}

function runSearch() {
  let ok = true
  REQUIRED_FILTERS.forEach((field) => {
    if (!filters[field]) {
      fieldErrors[field] = 'Required.'
      ok = false
    }
  })
  if (!ok) {
    toast.error('Fill in every exam detail before searching.')
    focusFirstError()
    return
  }

  recomputeMatchingMappings()
  searched.value = true

  // A fresh search invalidates any distribution already worked out for the
  // *previous* search's own pending count.
  teachers.value.forEach((t) => (t.assignQuantity = 0))

  // Searching by a department pre-selects the same department for the
  // teacher list below (still changeable there). Only when it's one of the
  // teacher list's own options — a non-super-admin only has their own.
  if (
    filters.department_id &&
    String(departmentFilter.value) !== String(filters.department_id) &&
    availableDepartments.value.some((d) => String(d.id) === String(filters.department_id))
  ) {
    departmentFilter.value = filters.department_id
    loadTeachers()
  }
}

// Pending, not total — a packet's answer_sheet_count never drops even once
// some of its sheets are assigned, so this page (whose whole job is "how
// many are left to hand out") reads pending_answer_sheet_count instead.
// See QuestionAnswerSheetMappingResource's own docblock on that field.
const pendingAnswerSheets = computed(() =>
  matchingMappings.value.reduce((sum, m) => sum + (m.pending_answer_sheet_count ?? m.answer_sheet_count ?? 0), 0),
)

// --- Distribution math (no persistence yet — see the file's own docblock).
// There's a single flow now: "Distribute Equally" fills every selected
// teacher's Assign Quantity in the table below, and the admin can then
// edit any of those numbers by hand — no separate "equal vs individual"
// mode/toggle any more. ---------------------------------------------------
const eachTeacherGets = computed(() =>
  selectedTeachers.value.length ? Math.floor(pendingAnswerSheets.value / selectedTeachers.value.length) : 0,
)
const equalRemaining = computed(() => pendingAnswerSheets.value - eachTeacherGets.value * selectedTeachers.value.length)

function distributeEqually() {
  if (!selectedTeachers.value.length) {
    toast.error('Select at least one teacher first.')
    return
  }
  if (!pendingAnswerSheets.value) {
    toast.error('No pending answer sheets to distribute for this search.')
    return
  }

  const base = eachTeacherGets.value
  const remainder = equalRemaining.value
  // The leftover that doesn't divide evenly goes one-each to the first few
  // selected teachers (plain round-robin) rather than silently vanishing.
  // Unselected teachers are zeroed out so a re-run never leaves a stale
  // quantity behind on someone who's since been excluded.
  let selectedIndex = 0
  teachers.value.forEach((t) => {
    if (!t.selected) {
      t.assignQuantity = 0
      return
    }
    t.assignQuantity = base + (selectedIndex < remainder ? 1 : 0)
    selectedIndex++
  })

  toast.success(`Split ${pendingAnswerSheets.value} answer sheet(s) equally across ${selectedTeachers.value.length} teacher(s) — adjust any quantity below if needed. Not saved yet.`)
}

// "distribution" — fixed quantities per teacher (below); "pool" — every
// pending sheet is shared by all selected teachers and whoever starts a
// sheet first gets it (AssignTeacherService::assignPool() on the backend).
const assignMode = ref('distribution')
const isPoolMode = computed(() => assignMode.value === 'pool')

// Pool mode: how many of the pending sheets go into the pool — whole
// numbers only, 1..pending. Starts at "all of them" and is pulled back
// down if a new search has fewer pending.
const poolQuantity = ref('')
const poolQuantityError = ref('')
const poolQuantityRef = ref(null)
watch([isPoolMode, pendingAnswerSheets], ([pool, pending]) => {
  if (!pool) return
  const current = Number(poolQuantity.value)
  if (!poolQuantity.value || !Number.isInteger(current) || current > pending) poolQuantity.value = pending ? String(pending) : ''
})
// Blocks the keys that would make a non-whole number (decimal point, sign, exponent).
function blockNonIntegerKeys(e) {
  if (['.', ',', 'e', 'E', '+', '-'].includes(e.key)) e.preventDefault()
}
const poolSheetCount = computed(() => {
  const n = Number(poolQuantity.value)
  return Number.isInteger(n) && n > 0 ? Math.min(n, pendingAnswerSheets.value) : pendingAnswerSheets.value
})

const totalAssignedQuantity = computed(() => teachers.value.reduce((sum, t) => sum + (Number(t.assignQuantity) || 0), 0))
const assignmentRemaining = computed(() => pendingAnswerSheets.value - totalAssignedQuantity.value)

// The evaluation window every sheet this "Assign" click touches gets
// stamped with (see AssignTeacherService::assign()'s own docblock on the
// backend) — a future "change timings" screen will let these be edited per
// sheet/teacher after the fact, but every assignment needs a starting
// window up front.
const evaluationStartDate = ref('')
const evaluationEndDate = ref('')
// Expected minutes to evaluate one answer sheet in this assignment — a
// whole number only (see AssignTeacherController's own 'integer' rule),
// entered as plain text so a stray decimal point can be caught and
// reported the same styled way as every other field here, instead of a
// native <input type="number"> silently rounding or blocking input.
const evaluationTimePerSheet = ref('')
const evaluationDateErrors = reactive({ evaluation_start_date: '', evaluation_end_date: '', evaluation_time_per_sheet: '' })
const evaluationStartDateRef = ref(null)
const evaluationEndDateRef = ref(null)
const evaluationTimePerSheetRef = ref(null)

// Today, in the same ISO 'YYYY-MM-DD' shape DatePicker's own v-model/min
// use — passed as the start picker's `min` so a past date can't be picked
// at all (rather than only being caught at submit time). Computed once
// per page load, same as DatePicker.vue's own `today`.
const todayIso = (() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})()

// If the start date moves to *after* an already-picked end date, that end
// date is now invalid (and, being before the end picker's own :min, would
// otherwise just sit there selected but unclickable-to-reselect) — clear
// it so the admin has to consciously re-pick an end date that actually
// still works, instead of silently submitting a stale one that only gets
// rejected at submit time.
watch(evaluationStartDate, (newStart) => {
  if (evaluationEndDate.value && newStart && evaluationEndDate.value < newStart) {
    evaluationEndDate.value = ''
  }
})

// Validation gate before "Assign" actually commits anything — once it
// passes, a "Send Mail and Assign" modal opens (pre-filled subject/body)
// instead of posting right away; the modal itself is what calls POST
// /assign-teacher (see SendAssignmentEmailModal.vue) so an admin always
// gets a chance to review the email before the assignment is persisted.
const showSendMailModal = ref(false)
const pendingAssignPayload = ref(null)
// availableCourses' own name already carries " (CODE)" (see loadCourses())
// — reused as-is for the email preview instead of re-deriving it.
const assignEmailCourseName = computed(() => availableCourses.value.find((c) => c.id === filters.course_id)?.name || '')

function assignPapers() {
  if (isPoolMode.value) {
    if (selectedTeachers.value.length < 2) {
      toast.error('Select at least 2 teachers to share the pool.')
      return
    }
    if (!pendingAnswerSheets.value) {
      toast.error('No pending answer sheets to put in a pool for this search.')
      return
    }
    const raw = String(poolQuantity.value ?? '').trim()
    if (!/^\d+$/.test(raw) || Number(raw) < 1) {
      poolQuantityError.value = 'Whole number only (e.g. 50) — no decimals.'
    } else if (Number(raw) > pendingAnswerSheets.value) {
      poolQuantityError.value = `Only ${pendingAnswerSheets.value} pending.`
    } else {
      poolQuantityError.value = ''
    }
    if (poolQuantityError.value) {
      toast.error('Enter how many answer sheets go into the pool.')
      poolQuantityRef.value?.focus()
      return
    }
  } else if (!totalAssignedQuantity.value) {
    toast.error('Enter at least one quantity, or click "Distribute Equally" first.')
    return
  } else if (totalAssignedQuantity.value > pendingAnswerSheets.value) {
    toast.error(`Assigned total (${totalAssignedQuantity.value}) is more than the ${pendingAnswerSheets.value} pending answer sheet(s).`)
    return
  }

  evaluationDateErrors.evaluation_start_date = evaluationStartDate.value ? '' : 'Required.'
  evaluationDateErrors.evaluation_end_date = evaluationEndDate.value ? '' : 'Required.'
  if (evaluationStartDate.value && evaluationEndDate.value && evaluationEndDate.value < evaluationStartDate.value) {
    evaluationDateErrors.evaluation_end_date = 'End date cannot be before the start date.'
  }
  // Optional — leave blank for no per-sheet time limit (see
  // EvaluatePaperView.vue's totalMinutes, which already treats a null
  // evaluation_time_per_sheet as "no limit"). If given at all, still whole
  // minutes only — no decimal point, no letters. Matches the backend's own
  // 'nullable', 'integer' rule (see AssignTeacherController).
  const timeValue = evaluationTimePerSheet.value.trim()
  if (timeValue && (!/^\d+$/.test(timeValue) || Number(timeValue) < 1)) {
    evaluationDateErrors.evaluation_time_per_sheet = 'Whole minutes only (e.g. 60) — no decimals.'
  } else {
    evaluationDateErrors.evaluation_time_per_sheet = ''
  }

  if (evaluationDateErrors.evaluation_start_date || evaluationDateErrors.evaluation_end_date || evaluationDateErrors.evaluation_time_per_sheet) {
    toast.error('Set the evaluation start date and end date before assigning.')
    // Same "focus the first invalid field" convention as every other
    // form in this app — left-to-right order across the row.
    if (evaluationDateErrors.evaluation_start_date) evaluationStartDateRef.value?.focus()
    else if (evaluationDateErrors.evaluation_end_date) evaluationEndDateRef.value?.focus()
    else evaluationTimePerSheetRef.value?.focus()
    return
  }

  const assignedTeachers = teachers.value.filter((t) => Number(t.assignQuantity) > 0)
  pendingAssignPayload.value = {
    program_name: filters.program_name,
    exam_term_id: filters.exam_term_id,
    exam_type_id: filters.exam_type_id,
    course_id: filters.course_id,
    semester: filters.semester,
    exam_year: filters.exam_year,
    department_id: filters.department_id || null,
    evaluation_start_date: evaluationStartDate.value,
    evaluation_end_date: evaluationEndDate.value,
    evaluation_time_per_sheet: timeValue ? Number(timeValue) : null,
    ...(isPoolMode.value
      ? { mode: 'pool', teacher_ids: selectedTeachers.value.map((t) => t.id), pool_quantity: Number(poolQuantity.value) }
      : { assignments: assignedTeachers.map((t) => ({ teacher_id: t.id, quantity: Number(t.assignQuantity) })) }),
  }
  showSendMailModal.value = true
}

// Fires once SendAssignmentEmailModal.vue's own POST /assign-teacher call
// has actually committed the assignment. Two things happen:
//  1. Pending sheet counts and per-teacher allocation/completed totals are
//     re-pulled from the server (not patched by hand) so a *later* search
//     against the same course never shows a stale, too-high pending count
//     — the exact bug this whole refresh exists to prevent.
//  2. The page itself resets all the way back to the blank "Select Exam
//     Details" search form (see resetSearchForm()) — this assignment is
//     done, and the admin's next click is almost always a fresh search for
//     a different course, not staring at zeroed-out leftovers from the one
//     they just finished.
async function onAssigned(resData) {
  toast.success(resData.message || 'Assignment saved successfully.')

  await Promise.all([loadBackingData(), loadTeachers()])
  resetSearchForm()
}

function resetSearchForm() {
  filters.program_name = ''
  filters.exam_term_id = ''
  filters.exam_type_id = ''
  filters.course_id = ''
  filters.semester = ''
  filters.exam_year = new Date().getFullYear()
  filters.department_id = ''
  fieldErrors.program_name = ''
  fieldErrors.exam_term_id = ''
  fieldErrors.exam_type_id = ''
  fieldErrors.course_id = ''
  fieldErrors.semester = ''
  fieldErrors.exam_year = ''
  departmentFilter.value = ''
  teacherSearch.value = ''
  evaluationStartDate.value = ''
  evaluationEndDate.value = ''
  evaluationTimePerSheet.value = ''
  poolQuantity.value = ''
  poolQuantityError.value = ''
  evaluationDateErrors.evaluation_start_date = ''
  evaluationDateErrors.evaluation_end_date = ''
  evaluationDateErrors.evaluation_time_per_sheet = ''
  searched.value = false
  matchingMappings.value = []
}
</script>

<template>
  <p v-if="!permissionChecked" class="text-center text-sm text-muted py-10">Loading&hellip;</p>
  <div v-else-if="!authStore.can('assign-answersheet-to-teacher')" class="bg-white rounded-2xl shadow-panel p-10 text-center">
    <p class="text-[15px] font-semibold text-gray-900">You don't have permission to view this page.</p>
    <p class="mt-1 text-[13px] text-muted">Contact an administrator if you think this is a mistake.</p>
  </div>
  <div v-else>
    <!-- Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-panel px-4 sm:px-5 py-2 mb-5 flex items-center gap-3">
      <RouterLink to="/dashboard" class="inline-flex items-center gap-2 text-[13px] sm:text-sm text-gray-700 hover:text-brand-blue">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z" /></svg>
        Home
      </RouterLink>
      <span class="w-px h-4 bg-gray-300 shrink-0"></span>
      <span class="text-[13px] sm:text-sm text-gray-700">Assign Teacher</span>
    </div>

    <div class="max-w-[1500px] mx-auto flex flex-col gap-3">
      <!-- Page header -->
      <div class="flex items-start gap-3">
        <span class="mt-0.5 w-11 h-11 rounded-[10px] p-[1.5px] bg-btn-gradient shadow-sm flex items-center justify-center shrink-0">
          <span class="w-full h-full rounded-[8.5px] bg-white flex items-center justify-center">
            <svg class="w-5 h-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <rect x="9" y="2" width="6" height="4" rx="1" />
              <path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4" />
              <path d="M9 14l2 2 4-4" />
            </svg>
          </span>
        </span>
        <div>
          <h1 class="text-[20px] sm:text-[24px] font-semibold text-brand leading-tight">Assign Teacher</h1>
          <p class="mt-1 text-[13px] sm:text-sm text-muted">Select program, semester, and course to distribute answer sheets to teachers.</p>
        </div>
      </div>

      <!-- Select Exam Details -->
      <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4">
        <div class="flex items-center gap-2 mb-2.5">
          <!-- <span class="w-5 h-5 rounded-full bg-brand-blue text-white text-[11px] font-bold flex items-center justify-center shrink-0">1</span> -->
          <h2 class="text-[14px] sm:text-[15px] font-semibold text-gray-900">Select Exam Details</h2>
        </div>

        <!-- Row 1: Program · Course · Exam Type · Exam Term. Program and
             Course get more width (their names are long); row 2 uses the
             same column widths so the fields line up. -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5">
          <div class="flex flex-col gap-1">
            <label for="assign_program" class="text-[12px] text-label">Program <span class="text-brand">*</span></label>
            <SearchableSelect
              id="assign_program"
              :ref="(el) => (fieldRefs.program_name.value = el)"
              v-model="filters.program_name"
              :options="availablePrograms"
              :loading="programsLoading"
              :error="!!fieldErrors.program_name"
              placeholder="Select program"
              search-placeholder="Search programs…"
              @change="clearFieldError('program_name')"
            />
            <p v-if="fieldErrors.program_name" class="text-[12px] text-brand">{{ fieldErrors.program_name }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <label for="assign_course" class="text-[12px] text-label">Course <span class="text-brand">*</span></label>
            <SearchableSelect
              id="assign_course"
              :ref="(el) => (fieldRefs.course_id.value = el)"
              v-model="filters.course_id"
              :options="availableCourses"
              :loading="coursesLoading"
              :error="!!fieldErrors.course_id"
              placeholder="Select course"
              search-placeholder="Search courses…"
              @change="clearFieldError('course_id')"
            />
            <p v-if="fieldErrors.course_id" class="text-[11px] text-brand">{{ fieldErrors.course_id }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <label for="assign_exam_type" class="text-[12px] text-label">Exam Type <span class="text-brand">*</span></label>
            <SearchableSelect
              id="assign_exam_type"
              :ref="(el) => (fieldRefs.exam_type_id.value = el)"
              v-model="filters.exam_type_id"
              :options="availableExamTypes"
              :loading="examTypesLoading"
              :error="!!fieldErrors.exam_type_id"
              placeholder="Select"
              search-placeholder="Search exam types…"
              @change="clearFieldError('exam_type_id')"
            />
            <p v-if="fieldErrors.exam_type_id" class="text-[11px] text-brand">{{ fieldErrors.exam_type_id }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <label for="assign_exam_term" class="text-[12px] text-label">Exam Term <span class="text-brand">*</span></label>
            <SearchableSelect
              id="assign_exam_term"
              :ref="(el) => (fieldRefs.exam_term_id.value = el)"
              v-model="filters.exam_term_id"
              :options="availableExamTerms"
              :loading="examTermsLoading"
              :error="!!fieldErrors.exam_term_id"
              placeholder="Select"
              search-placeholder="Search exam terms…"
              @change="clearFieldError('exam_term_id')"
            />
            <p v-if="fieldErrors.exam_term_id" class="text-[11px] text-brand">{{ fieldErrors.exam_term_id }}</p>
          </div>
        </div>

        <!-- Row 2: Semester · Exam Year · Department · Search -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.4fr_1.4fr_1fr_1fr] gap-2.5 mt-2.5">
          <div class="flex flex-col gap-1">
            <label for="assign_semester" class="text-[12px] text-label">Semester <span class="text-brand">*</span></label>
            <SearchableSelect
              id="assign_semester"
              :ref="(el) => (fieldRefs.semester.value = el)"
              v-model="filters.semester"
              :options="semesterOptions"
              :error="!!fieldErrors.semester"
              placeholder="Select"
              search-placeholder="Search semesters…"
              @change="clearFieldError('semester')"
            />
            <p v-if="fieldErrors.semester" class="text-[11px] text-brand">{{ fieldErrors.semester }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <label for="assign_exam_year" class="text-[12px] text-label">Exam Year <span class="text-brand">*</span></label>
            <input
              id="assign_exam_year"
              :ref="(el) => (fieldRefs.exam_year.value = el)"
              v-model="filters.exam_year"
              type="number"
              placeholder="e.g. 2026"
              @input="clearFieldError('exam_year')"
              class="w-full h-10 px-3 rounded-xl bg-input-bg text-sm text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
              :class="fieldErrors.exam_year ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
            />
            <p v-if="fieldErrors.exam_year" class="text-[11px] text-brand">{{ fieldErrors.exam_year }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <label for="assign_department" class="text-[12px] text-label">Department</label>
            <SearchableSelect
              id="assign_department"
              v-model="filters.department_id"
              :options="packetDepartmentOptions"
              :loading="packetDepartmentsLoading"
              placeholder="All departments"
              search-placeholder="Search departments…"
            />
          </div>

          <div class="flex flex-col gap-1">
            <label class="text-[12px] text-label invisible" aria-hidden="true">Search</label>
            <button
              type="button"
              class="h-10 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-5 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed self-start"
              :disabled="backingDataLoading"
              @click="runSearch"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
              Search
            </button>
          </div>
        </div>
        <p v-if="backingDataError" class="text-[11px] text-brand mt-1.5 text-right">{{ backingDataError }}</p>
      </section>

      <template v-if="searched">
       

        <!-- Distribution section -->
        <section class="bg-white rounded-[20px] shadow-card p-3 sm:p-4">
          <p v-if="teachersLoading" class="text-[13px] text-muted text-center py-8">Loading teachers&hellip;</p>

          <template v-else>
            <!-- Distribution (fixed quantities) or Pool (shared, first to start gets it). -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-0.5">
              <div class="flex items-center gap-2">
                <h2 class="text-[13px] font-semibold text-gray-900">{{ isPoolMode ? 'Pool Answer Sheets' : 'Distribute Answer Sheets' }}</h2>
                <span v-if="!isPoolMode" class="inline-flex items-center rounded-full bg-success/10 text-success text-[10px] font-semibold px-2 py-0.5">Equal split, then editable</span>
                <span v-else class="inline-flex items-center rounded-full bg-brand-blue/10 text-brand-blue text-[10px] font-semibold px-2 py-0.5">Shared, first to start gets it</span>
              </div>
              <div class="inline-flex rounded-lg border border-input-border bg-input-bg p-0.5 text-[12px] font-semibold" role="radiogroup" aria-label="Assignment mode">
                <button
                  v-for="mode in [{ id: 'distribution', label: 'Distribution' }, { id: 'pool', label: 'Pool' }]"
                  :key="mode.id"
                  type="button"
                  role="radio"
                  :aria-checked="assignMode === mode.id"
                  class="px-3 h-7 rounded-md transition-colors"
                  :class="assignMode === mode.id ? 'bg-white text-brand-blue shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                  @click="assignMode = mode.id"
                >
                  {{ mode.label }}
                </button>
              </div>
            </div>
            <p v-if="!isPoolMode" class="text-[12px] text-muted mb-3">Click "Distribute Equally" to split papers evenly across selected teachers below, then fine-tune any teacher's quantity by hand.</p>
            <p v-else class="text-[12px] text-muted mb-3">Every pending answer sheet goes into one pool shared by the selected teachers. Each of them sees all the sheets; the first teacher to start a sheet gets it, and it disappears for the others.</p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
              <div class="rounded-lg bg-input-bg p-2">
                <p class="text-[10px] text-muted mb-0.5">Pending answer sheets</p>
                <p class="text-[13px] font-bold text-gray-900">{{ pendingAnswerSheets }}</p>
              </div>
              <div class="rounded-lg bg-input-bg p-2">
                <p class="text-[10px] text-muted mb-0.5">Selected teachers</p>
                <p class="text-[13px] font-bold text-gray-900">{{ selectedTeachers.length }}</p>
              </div>
              <template v-if="!isPoolMode">
                <div class="rounded-lg bg-input-bg p-2">
                  <p class="text-[10px] text-muted mb-0.5">Each teacher gets</p>
                  <p class="text-[13px] font-bold text-gray-900">{{ eachTeacherGets }}</p>
                </div>
                <div class="rounded-lg bg-input-bg p-2">
                  <p class="text-[10px] text-muted mb-0.5">Remaining</p>
                  <p class="text-[13px] font-bold text-gray-900">{{ equalRemaining }}</p>
                </div>
              </template>
              <div v-else class="rounded-lg bg-input-bg p-2 col-span-2">
                <p class="text-[10px] text-muted mb-0.5">Each selected teacher sees</p>
                <p class="text-[13px] font-bold text-gray-900">{{ poolSheetCount }} sheet{{ poolSheetCount === 1 ? '' : 's' }} (shared)</p>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
              <!-- Teacher search + the teacher list's own Department filter, same line. -->
              <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <input
                  v-model="teacherSearch"
                  type="text"
                  placeholder="Search teacher…"
                  class="w-full sm:w-56 h-8 px-3 rounded-lg bg-input-bg text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue focus:ring-2 focus:ring-brand-blue/15 transition"
                />
                <div class="w-full sm:w-64">
                  <SearchableSelect
                    id="teacher_department"
                    v-model="departmentFilter"
                    :options="departmentFilterOptions"
                    :loading="departmentsLoading"
                    dense
                    placeholder="All departments"
                    search-placeholder="Search departments…"
                    aria-label="Teacher department"
                    @change="loadTeachers"
                  />
                </div>
              </div>

              <button
                v-if="!isPoolMode"
                type="button"
                class="h-9 shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 hover:opacity-90 transition-opacity"
                @click="distributeEqually"
              >
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg>
                Distribute Equally
              </button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-soft mb-3">
              <table class="w-full text-left text-[12px] min-w-[560px]">
                <thead>
                  <tr class="bg-subject-header text-white">
                    <th class="px-2.5 py-1.5 w-9">
                      <input type="checkbox" :checked="allTeachersSelected" class="w-3.5 h-3.5 cursor-pointer" @change="toggleSelectAllTeachers" />
                    </th>
                    <th class="px-2.5 py-1.5 font-medium">Teacher Name</th>
                    <th class="px-2.5 py-1.5 font-medium">Emp Code</th>
                    <th class="px-2.5 py-1.5 font-medium">Department</th>
                    <th class="px-2.5 py-1.5 font-medium">Designation</th>
                    <th class="px-2.5 py-1.5 font-medium text-center" title="Sheets waiting in shared pools this teacher is in — not started by anyone yet">Pending Pool</th>
                    <th class="px-2.5 py-1.5 font-medium text-center">Already Allocated</th>
                    <th class="px-2.5 py-1.5 font-medium text-center">Completed</th>
                    <th v-if="!isPoolMode" class="px-2.5 py-1.5 font-medium text-center">Assign Quantity</th>
                  </tr>
                </thead>
                <tbody class="bg-white">
                  <tr v-if="!filteredTeachers.length">
                    <td :colspan="isPoolMode ? 8 : 9" class="px-2.5 py-6 text-center text-muted">No teachers found.</td>
                  </tr>
                  <tr v-for="teacher in filteredTeachers" :key="teacher.id" class="border-b border-gray-100 last:border-b-0 even:bg-gray-50">
                    <td class="px-2.5 py-1.5">
                      <input v-model="teacher.selected" type="checkbox" class="w-3.5 h-3.5 cursor-pointer" />
                    </td>
                    <td class="px-2.5 py-1.5 font-medium">{{ teacher.name }}</td>
                    <td class="px-2.5 py-1.5">{{ teacher.emp_code }}</td>
                    <td class="px-2.5 py-1.5">{{ teacher.department }}</td>
                    <td class="px-2.5 py-1.5">{{ teacher.designation }}</td>
                    <td class="px-2.5 py-1.5 text-center font-semibold" :class="teacher.poolPendingCount ? 'text-cyan-700' : 'text-gray-400'">{{ teacher.poolPendingCount }}</td>
                    <td class="px-2.5 py-1.5 text-center">
                      <button
                        type="button"
                        class="font-semibold text-brand-blue hover:underline disabled:text-gray-400 disabled:no-underline disabled:cursor-default"
                        :disabled="!teacher.allocatedCount"
                        @click="openAllocationModal(teacher)"
                      >
                        {{ teacher.allocatedCount }}
                      </button>
                    </td>
                    <td class="px-2.5 py-1.5 text-center font-semibold text-success">{{ teacher.completedCount }}</td>
                    <td v-if="!isPoolMode" class="px-2.5 py-1.5 text-center">
                      <input
                        v-model.number="teacher.assignQuantity"
                        type="number"
                        min="0"
                        :disabled="!teacher.selected"
                        class="w-16 h-7 px-1.5 rounded-md bg-input-bg text-center text-[12px] text-gray-800 outline-none border border-input-border focus:border-brand-blue transition disabled:opacity-50 disabled:cursor-not-allowed"
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex flex-wrap items-start gap-2.5">
              <div v-if="!isPoolMode" class="flex flex-col gap-1">
                <span class="text-[11px] text-label invisible" aria-hidden="true">Stats</span>
                <div class="h-8 flex items-center gap-3 text-[12px] whitespace-nowrap">
                  <span><span class="text-muted">Total Assigned</span> <span class="font-bold text-gray-900">{{ totalAssignedQuantity }}</span></span>
                  <span><span class="text-muted">Remaining</span> <span class="font-bold text-gray-900">{{ assignmentRemaining }}</span></span>
                </div>
              </div>

              <div class="flex flex-col gap-1 w-44">
                <label for="assign_evaluation_start_date" class="text-[11px] text-label">Evaluation Start <span class="text-brand">*</span></label>
                <DatePicker
                  id="assign_evaluation_start_date"
                  ref="evaluationStartDateRef"
                  v-model="evaluationStartDate"
                  dense
                  with-time
                  :min="todayIso"
                  :error="!!evaluationDateErrors.evaluation_start_date"
                  @change="evaluationDateErrors.evaluation_start_date = ''"
                />
                <p v-if="evaluationDateErrors.evaluation_start_date" class="text-[11px] text-brand">{{ evaluationDateErrors.evaluation_start_date }}</p>
              </div>
              <div class="flex flex-col gap-1 w-44">
                <label for="assign_evaluation_end_date" class="text-[11px] text-label">Evaluation End <span class="text-brand">*</span></label>
                <DatePicker
                  id="assign_evaluation_end_date"
                  ref="evaluationEndDateRef"
                  v-model="evaluationEndDate"
                  dense
                  with-time
                  :min="evaluationStartDate"
                  :error="!!evaluationDateErrors.evaluation_end_date"
                  @change="evaluationDateErrors.evaluation_end_date = ''"
                />
                <p v-if="evaluationDateErrors.evaluation_end_date" class="text-[11px] text-brand">{{ evaluationDateErrors.evaluation_end_date }}</p>
              </div>

              <div class="flex flex-col gap-1 w-36">
                <label for="assign_evaluation_time_per_sheet" class="text-[11px] text-label">Eval. Time (min)</label>
                <input
                  id="assign_evaluation_time_per_sheet"
                  ref="evaluationTimePerSheetRef"
                  v-model="evaluationTimePerSheet"
                  type="text"
                  inputmode="numeric"
                  placeholder="No limit if blank"
                  class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[11px] text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="evaluationDateErrors.evaluation_time_per_sheet ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                  @input="evaluationDateErrors.evaluation_time_per_sheet = ''"
                />
                <p v-if="evaluationDateErrors.evaluation_time_per_sheet" class="text-[11px] text-brand">{{ evaluationDateErrors.evaluation_time_per_sheet }}</p>
              </div>

              <!-- Pool mode: how many pending sheets go into the pool. -->
              <div v-if="isPoolMode" class="flex flex-col gap-1 w-32 ml-auto">
                <label for="assign_pool_quantity" class="text-[11px] text-label">Sheets in Pool <span class="text-brand">*</span></label>
                <input
                  id="assign_pool_quantity"
                  ref="poolQuantityRef"
                  v-model="poolQuantity"
                  type="number"
                  min="1"
                  :max="pendingAnswerSheets"
                  step="1"
                  inputmode="numeric"
                  class="w-full h-8 px-2.5 rounded-xl bg-input-bg text-[12px] font-semibold text-gray-800 outline-none border focus:ring-2 focus:ring-brand-blue/15 transition"
                  :class="poolQuantityError ? 'border-brand' : 'border-input-border focus:border-brand-blue'"
                  @keydown="blockNonIntegerKeys"
                  @input="poolQuantityError = ''"
                />
                <p v-if="poolQuantityError" class="text-[11px] text-brand">{{ poolQuantityError }}</p>
                <p v-else class="text-[10.5px] text-muted">of {{ pendingAnswerSheets }} pending</p>
              </div>

              <div v-if="authStore.can('assign-answersheet-to-teacher')" class="flex flex-col gap-1" :class="isPoolMode ? '' : 'ml-auto'">
                <span class="text-[11px] text-label invisible" aria-hidden="true">Assign</span>
                <button
                  type="button"
                  class="h-9 inline-flex items-center gap-2 rounded-xl bg-btn-gradient text-white text-[13px] font-semibold px-4 hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed"
                  @click="assignPapers"
                >
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12" /></svg>
                  {{ isPoolMode ? 'Assign as Pool' : 'Assign' }}
                </button>
              </div>
            </div>
          </template>
        </section>
      </template>
    </div>

    <TeacherAllocationModal v-if="allocationModalTeacher" :teacher="allocationModalTeacher" @close="closeAllocationModal" />

    <SendAssignmentEmailModal
      v-if="showSendMailModal"
      endpoint="/assign-teacher"
      action-word="Assign"
      :payload="pendingAssignPayload"
      :course-name="assignEmailCourseName"
      :evaluation-start-date-display="formatDateTime(evaluationStartDate)"
      :evaluation-end-date-display="formatDateTime(evaluationEndDate)"
      :site-title="brandingStore.siteTitleValue"
      :pool="isPoolMode"
      @close="showSendMailModal = false"
      @assigned="onAssigned"
    />
  </div>
</template>
