// Best-effort structural parser for uploaded question paper PDFs — turns
// the extracted text lines into a starting node-tree skeleton (see
// QuestionPaperNode on the backend for what a node is — the same
// {label, mode, choose_count, marks, bloom_level, co, children} shape at
// every depth) so the person setting up the paper mostly reviews and
// corrects instead of typing everything into a blank form. Real papers vary
// a lot in layout, so this recognizes common patterns (Group A/B/C
// headers, roman-numeral sub-parts, "OR" alternatives, "answer any N of
// M", "N marks each", a Bloom's Taxonomy/Course Outcome column) and simply
// produces nothing for anything it can't confidently read — it is
// deliberately NOT required to get every paper right, only to save typing
// on the common case. It only ever extracts label + marks + bloom_level +
// co (never the question wording itself), because that's all the data
// model stores; bloom_level/co are left blank whenever the paper doesn't
// print them, never guessed.

import { extractDocumentLines, extractDocumentLinesViaOCR } from './pdf'

const GROUP_HEADER_RE = /^group[\s\-–—:]*([a-z0-9]+)\b/i
const OR_LINE_RE = /^or$/i
const MAIN_NUM_RE = /^(\d{1,3})[.)]\s*(.*)$/
const ROMAN_SUB_RE = /^([ivx]{1,6})[.)]\s*(.*)$/i
const FULL_MARKS_RE = /full\s*marks\s*[:\-]?\s*(\d{1,4})/i
const TIME_ALLOTTED_RE = /time\s*allotted\s*[:\-]?\s*(\d+(?:\.\d+)?\s*(?:hours?|hrs?|minutes?|mins?))/i
// Covers common real-world phrasings: "10 out of 12", "any 5 out of 7
// questions", "any 2 of the following 3 questions", "choose 3 from 6" — the
// filler words ("the", "following", "questions") between the two numbers
// are optional so wording differences don't break the match.
const CHOOSE_OUT_OF_RE = /(?:answer|attempt|choose|do|solve)?\s*(?:any\s+)?(\d+)\s*(?:questions?)?\s*(?:out\s*of|from\s*among|from|of)\s*(?:the\s+)?(?:following\s+)?(\d+)/i
const CHOOSE_SIMPLE_RE = /(?:answer|attempt|choose|do|solve)\s+(?:any\s+)?(\d+)\b/i
const MARKS_EACH_RE = /(\d+)\s*marks?\s*each|each\s*(?:question\s*)?carr(?:y|ies)\s*(\d+)\s*marks?/i
const INLINE_MARKS_RE = /[[(]\s*(\d+)\s*(?:marks?)?\s*[\])]\s*$/i
// Many papers print two extra columns per question: a Bloom's Taxonomy
// level (K1, K2, ...) and a Course Outcome (CO1, CO2, ...) — see
// designed_files/question_paper.pdf's right-hand columns. Both are
// optional per QuestionPaperNode; only ever filled in here when the text
// actually has them, never guessed.
const BLOOM_LEVEL_RE = /\bK\s*([1-6])\b/i
const CO_RE = /\bCO\s*(\d{1,2})\b/i
// Positional fallback labels for a choose-cluster's children — see
// flushCluster() below.
const ORDINAL_ROMANS = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x']

/** Pulls Full Marks / Time Allotted off the paper's own header text, if present. */
export function parseHeaderFields(lines) {
  const text = lines.join(' ')
  const fullMarksMatch = text.match(FULL_MARKS_RE)
  const timeMatch = text.match(TIME_ALLOTTED_RE)
  return {
    full_marks: fullMarksMatch ? Number(fullMarksMatch[1]) : null,
    time_allotted: timeMatch ? timeMatch[1].replace(/\s+/g, ' ').trim() : '',
  }
}

function leafFrom(localLabel, trailingText, defaultMarks) {
  const inlineMatch = trailingText.match(INLINE_MARKS_RE)
  const bloomMatch = trailingText.match(BLOOM_LEVEL_RE)
  const coMatch = trailingText.match(CO_RE)
  return {
    localLabel,
    marks: inlineMatch ? Number(inlineMatch[1]) : (defaultMarks || ''),
    bloom_level: bloomMatch ? `K${bloomMatch[1]}` : '',
    co: coMatch ? `CO${coMatch[1]}` : '',
  }
}

