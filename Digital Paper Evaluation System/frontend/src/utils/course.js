// One way of writing a course everywhere in the app — "Physics (PHY101) –
// Theory". Courses are unique by name + code + type, so the type is what
// tells two same-name-and-code courses apart. A course with no type yet
// (created before the column existed) reads as before: "Physics (PHY101)".
// Backend twin: app/Helpers/CourseLabel.php (emails, notifications, exports).

/** "Physics (PHY101) – Theory" */
export function courseLabel(name, code, type) {
  let label = String(name ?? '').trim()
  if (code) label += ` (${String(code).trim()})`
  return label + typeSuffix(type)
}

/** " – Theory" (or '') — for places that already show name and code. */
export function typeSuffix(type) {
  const t = String(type ?? '').trim()
  return t ? ` – ${t}` : ''
}

/** Dropdown option from a /courses row ({ id, name, code, type }). */
export function courseOption(course) {
  return { ...course, name: courseLabel(course.name, course.code, course.type) }
}
