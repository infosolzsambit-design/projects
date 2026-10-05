import { defineStore } from 'pinia'
import api from '../utils/api'

// Program names are shown everywhere as "Name (Label)", e.g.
// "Bachelor of Science (B.Sc) (UG)", while every screen still stores and
// filters by the plain program name (packets, students and report filters
// all keep program_name as a string). This store loads the programs once
// and is the one place that turns a name into that display text.
//
// A name that exists under more than one label (allowed — programs are
// unique by name + code + label) shows all of them, "Name (UG / PG)",
// since the stored name alone can't tell them apart.
export const useProgramsStore = defineStore('programs', {
  state: () => ({
    programs: [], // [{ name, label, status }]
    loaded: false,
    loadingPromise: null,
  }),
  getters: {
    labelsByName(state) {
      const map = new Map()
      state.programs.forEach((p) => {
        if (!map.has(p.name)) map.set(p.name, [])
        const labels = map.get(p.name)
        if (p.label && !labels.includes(p.label)) labels.push(p.label)
      })
      return map
    },
  },
  actions: {
    /** Loads once; pass force=true after programs change (Programs page). */
    load(force = false) {
      if (this.loaded && !force) return Promise.resolve()
      if (this.loadingPromise && !force) return this.loadingPromise
      this.loadingPromise = api
        .get('/programs', { params: { status: 'all', table_fields: ['name', 'label', 'status'] }, skipLoader: true })
        .then((res) => {
          this.programs = res.data.data
          this.loaded = true
        })
        .finally(() => {
          this.loadingPromise = null
        })
      return this.loadingPromise
    },

    /** "Name (UG)" — or the plain name if it has no label (or is unknown). */
    display(name) {
      if (!name) return name
      const labels = this.labelsByName.get(name)
      return labels && labels.length ? `${name} (${labels.join(' / ')})` : name
    },

    /**
     * Options for a program dropdown: value is the plain name (what gets
     * saved / sent as a filter), text is "Name (Label)". Active programs
     * only unless activeOnly is false; one option per distinct name.
     */
    options({ activeOnly = true } = {}) {
      const byName = new Map()
      this.programs
        .filter((p) => !activeOnly || p.status)
        .forEach((p) => {
          if (!byName.has(p.name)) byName.set(p.name, [])
          if (p.label && !byName.get(p.name).includes(p.label)) byName.get(p.name).push(p.label)
        })
      return [...byName].map(([name, labels]) => ({
        id: name,
        name: labels.length ? `${name} (${labels.join(' / ')})` : name,
      }))
    },
  },
})
