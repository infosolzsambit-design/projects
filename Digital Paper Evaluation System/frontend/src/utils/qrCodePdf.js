// Scan Papers' print actions — a packet's printable A4 PDF, built in the
// browser (pdf-lib, already used by Answer Sheet Upload; QR codes via
// `qrcode`, drawn as vector squares so they stay sharp when printed):
//   'list'   → "Print Student List": # · QR Code No · Registration No ·
//              Student Name, with the packet's details on top;
//   'labels' → "Print QR Code": the QR labels only — each QR code with its
//              number underneath, LABEL_COLUMNS × LABEL_ROWS per page.
import QRCode from 'qrcode'
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib'

const A4 = [595.28, 841.89] // points (1/72 inch)
const MARGIN = 36
const LABEL_COLUMNS = 5
const LABEL_ROWS = 7

const BLACK = rgb(0, 0, 0)
const GREY = rgb(0.45, 0.45, 0.45)
const LINE = rgb(0.8, 0.8, 0.8)
const HEADER_FILL = rgb(0.92, 0.93, 0.96)

// The standard PDF fonts only cover Latin-1 — anything else becomes "?"
// rather than failing the whole PDF.
function safe(text) {
  return String(text ?? '').replace(/[^\x20-\x7E -ÿ]/g, '?')
}

function fit(text, font, size, maxWidth) {
  let value = safe(text)
  if (font.widthOfTextAtSize(value, size) <= maxWidth) return value
  while (value.length > 1 && font.widthOfTextAtSize(`${value}...`, size) > maxWidth) value = value.slice(0, -1)
  return `${value}...`
}

// Dark modules as filled squares; consecutive dark modules in a row are
// merged into one rectangle to keep the PDF small.
function drawQr(page, text, x, y, size) {
  const { modules } = QRCode.create(String(text), { errorCorrectionLevel: 'M' })
  const cell = size / modules.size
  for (let row = 0; row < modules.size; row++) {
    let runStart = -1
    for (let col = 0; col <= modules.size; col++) {
      const dark = col < modules.size && modules.data[row * modules.size + col]
      if (dark && runStart < 0) runStart = col
      if (!dark && runStart >= 0) {
        page.drawRectangle({
          x: x + runStart * cell,
          y: y + size - (row + 1) * cell,
          width: (col - runStart) * cell,
          height: cell,
          color: BLACK,
        })
        runStart = -1
      }
    }
  }
}

/**
 * @param {object} packet  { packet_code, program_name, course_name, course_code, semester, exam_term_name, exam_type_name, exam_sub_type_name, department_name, batch, exam_year }
 * @param {Array<{qr_code: string, registration_no: string, name: string}>} codes
 * @param {'list'|'labels'} part  which PDF — the student list, or the QR labels
 * @returns {Promise<Uint8Array>} the PDF file
 */
