// Reads whatever QR code is on a PDF's first page — used by the Answer
// Sheet Upload flow to pull the answer-sheet code off each scanned PDF's
// cover page (see AnswerSheetUploadView.vue). Scans the *whole* rendered
// page rather than cropping to just the printed top-right corner the QR is
// supposed to be in — a real scan can be a little skewed or shifted per
// sheet, and jsQR is cheap enough on a single page that there's no real
// cost to being lenient about exactly where on the page it ends up.
import jsQR from 'jsqr'
import { loadPdf, renderPageToCanvas } from './pdf'

/**
 * @param {File} file
 * @returns {Promise<string|null>} the QR's decoded text, or null if page 1
 *   has no (readable) QR code at all — never throws for that; a genuinely
 *   corrupt/unreadable PDF file is the only thing that rejects.
 */
export async function extractQrFromPdfFirstPage(file) {
  const pdfDoc = await loadPdf({ data: await file.arrayBuffer() })
  const canvas = document.createElement('canvas') // never attached to the DOM — jsQR just needs the pixel data
  // Scale 2 is plenty for a QR code (unlike fine printed text, which is why
  // the OCR fallback elsewhere uses a much higher scale) while staying fast
  // across a large batch of files.
  await renderPageToCanvas(pdfDoc, 1, canvas, { scale: 2 })
  const ctx = canvas.getContext('2d')
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
  const result = jsQR(imageData.data, imageData.width, imageData.height)
  return result ? result.data : null
}
