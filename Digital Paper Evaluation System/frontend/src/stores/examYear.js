import { defineStore } from 'pinia'

const STORAGE_KEY = 'selected_exam_year'

// The Exam Year picker in AppHeader.vue — shared app-wide so every page
// that cares reads the same one value instead of each keeping its own.
// Only three pages actually *use* it for filtering (Question Papers,
// Assigned Teacher List, My Pending Course — see each view's own
// fetch function) — master data, teachers, students, and configuration
// pages ignore it entirely, by design.
//
// A non-super-admin is always scoped to whichever year this holds
// (defaulting to the current calendar year); a super admin sees every
// year unless they deliberately pick one here too. See each of those
// three views' own docblocks, and the backend's HasExamYearScope trait
// for the two endpoints that also enforce this server-side (Assigned
// Teacher List and My Pending Course — Question Papers' own endpoint is
// shared with AssignTeacherView.vue's unrelated bulk load, so it can't be
// forced there without breaking that page; see QuestionPaperController::
// index() itself).
export const useExamYearStore = defineStore('examYear', {
  state: () => ({
    selectedYear: Number(localStorage.getItem(STORAGE_KEY)) || new Date().getFullYear(),
  }),
  getters: {
    // A plain, static +/-N spread around "now" — no backend round-trip
    // needed just to populate a dropdown.
    years: () => {
      const current = new Date().getFullYear()
      return Array.from({ length: 6 }, (_, i) => current - 1 + i)
    },
  },
  actions: {
    setYear(year) {
      const parsed = Number(year)
      if (!Number.isInteger(parsed)) return
      this.selectedYear = parsed
      try {
        localStorage.setItem(STORAGE_KEY, String(parsed))
      } catch {
        // Private browsing / storage disabled — the picker still works
        // for the rest of this session, just doesn't persist.
      }
    },
  },
})
