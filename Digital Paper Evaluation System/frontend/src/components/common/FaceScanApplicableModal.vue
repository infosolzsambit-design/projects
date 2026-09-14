<script setup>
import { ref } from 'vue'
import api from '../../utils/api'
import { useToast } from '../../composables/useToast'

// Opened from the Teachers list's row action menu (see TeachersView.vue) —
// a small Yes/No question, not a full edit form: is this teacher even
// expected to have a face scan on file at all? Distinct from whether one
// has actually been captured yet (face_descriptor/has_face_profile) — a
// teacher can be marked "not applicable" and still show up in lists
// without ever being nagged to scan. Same PUT /teachers/{id} endpoint the
// list's own Active/Inactive toggle already uses, just for this one field
// (see UpdateTeacherRequest's face_scan_applicable rule).
const props = defineProps({
  teacher: { type: Object, required: true }, // { id, name, face_scan_applicable }
})
const emit = defineEmits(['close', 'updated'])
const toast = useToast()

const saving = ref(false)

async function choose(applicable) {
  if (saving.value) return
  saving.value = true
  try {
    const res = await api.put(`/teachers/${props.teacher.id}`, { face_scan_applicable: applicable })
    toast.success(`Face scan marked ${applicable ? 'applicable' : 'not applicable'} for ${props.teacher.name}.`)
    emit('updated', res.data.data.face_scan_applicable)
    emit('close')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Could not update this teacher.')
  } finally {
    saving.value = false
  }
}

function close() {
  if (saving.value) return
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="bg-white rounded-[24px] shadow-card w-full max-w-sm px-6 py-7 text-center">
      <span class="mx-auto mb-4 w-14 h-14 rounded-full bg-soft flex items-center justify-center">
        <svg class="w-7 h-7 text-brand-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
          <circle cx="12" cy="13" r="4" />
        </svg>
      </span>

      <h2 class="text-lg font-bold text-black mb-2">Face Scan Applicable?</h2>
      <p class="text-[13px] text-muted mb-1">Is a face scan applicable for <span class="font-semibold text-gray-700">{{ teacher.name }}</span>?</p>
      <p class="text-[12px] text-muted mb-6">Currently set to <span class="font-semibold">{{ teacher.face_scan_applicable ? 'Yes' : 'No' }}</span>.</p>

      <div class="flex justify-center gap-3">
        <button
          type="button"
          class="min-w-[110px] px-5 py-2.5 rounded-full text-sm font-semibold uppercase tracking-wide transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          :class="teacher.face_scan_applicable === false ? 'bg-brand text-white' : 'border border-input-border bg-white text-gray-700 hover:border-brand hover:text-brand'"
          :disabled="saving"
          @click="choose(false)"
        >
          No
        </button>
        <button
          type="button"
          class="min-w-[110px] px-5 py-2.5 rounded-full text-sm font-semibold uppercase tracking-wide transition-opacity hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed"
          :class="teacher.face_scan_applicable !== false ? 'bg-btn-gradient text-white' : 'border border-input-border bg-white text-gray-700 hover:border-brand-blue hover:text-brand-blue'"
          :disabled="saving"
          @click="choose(true)"
        >
          Yes
        </button>
      </div>
    </div>
  </div>
</template>
