// The Course dropdown that follows the Program dropdown — used on Assign
// Teacher, Generate Marksheet and the reports (same idea as Generate QR's
// own Course list): once a program is picked, only the courses mapped to it
// (Programs master → courses) are offered. A program with no courses mapped
// yet offers every course, so nothing becomes unsearchable.
//
// Program dropdowns here store the plain program *name* (see
// stores/programs.js), and one name can belong to more than one program
// (e.g. UG and PG) — their mapped courses are merged.
import { computed, ref, watch } from 'vue'
import api from '../utils/api'

let courseIdsByName = null // Promise<Map<programName, Set<courseId>>>, shared by every page

function loadCourseIdsByName() {
  courseIdsByName ??= api
    .get('/programs', { params: { status: 'all' }, skipLoader: true }) // no table_fields → each program comes with its courses
    .then((res) => {
      const map = new Map()
      for (const program of res.data.data || []) {
        if (!map.has(program.name)) map.set(program.name, new Set())
        ;(program.courses || []).forEach((course) => map.get(program.name).add(String(course.id)))
      }
      return map
    })
    .catch(() => {
      courseIdsByName = null // try again next time
      return new Map()
    })
  return courseIdsByName
}

/**
 * @param {object} filters          the page's reactive filters ({ program_name, course_id, … })
 * @param {import('vue').Ref<Array>} allCourses  every course option ({ id, name })
 * @returns {import('vue').ComputedRef<Array>}   the Course dropdown's options
 */
export function useProgramCourses(filters, allCourses) {
  const map = ref(new Map())
  loadCourseIdsByName().then((m) => (map.value = m))

  const courseOptions = computed(() => {
    const mapped = filters.program_name ? map.value.get(filters.program_name) : null
    if (!mapped || !mapped.size) return allCourses.value
    return allCourses.value.filter((course) => mapped.has(String(course.id)))
  })

  // A course that isn't under the newly picked program is cleared.
  watch(courseOptions, (options) => {
    if (filters.course_id && !options.some((o) => String(o.id) === String(filters.course_id))) filters.course_id = ''
  })

  return courseOptions
}
