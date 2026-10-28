// How a permission's stored name is shown on the Roles pages:
// "course-status-change" → "Course Status Change". The stored name itself
// never changes (permission checks use it). Short forms stay in capitals
// ("hod-dashboard" → "HOD Dashboard").
const ACRONYMS = new Set(['hod', 'qr', 'pdf', 'csv', 'id', 'api', 'otp'])

export function permissionLabel(name) {
  return String(name ?? '').split(/[-_]+/).filter(Boolean)
    .map((w) => (ACRONYMS.has(w.toLowerCase()) ? w.toUpperCase() : w.charAt(0).toUpperCase() + w.slice(1)))
    .join(' ')
}

// Whether a permission matches a search, by its stored name
// ("status-change") or by how it's shown ("status change").
export function permissionMatches(name, query) {
  const q = query.trim().toLowerCase()
  return !q || String(name).toLowerCase().includes(q) || permissionLabel(name).toLowerCase().includes(q)
}
