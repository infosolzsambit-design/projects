<script setup>
import { ref } from 'vue'
import api from '../../utils/api'

// Swaps in a new scan for an already-set-up paper's PDF only — reachable
// from QuestionPapersView.vue's row action menu, deliberately enabled even
// once evaluation_started has otherwise locked "Edit Setup"/"Delete" (see
// QuestionPaperController::updatePdf()'s own docblock for why a plain file
// swap is safe there: it never touches the structure tree/marks, so it
// can't orphan a teacher's in-progress draft_marks_breakdown).
const props = defineProps({
  paper: { type: Object, required: true },
})
const emit = defineEmits(['close', 'updated'])

const fileInput = ref(null)
const selectedFile = ref(null)
const fileError = ref('')
const formError = ref('')
const saving = ref(false)

function pickFile() {
  fileInput.value?.click()
}

function onFileChange(event) {
  const file = event.target.files?.[0] ?? null
  fileError.value = ''
  formError.value = ''
  if (file && file.type !== 'application/pdf') {
    fileError.value = 'Please choose a PDF file.'
    selectedFile.value = null
    event.target.value = ''
    return
  }
  selectedFile.value = file
}

async function submit() {
  formError.value = ''
  if (!selectedFile.value) {
    fileError.value = 'Choose the replacement PDF file first.'
    return
  }

  saving.value = true
  try {
    const formData = new FormData()
    formData.append('pdf', selectedFile.value)
    const res = await api.post(`/question-papers/${props.paper.id}/pdf`, formData)
    emit('updated', res.data.data)
  } catch (err) {
    formError.value = err.response?.data?.errors?.pdf?.[0] || err.response?.data?.message || 'Could not replace the PDF file.'
  } finally {
    saving.value = false
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60 p-4" @click.self="close">
    <div class="w-full max-w-[460px] rounded-[28px] bg-white shadow-panel overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-soft">
        <h2 class="text-[18px] font-semibold text-gray-900">Replace PDF</h2>
        <button type="button" class="w-9 h-9 rounded-full border border-input-border flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors" aria-label="Close" @click="close">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
        </button>
      </div>

      <form class="p-5 flex flex-col gap-4" @submit.prevent="submit">
        <p class="text-[13px] text-muted">
          Upload a new scan for {{ paper.course_name || 'this question paper' }} ({{ paper.exam_year }}, Sem {{ paper.semester }}).
          Only the file is replaced — marks, Bloom/CO tags and the question structure stay exactly as already set up.
        </p>

        <p v-if="formError" class="text-[13px] text-brand text-center">{{ formError }}</p>

        <div class="flex flex-col gap-1.5">
          <input ref="fileInput" type="file" accept="application/pdf" class="hidden" @change="onFileChange" />
          <button
            type="button"
            class="w-full h-24 rounded-xl border-2 border-dashed flex flex-col items-center justify-center gap-1 text-center transition-colors"
            :class="fileError ? 'border-brand text-brand' : 'border-input-border text-muted hover:border-brand-blue hover:text-brand-blue'"
            @click="pickFile"
          >
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" /></svg>
            <span class="text-[13px] font-medium">{{ selectedFile ? selectedFile.name : 'Click to choose a PDF file' }}</span>
          </button>
          <p v-if="fileError" class="text-[12px] text-brand">{{ fileError }}</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button
            type="submit"
            class="min-w-[220px] px-8 py-3 rounded-full bg-btn-gradient text-white text-sm font-semibold tracking-[0.06em] uppercase hover:opacity-90 hover:-translate-y-px active:translate-y-0 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="saving"
          >
            {{ saving ? 'Uploading…' : 'Replace PDF' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
