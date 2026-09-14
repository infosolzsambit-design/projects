// Turns free text into a lowercase, hyphen-separated slug — "Paper View" /
// "paper view" / "One two THREE" all become "paper-view" / "one-two-three".
// Used live (on each keystroke) by PermissionFormView's Name field, so it
// keeps a trailing hyphen while the user is still typing (e.g. right after
// a space) rather than eating it — see `finalize` for the version that
// trims that off, run on blur/submit.
export function slugify(value) {
  return String(value)
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '') // strips periods, symbols — anything but letters/digits/space/hyphen
    .replace(/[\s_]+/g, '-') // spaces/underscores -> hyphen
    .replace(/-{2,}/g, '-') // collapse repeats
}

export function finalizeSlug(value) {
  return slugify(value).replace(/^-+|-+$/g, '')
}

// Mirrors the backend's StorePermissionRequest/UpdatePermissionRequest
// regex exactly — lowercase alphanumeric segments joined by single hyphens,
// no leading/trailing/double hyphens.
export const SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/
