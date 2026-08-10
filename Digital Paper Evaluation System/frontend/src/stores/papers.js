import { defineStore } from 'pinia'

function padSerial(n) {
  return String(n).padStart(4, '0')
}

export const usePapersStore = defineStore('papers', {
  state: () => ({
    // One entry per uploaded answer-script PDF.
    // NOTE: no student name/roll is ever stored here — only the QR/serial
    // number, so anything reading this list (e.g. the teacher review screens)
    // cannot see student identity even by accident.
    papers: [],
    nextSerial: 1,

    // Parsed from the admin's Excel upload: serial <-> student identity.
    // Kept separate from `papers` on purpose. Only admin-facing views should
    // ever read this array.
    studentMap: [],
  }),

  getters: {
    paperById: (state) => (id) => state.papers.find((p) => p.id === id),
  },

  actions: {
    addPapers(files) {
      const added = []
      for (const file of files) {
        const paper = {
          id: padSerial(this.nextSerial),
          fileName: file.name,
          file,
          fileUrl: URL.createObjectURL(file),
          pageCount: null,
          pdfError: null,
          status: 'Pending',
          marks: {},
          annotations: {},
          checkingStartedAt: null,
          checkingDurationSeconds: null,
          uploadedAt: new Date().toISOString(),
        }
        this.nextSerial += 1
        this.papers.push(paper)
        added.push(paper)
      }
      return added
    },

    removePaper(id) {
      const paper = this.papers.find((p) => p.id === id)
      if (paper?.fileUrl) URL.revokeObjectURL(paper.fileUrl)
      this.papers = this.papers.filter((p) => p.id !== id)
    },

    setPageCount(id, count) {
      const paper = this.paperById(id)
      if (paper) paper.pageCount = count
    },

    setPdfError(id, message) {
      const paper = this.paperById(id)
      if (paper) paper.pdfError = message
    },

    setStudentMap(rows) {
      this.studentMap = rows
    },

    updateMark(id, questionKey, value) {
      const paper = this.paperById(id)
      if (paper) paper.marks = { ...paper.marks, [questionKey]: value }
    },

    setAnnotations(id, page, annotations) {
      const paper = this.paperById(id)
      if (paper) paper.annotations = { ...paper.annotations, [page]: annotations }
    },

    setStatus(id, status) {
      const paper = this.paperById(id)
      if (paper) paper.status = status
    },

    startChecking(id) {
      const paper = this.paperById(id)
      if (paper) paper.checkingStartedAt = Date.now()
    },

    finishChecking(id, durationSeconds) {
      const paper = this.paperById(id)
      if (paper) paper.checkingDurationSeconds = durationSeconds
    },
  },
})
