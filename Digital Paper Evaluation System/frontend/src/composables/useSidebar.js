import { ref } from 'vue'

// Shared singleton state for the dashboard sidebar — ported from
// designed_files/js/script.js's desktop hover-to-expand / pin-on-click /
// mobile off-canvas behavior. Module-level (not created inside a function),
// so AppSidebar, AppHeader (hamburger button) and DashboardLayout (overlay +
// content padding) all read/write the exact same state instead of needing
// prop drilling or an event bus.
const isOpen = ref(false)
const isPinned = ref(false)

const desktopQuery = typeof window !== 'undefined' ? window.matchMedia('(min-width: 992px)') : null
const isDesktop = ref(desktopQuery ? desktopQuery.matches : true)

desktopQuery?.addEventListener('change', (event) => {
  isDesktop.value = event.matches
  isPinned.value = false
  isOpen.value = false
})

function toggle() {
  if (isDesktop.value) {
    isPinned.value = !isPinned.value
    isOpen.value = isPinned.value
  } else {
    isOpen.value = !isOpen.value
  }
}

function hoverOpen() {
  if (!isDesktop.value || isPinned.value) return
  isOpen.value = true
}

function hoverClose() {
  if (!isDesktop.value || isPinned.value) return
  isOpen.value = false
}

function close() {
  isPinned.value = false
  isOpen.value = false
}

export function useSidebar() {
  return { isOpen, isPinned, isDesktop, toggle, hoverOpen, hoverClose, close }
}