// One "1)i) 1)ii) 1)iii)"-style run: every line sharing the same leading
// number, buffered here until either an unrelated line breaks the run or
// the group body ends. flushCluster() below decides what it was:
//  - exactly one item, no OR seen → a flat leaf, unwrapped, using its own
//    already-combined label (buildLeafLabel — so a plain non-broken-down
//    question, e.g. "2. What is X?", still looks exactly as flat as ever).
//  - 2+ items, OR seen between them → a "choose" wrapper labeled "1" whose
//    children keep just their own local "i"/"ii" label (real alternatives
//    — only one of them is ever actually answered).
//  - 2+ items, no OR at all → an "all" wrapper labeled "1" whose children
//    likewise keep just their own local "i"/"ii" label (required
//    sub-parts, not alternatives — e.g. Group A's "1) i)...x)"). This
//    used to flatten each into its own leaf with the number baked
//    straight into the label ("1)i", "1)ii", ...), which read as ten
//    separate main questions instead of one question with ten parts.
//    Composing "1)i" back together at display time is QuestionNodeViewer
//    .vue's job (and QuestionNodeEditor.vue's non-editable prefix) — not
//    something baked into the stored label any more.
function buildLeafLabel(mainLabel, item) {
  return item.localLabel === mainLabel ? mainLabel : `${mainLabel})${item.localLabel}`
}

// Children of either wrapper keep just their own local marker ("i", "ii",
// ...) — see buildLeafLabel's docblock above for why. Falls back to
// position in the list ("i", "ii", ...) on the rare OCR misread where an
// alternative's own roman marker got garbled into matching the shared
// main number (which would otherwise duplicate the parent's own label).
function wrapperChildLabel(mainLabel, item, idx) {
  return item.localLabel === mainLabel ? (ORDINAL_ROMANS[idx] || String(idx + 1)) : item.localLabel
}

