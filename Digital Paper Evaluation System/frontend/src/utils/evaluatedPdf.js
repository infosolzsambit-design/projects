import { LineCapStyle, PDFDocument, StandardFonts, rgb } from 'pdf-lib'

// Rebuilds the "evaluated" copy of an answer sheet: the original uploaded
// PDF with the teacher's own annotations (saved by EvaluatePaperView.vue as
// draft_annotations) drawn back onto it, plus the marks stamped on page 1.
// Annotations are stored in PDF user space (see EvaluatePaperView.vue's
// pixelToPdf()), which is exactly the space pdf-lib draws in, so no
// conversion is needed. Sizes/colours mirror EvaluatePaperView.vue's own
// drawCheck()/drawCross()/drawPencilPath()/drawBlankRect() so the file
// looks like what the teacher saw on screen.

const COLORS = {
  tick: { color: rgb(22 / 255, 163 / 255, 74 / 255), opacity: 1 },
  cross: { color: rgb(220 / 255, 38 / 255, 38 / 255), opacity: 1 },
  pencilRed: { color: rgb(220 / 255, 38 / 255, 38 / 255), opacity: 0.85 },
  pencilGreen: { color: rgb(22 / 255, 163 / 255, 74 / 255), opacity: 0.85 },
  blank: { color: rgb(234 / 255, 88 / 255, 12 / 255), opacity: 0.9 },
}

function line(page, [x1, y1], [x2, y2], thickness, { color, opacity }) {
  page.drawLine({
    start: { x: x1, y: y1 },
    end: { x: x2, y: y2 },
    thickness,
    color,
    opacity,
    lineCap: LineCapStyle.Round,
  })
}

// Canvas y grows downward, PDF y grows upward — the vertical offsets below
// are EvaluatePaperView.vue's own, just with their sign flipped.
function drawTick(page, [x, y]) {
  const s = 18
  line(page, [x - s, y], [x - s * 0.25, y - s * 0.7], 4.5, COLORS.tick)
  line(page, [x - s * 0.25, y - s * 0.7], [x + s, y + s * 0.8], 4.5, COLORS.tick)
}

function drawCross(page, [x, y]) {
  const s = 16
  line(page, [x - s, y + s], [x + s, y - s], 4.5, COLORS.cross)
  line(page, [x + s, y + s], [x - s, y - s], 4.5, COLORS.cross)
}

function drawPencil(page, points, colorKey) {
  const style = colorKey === 'green' ? COLORS.pencilGreen : COLORS.pencilRed
  for (let i = 1; i < points.length; i++) line(page, points[i - 1], points[i], 2.5, style)
}

function drawBlank(page, [x1, y1], [x2, y2]) {
  page.drawRectangle({
    x: Math.min(x1, x2),
    y: Math.min(y1, y2),
    width: Math.abs(x2 - x1),
    height: Math.abs(y2 - y1),
    borderColor: COLORS.blank.color,
    borderOpacity: COLORS.blank.opacity,
    borderWidth: 2,
    borderDashArray: [6, 4],
  })
}

function drawAnnotation(page, ann) {
  if (ann.type === 'correct' && ann.point) drawTick(page, ann.point)
  else if (ann.type === 'wrong' && ann.point) drawCross(page, ann.point)
  else if (ann.type === 'pencil' && ann.points?.length > 1) drawPencil(page, ann.points, ann.color)
  else if (ann.type === 'blank' && ann.start && ann.end) drawBlank(page, ann.start, ann.end)
}

async function stampMarks(pdfDoc, page, marks, maxMarks) {
  const font = await pdfDoc.embedFont(StandardFonts.HelveticaBold)
  const shown = Number.isFinite(Number(marks)) ? Number(marks) : marks
  const text = maxMarks != null ? `Marks: ${shown} / ${maxMarks}` : `Marks: ${shown}`
  const size = 14
  const padX = 10
  const padY = 7
  const textWidth = font.widthOfTextAtSize(text, size)
  const { width, height } = page.getSize()
  const boxW = textWidth + padX * 2
  const boxH = size + padY * 2
  const x = width - boxW - 18
  const y = height - boxH - 18

  page.drawRectangle({
    x,
    y,
    width: boxW,
    height: boxH,
    color: rgb(1, 1, 1),
    opacity: 0.9,
    borderColor: COLORS.cross.color,
    borderWidth: 1.5,
  })
  page.drawText(text, { x: x + padX, y: y + padY + 2, size, font, color: COLORS.cross.color })
}

/**
 * @param {ArrayBuffer} originalPdfBytes
 * @param {{ annotations?: Record<string, Array<object>>|null, marks?: number|string|null, maxMarks?: number|null, coverPdfBytes?: ArrayBuffer|null }} data
 *   coverPdfBytes — optional PDF (the generated top sheet) whose pages go
 *   in front of the answer sheet.
 * @returns {Promise<Uint8Array>}
 */
export async function buildEvaluatedPdf(originalPdfBytes, { annotations, marks, maxMarks, coverPdfBytes = null }) {
  const pdfDoc = await PDFDocument.load(originalPdfBytes)
  const pages = pdfDoc.getPages()

  for (const [pageKey, list] of Object.entries(annotations || {})) {
    const page = pages[Number(pageKey) - 1]
    if (!page || !Array.isArray(list)) continue
    for (const ann of list) drawAnnotation(page, ann)
  }

  if (marks !== null && marks !== undefined && pages[0]) await stampMarks(pdfDoc, pages[0], marks, maxMarks)

  // Cover pages go in last, so the annotation page numbers above (1 = the
  // answer sheet's own first page) and the marks stamp stay correct.
  if (coverPdfBytes) {
    const coverDoc = await PDFDocument.load(coverPdfBytes)
    const coverPages = await pdfDoc.copyPages(coverDoc, coverDoc.getPageIndices())
    coverPages.forEach((page, i) => pdfDoc.insertPage(i, page))
  }

  return pdfDoc.save()
}

export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
