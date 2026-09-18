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
// 1-2 digits, not 3 — a real exam paper is never 100+ questions long, and
// allowing 3 let stray numeric residue from wrapped body text (e.g. an OCR
// line break landing right after "...Sales Price 700)" mid-sentence) get
// misread as a genuine bare question-number header, corrupting every
// sub-part line that followed it (see parseGroupBody()'s own
// usingCombinedSubScheme for the other half of that same class of bug).
const MAIN_NUM_RE = /^(\d{1,2})[.)]\s*(.*)$/
// A leading "(" is optional — some papers print "i)", others "(i)" (see
// designed_files/question_paper_2.pdf's Group A) — both close on ")" or ".".
const ROMAN_SUB_RE = /^\(?([ivx]{1,6})[.)]\s*(.*)$/i
// A *second*, distinct sub-part convention some papers use instead of
// roman numerals: "3. a)", "8.b)", "9.a)" — a lowercase letter glued
// straight onto the main number, always on the same line (see
// question_paper_2.pdf's Group B/C — unlike the roman convention, each
// lettered item is its own independent top-level question, never a
// required sub-part of a shared parent; see parseGroupBody()'s own
// handling below for why the combined "N.letter" string is used as the
// cluster key directly instead of just "N"). Checked only after
// ROMAN_SUB_RE fails, so an unavoidably-ambiguous single letter like "i)"
// still resolves as roman first — the far more common convention when
// either reading is possible.
// "¢" also accepted for the letter itself — Tesseract frequently misreads
// a lowercase "c" right after a period as the cent sign (their shapes are
// close: a vertical stroke through a "c"), e.g. "8.¢)" for "8.c)" — see
// normalizeOcrLetter() below, applied only to this one captured character.
const LETTER_SUB_RE = /^\(?([a-z¢])[.)]\s*(.*)$/i
const FULL_MARKS_RE = /full\s*marks\s*[:\-]?\s*(\d{1,4})/i
const TIME_ALLOTTED_RE = /time\s*allotted\s*[:\-]?\s*(\d+(?:\.\d+)?\s*(?:hours?|hrs?|minutes?|mins?))/i
// Covers common real-world phrasings: "10 out of 12", "any 5 out of 7
// questions", "any 2 of the following 3 questions", "choose 3 from 6" — the
// filler words ("the", "following", "questions") between the two numbers
// are optional so wording differences don't break the match.
const CHOOSE_OUT_OF_RE = /(?:answer|attempt|choose|do|solve)?\s*(?:any\s+)?(\d+)\s*(?:questions?)?\s*(?:out\s*of|from\s*among|from|of)\s*(?:the\s+)?(?:following\s+)?(\d+)/i
const CHOOSE_SIMPLE_RE = /(?:answer|attempt|choose|do|solve)\s+(?:any\s+)?(\d+)\b/i
const MARKS_EACH_RE = /(\d+)\s*marks?\s*each|each\s*(?:question\s*)?carr(?:y|ies)\s*(\d+)\s*marks?/i
// A group's own opening instruction sentence ("Answer all the following
// questions. Each question carries 2 marks.") is frequently printed with
// a leading "1." of its own (see designed_files/question_paper_2.pdf's
// Group A) — structurally identical to a real "1. <question text>" line,
// but it isn't one: the real numbered content is the roman sub-parts that
// follow. Checked against a mainMatch's own trailing text in
// parseGroupBody()'s instruction-collection loop below, so this boilerplate
// keeps being treated as more instruction text instead of prematurely
// ending that loop and getting swallowed whole as a bogus "question 1".
const GROUP_INSTRUCTION_BOILERPLATE_RE = /^answer\s+(all|any)\b/i
// A per-question marks value that trails the whole line in plain brackets,
// e.g. "...Rate of Depreciation. [5]" or "(5 marks)" — paper_1's own style.
// Allows a "+"-summed value too ("(2+3)"), same as MARKS_AFTER_BLOOM_RE
// below, for a paper that prints the split without a K-tag right before it.
const INLINE_MARKS_RE = /[[(]\s*([\d+\s]+?)\s*(?:marks?)?\s*[\])]\s*$/i
// Many papers print two extra columns per question: a Bloom's Taxonomy
// level (K1, K2, ...) and a Course Outcome (CO1, CO2, ...) — see
// designed_files/question_paper_1.pdf's right-hand columns. Both are
// optional per QuestionPaperNode; only ever filled in here when the text
// actually has them, never guessed. The captured digit also accepts OCR's
// most common digit/letter confusions (l/I for 1, O/o for 0 — e.g. "COl"
// misread for "CO1") — see normalizeOcrDigit() below, applied to the
// capture only, never to the surrounding real text.
const BLOOM_LEVEL_RE = /\bK\s*([1-6lI])\b/i
const CO_RE = /\bCO\s*([0-9lIOo]{1,2})\b/i
// A marks value (or a "2+3"-style split of it — see designed_files/
// question_paper_2.pdf's Group C, "K4 (3+3)" meaning one question worth
// two parts of 3 marks each, summed here to 6) printed in parens right
// after the Bloom tag — this paper's own convention for where per-question
// marks actually live, distinct from paper_1's plain trailing-bracket
// style (INLINE_MARKS_RE above), and checked first since it's the more
// precisely-anchored of the two.
const MARKS_AFTER_BLOOM_RE = /\bK\s*[1-6lI]\s*\(\s*([\d+\s]+?)\s*\)/i
// Positional fallback labels for a choose-cluster's children — see
// flushCluster() below.
const ORDINAL_ROMANS = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x']

