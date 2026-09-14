import { reactive } from 'vue'

// Shared singleton state for one app-wide toast stack — every page calls
// the same success()/error()/info() instead of rolling its own inline
// "Saved successfully" banner (or, just as often, showing nothing at all
// after a create/update/delete). A single <Toaster /> mounted once in
// App.vue reads this state, so there's only ever one toast stack in the
// DOM no matter how many pages push to it — mirrors useConfirm.js's same
// shared-singleton-composable shape, just for a stack instead of one modal.
const toasts = reactive([])
let nextId = 1

function push(type, message, duration) {
  const id = nextId++
  toasts.push({ id, type, message })
  setTimeout(() => dismiss(id), duration)
  return id
}

function dismiss(id) {
  const index = toasts.findIndex((toast) => toast.id === id)
  if (index !== -1) toasts.splice(index, 1)
}

function success(message, duration = 3500) {
  return push('success', message, duration)
}

function error(message, duration = 5000) {
  return push('error', message, duration)
}

function info(message, duration = 3500) {
  return push('info', message, duration)
}

export function useToast() {
  return { toasts, success, error, info, dismiss }
}
