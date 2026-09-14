import * as pdfjsLib from 'pdfjs-dist'

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
  'pdfjs-dist/build/pdf.worker.min.mjs',
  import.meta.url,
).href

export function loadPdf(source) {
  const params = typeof source === 'string' ? { url: source } : source
  return pdfjsLib.getDocument(params).promise
}

export async function renderPageToCanvas(pdfDoc, pageNumber, canvas, { scale = 1, rotation = 0 } = {}) {
  const page = await pdfDoc.getPage(pageNumber)
  const viewport = page.getViewport({ scale, rotation })

  canvas.width = viewport.width
  canvas.height = viewport.height

  const ctx = canvas.getContext('2d')
  await page.render({ canvasContext: ctx, viewport }).promise

  return viewport
}

// pdf.js hands back one item per text run, not per visual line, so this
// groups items whose baseline sits close together into the same line (then
// sorts left-to-right within it) before handing plain text lines off to a
// structural parser — see utils/questionPaperParser.js, the only consumer.
export async function extractPageLines(pdfDoc, pageNumber) {
  const page = await pdfDoc.getPage(pageNumber)
  const content = await page.getTextContent()

  const rows = []
  content.items.forEach((item) => {
    if (!item.str) return
    const x = item.transform[4]
    const y = item.transform[5]
    let row = rows.find((r) => Math.abs(r.y - y) < 3)
    if (!row) {
      row = { y, items: [] }
      rows.push(row)
    }
    row.items.push({ x, width: item.width || 0, str: item.str })
  })

  rows.sort((a, b) => b.y - a.y) // PDF y grows upward — read top to bottom
  return rows
    .map((row) => {
      row.items.sort((a, b) => a.x - b.x)
      let line = ''
      let lastEnd = null
      row.items.forEach(({ x, width, str }) => {
        // pdf.js splits text into separate items at font/style boundaries
        // without always emitting the space between words as its own item —
        // insert one whenever there's a visible gap the previous item didn't
        // already end with.
        if (lastEnd !== null && x - lastEnd > 2 && !line.endsWith(' ')) line += ' '
        line += str
        lastEnd = x + width
      })
      return line.replace(/\s+/g, ' ').trim()
    })
    .filter(Boolean)
}

export async function extractDocumentLines(pdfDoc) {
  const allLines = []
  for (let i = 1; i <= pdfDoc.numPages; i++) {
    allLines.push(...(await extractPageLines(pdfDoc, i)))
  }
  return allLines
}

// Tesseract frequently reads a bordered table cell's vertical rule as a
// stray "|" character stuck to the start and/or end of the line inside it
// — e.g. "| iii) | What is meant..." — which would otherwise defeat the
// structural parser's line-start patterns (it never sees the line as
// starting with "iii)"). Strips that (and the whitespace it leaves behind)
// without touching real punctuation elsewhere in the line.
function stripOcrTableBorderNoise(line) {
  return line.trim().replace(/^(?:\|\s*)+/, '').replace(/(?:\s*\|)+$/, '').trim()
}

// Fallback for scanned/photographed question papers, which have no
// embedded text layer at all (extractDocumentLines above returns nothing
// for them) — renders each page to an off-screen canvas at a higher
// resolution than the on-screen preview needs (OCR reads small print far
// better at 3.5x than at the preview's 1.3x) and runs it through
// tesseract.js. Lazy-imported so its ~64KB core + multi-MB wasm/language
// data only ever load for someone who actually hits this fallback, not on
// every visit to this page.
export async function extractDocumentLinesViaOCR(pdfDoc, { scale = 3.5, onProgress } = {}) {
  const { createWorker } = await import('tesseract.js')
  // Self-host just the tiny worker script (matches how the pdf.js worker
  // above is bundled) — the much larger core wasm and language data are
  // left on tesseract.js's default CDN, per its own recommendation, rather
  // than shipping every SIMD/non-SIMD variant (tens of MB) with this app.
  const workerPath = new URL('tesseract.js/dist/worker.min.js', import.meta.url).href
  const worker = await createWorker('eng', 1, { workerPath })

  try {
    const allLines = []
    for (let page = 1; page <= pdfDoc.numPages; page++) {
      onProgress?.(page, pdfDoc.numPages)
      const canvas = document.createElement('canvas')
      await renderPageToCanvas(pdfDoc, page, canvas, { scale })
      const { data } = await worker.recognize(canvas)
      allLines.push(...data.text.split('\n').map(stripOcrTableBorderNoise).filter(Boolean))
    }
    return allLines
  } finally {
    await worker.terminate()
  }
}