function parseGroupBody(label, body) {
  let i = 0
  const instructionLines = []
  while (i < body.length && !MAIN_NUM_RE.test(body[i]) && !ROMAN_SUB_RE.test(body[i]) && !OR_LINE_RE.test(body[i])) {
    instructionLines.push(body[i])
    i++
  }
  const instruction = instructionLines.join(' ').replace(/\s+/g, ' ').trim()

  let mode = 'all'
  let choose_count = ''
  const chooseMatch = instruction.match(CHOOSE_OUT_OF_RE) || instruction.match(CHOOSE_SIMPLE_RE)
  if (chooseMatch) {
    mode = 'choose'
    choose_count = Number(chooseMatch[1])
  }

  const marksMatch = instruction.match(MARKS_EACH_RE)
  const defaultMarks = marksMatch ? Number(marksMatch[1] || marksMatch[2]) : null

  const children = []
  let currentMain = null // last bare leading number seen, e.g. the "1." a run of roman sub-parts hangs off of
  let pendingCluster = null // { mainLabel, items: [{localLabel, marks}], hadOr } — see buildLeafLabel above
  let orPending = false // an "OR" line was just seen — the next item joins the cluster flagged as a real alternative, not just another required part

  function flushCluster() {
    if (!pendingCluster) return
    const { mainLabel, items, hadOr } = pendingCluster
    if (items.length === 1) {
      const item = items[0]
      children.push({ label: buildLeafLabel(mainLabel, item), mode: 'leaf', marks: item.marks, bloom_level: item.bloom_level, co: item.co })
    } else {
      children.push({
        label: mainLabel,
        // An "OR" ever appearing between this cluster's items means they're
        // real alternatives (only one is ever answered) — no "OR" at all
        // means they're required sub-parts of the same question (e.g.
        // Group A's "1) i)...x)"), which are *all* answered.
        mode: hadOr ? 'choose' : 'all',
        ...(hadOr ? { choose_count: 1 } : {}),
        children: items.map((item, idx) => ({ label: wrapperChildLabel(mainLabel, item, idx), mode: 'leaf', marks: item.marks, bloom_level: item.bloom_level, co: item.co })),
      })
    }
    pendingCluster = null
  }

  // Every item sharing the same leading number merges into one cluster,
  // regardless of how that number was obtained (read directly off this
  // line, e.g. "4)ii)", or the Group-A-style implicit "1" every bare roman
  // sub-part defaults to when no leading number was ever seen — see the
  // romanMatch branch below) — a real paper never reuses a question number
  // for unrelated content, so sharing one always means "same question".
  // Whether that becomes a "choose" (real alternatives) or "all" (required
  // sub-parts) wrapper is decided once at flushCluster() time from
  // whether "OR" ever actually appeared between any of them, not from
  // this merge decision.
  function addItem(key, item) {
    if (pendingCluster && pendingCluster.mainLabel === key) {
      pendingCluster.items.push(item)
    } else {
      flushCluster()
      pendingCluster = { mainLabel: key, items: [item], hadOr: false }
    }
    if (orPending) pendingCluster.hadOr = true
    orPending = false
  }

  for (; i < body.length; i++) {
    const line = body[i]
    if (OR_LINE_RE.test(line)) {
      orPending = true // the alternative itself arrives as its own numbered/roman line right after
      continue
    }

    const mainMatch = line.match(MAIN_NUM_RE)
    if (mainMatch) {
      currentMain = mainMatch[1]
      const rest = mainMatch[2].trim()
      if (!rest) continue // bare leading number ("1.") — sub-parts follow as separate roman-numeral lines below
      const restRoman = rest.match(ROMAN_SUB_RE)
      if (restRoman) {
        addItem(currentMain, leafFrom(restRoman[1], restRoman[2], defaultMarks))
      } else {
        addItem(currentMain, leafFrom(currentMain, rest, defaultMarks))
      }
      continue
    }

    const romanMatch = line.match(ROMAN_SUB_RE)
    if (romanMatch) {
      // A roman-lettered sub-part with no leading number ever seen in this
      // group (OCR often drops or garbles a bare "1." header line even
      // though the printed paper has one) — every group of this style seen
      // so far numbers its sub-parts under an implicit "1.", so default to
      // that rather than showing the sub-part with no number at all.
      if (currentMain === null) currentMain = '1'
      addItem(currentMain, leafFrom(romanMatch[1], romanMatch[2], defaultMarks))
      continue
    }

    // Anything else (wrapped continuation text, a taxonomy/CO tag column,
    // a nested "(i) ..." example list inside one question, page footers) is
    // not a new question line — skip it rather than guess. But if an "OR"
    // was just seen and this line reads like a real sentence (not short
    // OCR noise, e.g. a stray "|"), the alternative itself was unlabeled —
    // e.g. "viii) State reason for issue of Shares. Or What are the main
    // type of shares...?" has no roman marker of its own. Clear orPending
    // here so it doesn't wrongly carry forward and merge the next
    // genuinely separate numbered question into this one's cluster.
    if (orPending && line.length > 15) orPending = false
  }
  flushCluster()

  return { label, instruction, mode, choose_count, children }
}

/** Splits the full document's lines into Group blocks and parses each one. */
export function parseQuestionPaperStructure(lines) {
  const headerIdx = []
  lines.forEach((line, i) => {
    if (GROUP_HEADER_RE.test(line.trim())) headerIdx.push(i)
  })
  if (!headerIdx.length) return []

  return headerIdx
    .map((startIdx, gi) => {
      const endIdx = gi + 1 < headerIdx.length ? headerIdx[gi + 1] : lines.length
      const headerMatch = lines[startIdx].trim().match(GROUP_HEADER_RE)
      const label = `Group ${headerMatch[1].toUpperCase()}`
      const body = lines.slice(startIdx + 1, endIdx).map((l) => l.trim()).filter(Boolean)
      return parseGroupBody(label, body)
    })
    .filter((g) => g.children.length)
}

/** Convenience wrapper: run both extractors over an already-loaded pdf.js document. */
export async function autoFillFromPdf(pdfDoc, { onOcrProgress } = {}) {
  let lines = await extractDocumentLines(pdfDoc)
  let usedOcr = false

  // A born-digital PDF's header alone ("Full Marks", "Time Allotted",
  // subject name, ...) is already several lines — anything this sparse
  // means there's effectively no embedded text layer, i.e. a scanned or
  // photographed paper. Fall back to reading the page images with OCR.
  if (lines.length < 5) {
    lines = await extractDocumentLinesViaOCR(pdfDoc, { onProgress: onOcrProgress })
    usedOcr = true
  }

  return {
    ...parseHeaderFields(lines),
    groups: parseQuestionPaperStructure(lines),
    usedOcr,
  }
}
