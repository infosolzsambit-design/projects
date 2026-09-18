// Every *displayed* date in this app shows as dd-mm-yyyy (dd-mm-yyyy
// hh:mm AM/PM when a time is shown too) — the same dd-mm-yyyy date part
// DatePicker.vue's own text field already renders, just for read-only
// display everywhere else (tables, detail views, modals, audit logs, ...).
// Never used for date *inputs* — those stay on DatePicker.vue's own ISO
// round-trip; this is purely for turning a backend value into what the
// user sees.
//
// Accepts whatever a backend timestamp already comes back as in this
// app — 'YYYY-MM-DD', 'YYYY-MM-DD HH:mm[:ss]', or a full ISO 8601 string
// (with a 'T' and/or timezone) — without relying on Date's own locale-
// dependent parsing of ambiguous formats.
function parseBackendDate(value) {
  if (!value) return null
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value

  const match = String(value)
    .trim()
    .match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/)
  if (!match) {
    const fallback = new Date(value)
    return Number.isNaN(fallback.getTime()) ? null : fallback
  }
  const [, y, mo, d, h = '0', mi = '0', s = '0'] = match
  return new Date(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi), Number(s))
}

function pad2(n) {
  return String(n).padStart(2, '0')
}

/**
 * @param {string|Date|null|undefined} value
 * @param {{ fallback?: string }} [options] what to return for a missing/
 *   unparseable value — defaults to '—', this app's usual empty-cell dash.
 * @returns {string} 'dd-mm-yyyy'
 */
export function formatDate(value, { fallback = '—' } = {}) {
  const date = parseBackendDate(value)
  if (!date) return fallback
  return `${pad2(date.getDate())}-${pad2(date.getMonth() + 1)}-${date.getFullYear()}`
}

/**
 * @param {string|Date|null|undefined} value
 * @param {{ fallback?: string }} [options]
 * @returns {string} 'dd-mm-yyyy hh:mm AM/PM' — only when the source value
 *   actually carried a time component; falls back to the date-only form
 *   otherwise (e.g. a plain 'YYYY-MM-DD' column that's never had a time
 *   of day).
 */
export function formatDateTime(value, { fallback = '—' } = {}) {
  const date = parseBackendDate(value)
  if (!date) return fallback
  const hasTime = /\d{2}:\d{2}/.test(String(value))
  const datePart = `${pad2(date.getDate())}-${pad2(date.getMonth() + 1)}-${date.getFullYear()}`
  if (!hasTime) return datePart

  const hours24 = date.getHours()
  const period = hours24 >= 12 ? 'PM' : 'AM'
  const hours12 = hours24 % 12 || 12
  return `${datePart} ${pad2(hours12)}:${pad2(date.getMinutes())} ${period}`
}
