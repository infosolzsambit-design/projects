import { ref } from 'vue'

// Shared singleton state for one app-wide confirmation dialog — every page
// calls the same confirmDialog() instead of the browser's native confirm()
// (which can't be styled and looks jarring next to the rest of this app).
// A single <ConfirmDialog /> mounted once in App.vue reads this state, so
// there's only ever one dialog in the DOM no matter how many pages use it.
const isOpen = ref(false)
const title = ref('')
const message = ref('')
const confirmText = ref('Confirm')
const cancelText = ref('Cancel')
const danger = ref(true)

let resolvePromise = null

/**
 * @param {{ title?: string, message?: string, confirmText?: string, cancelText?: string, danger?: boolean }} options
 * @returns {Promise<boolean>} resolves true if confirmed, false if cancelled/dismissed
 */
function confirmDialog(options = {}) {
  title.value = options.title ?? 'Are you sure?'
  message.value = options.message ?? ''
  confirmText.value = options.confirmText ?? 'Confirm'
  cancelText.value = options.cancelText ?? 'Cancel'
  danger.value = options.danger ?? true
  isOpen.value = true

  // Resolves the *previous* call defensively if a second confirmDialog()
  // somehow fires before the first was dismissed, so no caller's await
  // hangs forever.
  resolvePromise?.(false)

  return new Promise((resolve) => {
    resolvePromise = resolve
  })
}

function handleConfirm() {
  isOpen.value = false
  resolvePromise?.(true)
  resolvePromise = null
}

function handleCancel() {
  isOpen.value = false
  resolvePromise?.(false)
  resolvePromise = null
}

export function useConfirm() {
  return { isOpen, title, message, confirmText, cancelText, danger, confirmDialog, handleConfirm, handleCancel }
}
