// 1 → "1st", 2 → "2nd", 3 → "3rd", 4 → "4th", 11–13 → "11th"–"13th".
// Same rule as GenerateMarksheetController::ordinal() on the backend.
// Anything that isn't a whole number is returned as-is ('—' for empty).
export function ordinal(value) {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  if (!Number.isInteger(n)) return String(value)
  const lastTwo = Math.abs(n) % 100
  const suffix = lastTwo >= 11 && lastTwo <= 13 ? 'th' : { 1: 'st', 2: 'nd', 3: 'rd' }[Math.abs(n) % 10] || 'th'
  return `${n}${suffix}`
}
