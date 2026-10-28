// The optional Exam Sub Type dropdown on the report pages: only the sub
// types of the picked Exam Type / Exam Term (each sub type belongs to one of
// each — exam_sub_types.exam_type_id / exam_term_id, our own ids). Before
// either is picked, every sub type is offered.
import { computed, ref, watch } from 'vue'
import api from '../utils/api'

let allSubTypes = null // Promise<Array>, shared by every page

function loadAll() {
  allSubTypes ??= api
    .get('/exam-sub-types', { params: { status: 'all', is_active: 'yes', table_fields: ['name', 'exam_type_id', 'exam_term_id'] }, skipLoader: true })
    .then((res) => res.data.data || [])
    .catch(() => {
      allSubTypes = null // try again next time
      return []
    })
  return allSubTypes
}

/**
 * @param {object} filters  the page's reactive filters ({ exam_type_id, exam_term_id, exam_sub_type_id, … })
 * @returns {{ options: import('vue').ComputedRef<Array>, loading: import('vue').Ref<boolean> }}
 */
export function useExamSubTypes(filters) {
  const subTypes = ref([])
  const loading = ref(true)
  loadAll().then((rows) => {
    subTypes.value = rows
    loading.value = false
  })

  const options = computed(() => subTypes.value.filter((s) =>
    (!filters.exam_type_id || String(s.exam_type_id) === String(filters.exam_type_id))
    && (!filters.exam_term_id || String(s.exam_term_id) === String(filters.exam_term_id))))

  // A sub type that isn't under the newly picked exam type / term is cleared.
  watch(options, (list) => {
    if (filters.exam_sub_type_id && !list.some((o) => String(o.id) === String(filters.exam_sub_type_id))) filters.exam_sub_type_id = ''
  })

  return { options, loading }
}
