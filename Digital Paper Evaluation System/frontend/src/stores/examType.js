import { defineStore } from 'pinia'
import api from '../utils/api'

const STORAGE_KEY = 'selected_exam_type_id'

// The Examination picker in AppHeader.vue — the exam-type counterpart to
// stores/examYear.js (see that store's own docblock for the shared shape:
// same four pages use it — Question Papers is the one exception there
// that does NOT apply here, since a question paper itself has no
// exam_type_id at all; only a packet/QuestionAnswerSheetMapping does, so
// this store only backs My Pending Course, My Completed Course, Assigned
// Teacher List, and Admin Dashboard).
//
// Unlike exam years, exam types aren't a plain calendar spread that can
// be synthesized client-side — they're real master-data rows (see
// ExamTypeController) — so this store has to actually fetch them, once,
// via load(). A non-super-admin is always scoped to whichever one this
// holds; defaulting to the *first* option returned (see load()) once
// loaded, same as the backend's own HasExamTypeScope trait defaults a
// non-super-admin who sent nothing to the most-recently-created active
// exam type — GET /exam-types with no explicit sort returns exactly that
// order already, so both sides agree without either one hard-coding the
// other's logic. A super admin sees every exam type unless they
// deliberately pick one here too.
export const useExamTypeStore = defineStore('examType', {
  state: () => ({
    selectedId: Number(localStorage.getItem(STORAGE_KEY)) || null,
    types: [],
    loading: false,
    loaded: false,
  }),
  actions: {
    async load() {
      if (this.loaded || this.loading) return
      this.loading = true
      try {
        const res = await api.get('/exam-types', { params: { status: 'all', is_active: 'yes', table_fields: ['name'] } })
        this.types = res.data.data
        this.loaded = true
        // A stale id from an exam type since deleted/deactivated would
        // otherwise sit in selectedId forever with nothing in the
        // dropdown actually showing as selected — fall back to the first
        // option whenever the current selection isn't (or is no longer)
        // valid.
        if (!this.types.some((t) => t.id === this.selectedId)) {
          this.setType(this.types[0]?.id ?? null)
        }
      } catch {
        // Non-fatal — the picker just stays empty; pages that read
        // selectedId simply get null (no exam-type filter applied) until
        // a retry succeeds.
      } finally {
        this.loading = false
      }
    },
    setType(id) {
      const parsed = id === null || id === '' ? null : Number(id)
      this.selectedId = Number.isInteger(parsed) ? parsed : null
      try {
        if (this.selectedId !== null) {
          localStorage.setItem(STORAGE_KEY, String(this.selectedId))
        } else {
          localStorage.removeItem(STORAGE_KEY)
        }
      } catch {
        // Private browsing / storage disabled — the picker still works
        // for the rest of this session, just doesn't persist.
      }
    },
  },
})
