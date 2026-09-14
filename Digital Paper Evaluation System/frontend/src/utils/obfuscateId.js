// Keeps a plain numeric database id (e.g. an AnswerSheet's) out of the
// visible URL — /my-pending-courses/MTM/evaluate instead of
// /my-pending-courses/13/evaluate — so a teacher can't casually read off
// "how many answer sheets exist" or guess a neighboring one by editing the
// address bar. This is NOT real encryption and isn't meant as a security
// boundary: any transform written in frontend JS ships its own "key" in
// the bundle, so it can't keep a determined caller out — the backend's own
// ownership check (see MyPendingCourseController::show()) is what actually
// enforces access. This purely stops the *number itself* from being
// casually visible/enumerable in the URL bar.
export function encodeId(id) {
  return btoa(String(id)).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_')
}

export function decodeId(token) {
  if (!token) return null
  const base64 = token.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const decoded = Number(atob(padded))
  return Number.isInteger(decoded) && decoded > 0 ? decoded : null
}