// Tesseract's single most common failure mode on these short, all-caps
// tags is confusing 1/l/I and 0/O/o — normalizes just a captured tag
// value (never a whole line) before it's used as a real digit.
function normalizeOcrDigit(token) {
  return token.replace(/[lI]/g, '1').replace(/[Oo]/g, '0')
}

// See LETTER_SUB_RE's own docblock — "¢" is the one confusable this
// covers so far, added only once actually seen misread this way.
function normalizeOcrLetter(ch) {
  return ch === '¢' ? 'c' : ch.toLowerCase()
}

// Sums a "3+3" (or plain "6") marks capture into one number — null if
// nothing numeric survived (rather than 0, which would read as a real,
// deliberately-zero-mark question).
function sumMarksText(raw) {
  const total = raw
    .split('+')
    .map((part) => Number(part.trim()))
    .filter((n) => !Number.isNaN(n))
    .reduce((a, b) => a + b, 0)
  return total || null
}

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
  // Most-precisely-anchored first: a marks value sitting right after the
  // Bloom tag (possibly a "3+3" split, summed) beats a bare trailing
  // bracket, which in turn beats the group instruction's own default.
  const afterBloomMatch = trailingText.match(MARKS_AFTER_BLOOM_RE)
  const inlineMatch = trailingText.match(INLINE_MARKS_RE)
  const bloomMatch = trailingText.match(BLOOM_LEVEL_RE)
  const coMatch = trailingText.match(CO_RE)
  const marks = afterBloomMatch
    ? sumMarksText(afterBloomMatch[1])
    : inlineMatch
      ? sumMarksText(inlineMatch[1])
      : null
  return {
    localLabel,
    marks: marks ?? (defaultMarks || ''),
    bloom_level: bloomMatch ? `K${normalizeOcrDigit(bloomMatch[1])}` : '',
    co: coMatch ? `CO${normalizeOcrDigit(coMatch[1])}` : '',
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
  while (i < body.length) {
    const line = body[i]
    // See GROUP_INSTRUCTION_BOILERPLATE_RE's own docblock — a mainMatch
    // whose own trailing text is itself instruction boilerplate is more
    // instruction text, not the real first question; strip the leading
    // "1." noise off before keeping it, same as every other instruction
    // line collected here.
    const boilerplateMatch = line.match(MAIN_NUM_RE)
    if (boilerplateMatch && GROUP_INSTRUCTION_BOILERPLATE_RE.test(boilerplateMatch[2].trim())) {
      instructionLines.push(boilerplateMatch[2].trim())
      i++
      continue
    }
    if (MAIN_NUM_RE.test(line) || ROMAN_SUB_RE.test(line) || OR_LINE_RE.test(line)) break
    instructionLines.push(line)
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
  // True whenever the most recently seen main-numbered line already
  // carried real content of its own (i.e. wasn't a bare "1." header with
  // sub-parts still to come) — reset fresh at every mainMatch, not latched
  // for the whole group, since one question in a group being self-
  // contained must never suppress a *different* question in that same
  // group that genuinely is a bare header (see the mainMatch branch below
  // for where this is set).
  //
  // Combined with a *parenthesized* bare roman line specifically (see the
  // romanMatch branch below), this tells apart two things that look
  // identical in isolation:
  //  - designed_files/question_paper_1.pdf's own "5) 1) Journalise the
  //    followings" going on to list four parenthesized "(i)"/"(ii)"/
  //    "(iii)"/"(iv)" *examples* — part of that one already-self-contained
  //    question's own text, not four more questions to score separately.
  //  - that same paper's "9) 1) ... OR ... ii) Cash in Hand..." — a
  //    genuine second alternative for question 9, arriving as its own
  //    bare (not parenthesized) roman line with no leading number of its
  //    own, exactly like the roman-numeral fallback below already expects.
  // A parenthesized roman line when nothing self-contained precedes it at
  // all (e.g. Group A's own "(i)"-"(x)", each a real separate question)
  // is never suppressed by this — usingCombinedSubScheme stays false for
  // a true bare "N." header the whole way through its own sub-parts.
  let usingCombinedSubScheme = false
  // mainLabel -> index into `children`, for every cluster already flushed —
  // lets a key that reappears *after* its first cluster was already
  // flushed (the next paragraph below) merge into that same child instead
  // of becoming a bogus, wrongly-duplicate-labeled sibling.
  const flushedIndexByKey = new Map()

  // A cluster is normally only ever built once per key (see addItem below)
  // — but OCR on a scanned paper frequently loses the literal "OR" row
  // between a question and its alternative entirely (a thin, centered,
  // low-text row is exactly the kind of table row line-detection most
  // often drops), which would otherwise silently orphan that alternative
  // as if it were an unrelated question sharing the same number. Since a
  // real paper never reuses a question number for anything *but* an
  // alternative (see this function's own long-standing assumption below),
  // a key showing up again after its first cluster already flushed is
  // itself enough evidence of a lost "OR" — no literal "OR" text required.
  function flushCluster() {
    if (!pendingCluster) return
    const { mainLabel, items, hadOr } = pendingCluster
    pendingCluster = null

    if (flushedIndexByKey.has(mainLabel)) {
      const idx = flushedIndexByKey.get(mainLabel)
      const existing = children[idx]
      const existingChildren = existing.mode === 'leaf'
        ? [{ label: wrapperChildLabel(mainLabel, { localLabel: mainLabel }, 0), mode: 'leaf', marks: existing.marks, bloom_level: existing.bloom_level, co: existing.co }]
        : existing.children
      const newChildren = items.map((item, idx2) => ({
        label: wrapperChildLabel(mainLabel, item, existingChildren.length + idx2),
        mode: 'leaf',
        marks: item.marks,
        bloom_level: item.bloom_level,
        co: item.co,
      }))
      children[idx] = { label: mainLabel, mode: 'choose', choose_count: 1, children: [...existingChildren, ...newChildren] }
      return
    }

    if (items.length === 1 && !hadOr) {
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
    flushedIndexByKey.set(mainLabel, children.length - 1)
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
      // Re-evaluated per question, not latched for the rest of the group —
      // see this variable's own docblock above.
      usingCombinedSubScheme = false
      const rest = mainMatch[2].trim()
      if (!rest) continue // bare leading number ("1.") — sub-parts follow as separate roman-numeral lines below
      usingCombinedSubScheme = true
      const restRoman = rest.match(ROMAN_SUB_RE)
      const restLetter = !restRoman && rest.match(LETTER_SUB_RE)
      if (restRoman) {
        addItem(currentMain, leafFrom(restRoman[1], restRoman[2], defaultMarks))
      } else if (restLetter) {
        // Each lettered item is its own independent top-level question
        // (never a required sub-part of a shared "N" — see LETTER_SUB_RE's
        // own docblock), so the combined "N.letter" string is the cluster
        // key itself, not just "N".
        const combinedKey = `${currentMain}.${normalizeOcrLetter(restLetter[1])}`
        addItem(combinedKey, leafFrom(combinedKey, restLetter[2], defaultMarks))
      } else {
        addItem(currentMain, leafFrom(currentMain, rest, defaultMarks))
      }
      continue
    }

    const romanMatch = line.match(ROMAN_SUB_RE)
    // A *parenthesized* bare roman line ("(i)") while the current question
    // is already self-contained is an illustrative example, not a new
    // question — a non-parenthesized one ("ii)") always still counts,
    // since that's the shape a genuine unlabeled-number OR-alternative
    // continuation actually takes (see usingCombinedSubScheme's own
    // docblock for both real cases this tells apart).
    if (romanMatch && !(usingCombinedSubScheme && line.trim().startsWith('('))) {
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
    // a nested "(i) ..." example list inside one question, page footers,
    // or — once usingCombinedSubScheme is set — a bare roman-looking line
    // that's really just an illustrative sub-list inside one already-
    // numbered question's own body) is not a new question line — skip it
    // rather than guess.
    // But if an "OR" was just seen and this line reads like a real
    // sentence (not short OCR noise, e.g. a stray "|"), the alternative
    // itself was unlabeled — e.g. "viii) State reason for issue of Shares.
    // Or What are the main type of shares...?" has no roman marker of its
    // own. Clear orPending here so it doesn't wrongly carry forward and
    // merge the next genuinely separate numbered question into this one's
    // cluster.
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
