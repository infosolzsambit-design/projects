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
