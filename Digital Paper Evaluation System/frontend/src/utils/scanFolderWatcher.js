// Scan Papers → Scan: watches the folder the scanner's own software saves
// each scan into (File System Access API — Chrome/Edge, HTTPS) and hands
// every new PDF that appears to a callback, one at a time.
//
// - Polls the folder (POLL_MS) rather than using FileSystemObserver, which
//   is still experimental.
// - PDFs already in the folder when watching starts aren't taken
//   automatically (they may be earlier scans of other packets) — they're
//   returned as `existing`, for the page to offer "Scan These In".
// - A new file is only handed over once its size has stayed the same for one
//   poll — the scanner may still be writing it.
// - The chosen folder is remembered (IndexedDB) so next time it's one click
//   ("Reconnect") instead of browsing for it again; the browser still asks
//   for permission once per visit.
// - Read AND write: once a scan is accepted / rejected, moveToSubfolder()
//   moves its file into the folder's own "scanned_copy" / "rejected_copy"
//   subfolder (created if needed) — subfolders are never watched themselves.
//
// Each scan is handed over as { file, handle } — the File to read, and its
// FileSystemFileHandle so it can be moved afterwards.

const POLL_MS = 1500
const DB_NAME = 'scan-papers'
const STORE = 'handles'
const KEY = 'scanner-folder'
export const SCANNED_COPY_FOLDER = 'scanned_copy'
export const REJECTED_COPY_FOLDER = 'rejected_copy'

export const folderWatchSupported = typeof window !== 'undefined' && 'showDirectoryPicker' in window

function idb(mode, run) {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB_NAME, 1)
    open.onupgradeneeded = () => open.result.createObjectStore(STORE)
    open.onerror = () => reject(open.error)
    open.onsuccess = () => {
      // A throw in here (e.g. a value that can't be stored) must reject, not
      // leave the promise hanging.
      try {
        const tx = open.result.transaction(STORE, mode)
        const request = run(tx.objectStore(STORE))
        tx.oncomplete = () => resolve(request?.result)
        tx.onerror = () => reject(tx.error)
      } catch (error) {
        reject(error)
      }
    }
  })
}

/** Ask the user for the scanner's folder (and remember it). */
export async function pickScanFolder() {
  const handle = await window.showDirectoryPicker({ id: 'scanner-folder', mode: 'readwrite' })
  try {
    await idb('readwrite', (store) => store.put(handle, KEY))
  } catch {
    // Not remembered — they'll just pick it again next time.
  }
  return handle
}

/** The folder picked last time, if any (permission not yet granted). */
export async function rememberedScanFolder() {
  try {
    return (await idb('readonly', (store) => store.get(KEY))) || null
  } catch {
    return null
  }
}

/** Read + write (to move accepted scans). Needs a click (user gesture) when not yet granted. */
export async function ensureFolderPermission(handle) {
  if ((await handle.queryPermission({ mode: 'readwrite' })) === 'granted') return true
  return (await handle.requestPermission({ mode: 'readwrite' })) === 'granted'
}

async function listPdfs(handle) {
  const files = []
  for await (const entry of handle.values()) {
    if (entry.kind !== 'file' || !entry.name.toLowerCase().endsWith('.pdf')) continue
    try {
      files.push({ file: await entry.getFile(), handle: entry })
    } catch {
      // Being written / locked right now — next poll.
    }
  }
  return files
}

const keyOf = ({ file }) => `${file.name}|${file.lastModified}`
const byAge = (a, b) => a.file.lastModified - b.file.lastModified

/**
 * Starts watching. Returns { stop, existing }.
 * @param {FileSystemDirectoryHandle} handle
 * @param {(scan: {file: File, handle: FileSystemFileHandle}) => void} onNewFile  once per new, finished PDF
 * @param {(error: Error) => void} onError   e.g. the folder was removed / permission revoked
 * @param {(info: {checkedAt: Date, pdfCount: number}) => void} [onPoll]  after every check
 * @returns {Promise<{stop: () => void, existing: Array<{file: File, handle: FileSystemFileHandle}>}>} existing = the PDFs already there
 */
export async function watchScanFolder(handle, onNewFile, onError, onPoll) {
  const existing = (await listPdfs(handle)).sort(byAge)
  const seen = new Set(existing.map(keyOf))
  onPoll?.({ checkedAt: new Date(), pdfCount: existing.length })
  const pendingSize = new Map() // key -> size at last poll
  let stopped = false
  let timer = null

  const poll = async () => {
    if (stopped) return
    try {
      const files = (await listPdfs(handle)).sort(byAge)
      onPoll?.({ checkedAt: new Date(), pdfCount: files.length })
      for (const scan of files) {
        const key = keyOf(scan)
        if (seen.has(key)) continue
        if (scan.file.size > 0 && pendingSize.get(key) === scan.file.size) {
          seen.add(key)
          pendingSize.delete(key)
          onNewFile(scan)
        } else {
          pendingSize.set(key, scan.file.size)
        }
      }
    } catch (error) {
      stopped = true
      onError?.(error)
      return
    }
    timer = setTimeout(poll, POLL_MS)
  }
  timer = setTimeout(poll, POLL_MS)

  return {
    existing,
    stop: () => {
      stopped = true
      clearTimeout(timer)
    },
  }
}

// "Scanned Document.pdf" → "Scanned Document (2).pdf", … — a name not yet
// used in `dir` (Simple Scan, for one, saves every scan under the same name).
async function freeName(dir, name) {
  const dot = name.lastIndexOf('.')
  const stem = dot > 0 ? name.slice(0, dot) : name
  const ext = dot > 0 ? name.slice(dot) : ''
  for (let n = 1; ; n++) {
    const candidate = n === 1 ? name : `${stem} (${n})${ext}`
    try {
      await dir.getFileHandle(candidate)
    } catch {
      return candidate // not there
    }
  }
}

/**
 * Moves a scan from the scanner folder into one of its subfolders —
 * SCANNED_COPY_FOLDER (accepted) or REJECTED_COPY_FOLDER (rejected) —
 * creating it if it isn't there. Returns the name it got there.
 * @param {FileSystemDirectoryHandle} folder  the scanner folder
 * @param {FileSystemFileHandle} fileHandle   the scan, directly inside it
 * @param {string} subfolder                  the subfolder's name
 */
export async function moveToSubfolder(folder, fileHandle, subfolder) {
  const target = await folder.getDirectoryHandle(subfolder, { create: true })
  const name = await freeName(target, fileHandle.name)
  // Native move where the browser has it; otherwise copy, then delete.
  if (typeof fileHandle.move === 'function') {
    try {
      await fileHandle.move(target, name)
      return name
    } catch {
      // fall through to copy + delete
    }
  }
  const copy = await target.getFileHandle(name, { create: true })
  const writable = await copy.createWritable()
  await writable.write(await fileHandle.getFile())
  await writable.close()
  await folder.removeEntry(fileHandle.name)
  return name
}