export async function buildQrCodePdf(packet, codes, part) {
  const pdf = await PDFDocument.create()
  pdf.setTitle(safe(`${part === 'list' ? 'Student List' : 'QR Codes'} - Packet ${packet.packet_code}`))
  const font = await pdf.embedFont(StandardFonts.Helvetica)
  const bold = await pdf.embedFont(StandardFonts.HelveticaBold)
  const [W, H] = A4

  // --- 1. The list ---------------------------------------------------------
  const course = [packet.course_name, packet.course_code ? `(${packet.course_code})` : ''].filter(Boolean).join(' ')
  const details = [
    ['Program', packet.program_name],
    ['Course', course],
    ['Semester', packet.semester],
    ['Exam Term', packet.exam_term_name],
    ['Exam Type', packet.exam_type_name],
    ['Exam Sub Type', packet.exam_sub_type_name],
    ['Department', packet.department_name],
    ['Batch', packet.batch],
    ['Exam Year', packet.exam_year],
    ['Total Students', codes.length],
  ]
  const COLUMNS = [
    { label: '#', width: 36, value: (_, i) => String(i + 1) },
    { label: 'QR Code No', width: 100, value: (c) => c.qr_code },
    { label: 'Registration No', width: 130, value: (c) => c.registration_no },
    { label: 'Student Name', width: W - 2 * MARGIN - 266, value: (c) => c.name },
  ]
  const ROW_H = 18
  const TEXT = 9

  let page
  let y
  const newListPage = (first) => {
    page = pdf.addPage(A4)
    y = H - MARGIN
    if (first) {
      page.drawText(fit(`Student List - Packet ${packet.packet_code}`, bold, 14, W - 2 * MARGIN), { x: MARGIN, y: y - 14, size: 14, font: bold })
      y -= 30
      // Packet details — Program and Course (long names) on a line each,
      // the rest two per line.
      const full = W - 2 * MARGIN
      const half = full / 2
      const show = (value) => (value === null || value === undefined || value === '' ? '-' : value)
      const drawDetail = ([label, value], x, width) => {
        page.drawText(`${label}:`, { x, y, size: TEXT, font: bold })
        page.drawText(fit(show(value), font, TEXT, width - 80), { x: x + 75, y, size: TEXT, font })
      }
      const [program, courseLine, ...rest] = details
      drawDetail(program, MARGIN, full)
      y -= 14
      drawDetail(courseLine, MARGIN, full)
      rest.forEach((detail, i) => {
        if (i % 2 === 0) y -= 14
        drawDetail(detail, MARGIN + (i % 2) * half, half)
      })
      y -= 22
    }
    // Table header (repeated on every list page).
    page.drawRectangle({ x: MARGIN, y: y - ROW_H, width: W - 2 * MARGIN, height: ROW_H, color: HEADER_FILL, borderColor: LINE, borderWidth: 0.5 })
    let x = MARGIN
    COLUMNS.forEach((col) => {
      page.drawText(col.label, { x: x + 5, y: y - ROW_H + 6, size: TEXT, font: bold })
      x += col.width
    })
    y -= ROW_H
  }

  if (part === 'list') newListPage(true)
  if (part === 'list') codes.forEach((code, i) => {
    if (y - ROW_H < MARGIN + 14) newListPage(false)
    page.drawRectangle({ x: MARGIN, y: y - ROW_H, width: W - 2 * MARGIN, height: ROW_H, borderColor: LINE, borderWidth: 0.5 })
    let x = MARGIN
    COLUMNS.forEach((col) => {
      page.drawText(fit(col.value(code, i), font, TEXT, col.width - 10), { x: x + 5, y: y - ROW_H + 6, size: TEXT, font })
      x += col.width
    })
    y -= ROW_H
  })

  // --- 2. The QR labels ----------------------------------------------------
  const cellW = (W - 2 * MARGIN) / LABEL_COLUMNS
  const cellH = (H - 2 * MARGIN) / LABEL_ROWS
  const NUMBER = 11
  const qrSize = Math.min(cellW - 24, cellH - 24 - NUMBER)
  const perPage = LABEL_COLUMNS * LABEL_ROWS
  if (part === 'labels') codes.forEach((code, i) => {
    if (i % perPage === 0) page = pdf.addPage(A4)
    const slot = i % perPage
    const cellX = MARGIN + (slot % LABEL_COLUMNS) * cellW
    const cellTop = H - MARGIN - Math.floor(slot / LABEL_COLUMNS) * cellH
    // A light cutting border round each label.
    page.drawRectangle({ x: cellX + 3, y: cellTop - cellH + 3, width: cellW - 6, height: cellH - 6, borderColor: LINE, borderWidth: 0.5 })
    const qrX = cellX + (cellW - qrSize) / 2
    const qrY = cellTop - 9 - qrSize
    drawQr(page, code.qr_code, qrX, qrY, qrSize)
    const number = safe(code.qr_code)
    page.drawText(number, { x: cellX + (cellW - font.widthOfTextAtSize(number, NUMBER)) / 2, y: qrY - NUMBER - 3, size: NUMBER, font })
  })

  // Page numbers, now that the total is known.
  const pages = pdf.getPages()
  pages.forEach((p, i) => {
    const label = `${part === 'list' ? 'Student List' : 'QR Codes'} - Packet ${safe(packet.packet_code)} - Page ${i + 1} of ${pages.length}`
    p.drawText(label, { x: W - MARGIN - font.widthOfTextAtSize(label, 7), y: MARGIN / 2 - 3, size: 7, font, color: GREY })
  })

  return pdf.save()
}
