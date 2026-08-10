import { defineStore } from 'pinia'

// Single-teacher profile for this demo — there's no login/session system yet,
// so this represents "the teacher using this device".
export const useTeacherStore = defineStore('teacher', {
  state: () => ({
    name: '',
    photoUrl: '',
    descriptor: null, // plain number[] (128-d face-api.js descriptor)
  }),

  getters: {
    isRegistered: (state) => !!state.descriptor,
  },

  actions: {
    register({ name, photoUrl, descriptor }) {
      this.name = name
      this.photoUrl = photoUrl
      this.descriptor = descriptor
    },

    clear() {
      this.name = ''
      this.photoUrl = ''
      this.descriptor = null
    },
  },
})
