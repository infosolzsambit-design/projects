// A minimal ZIP writer for downloads built in the browser (e.g. Report →
// Evaluated Copy Download). Files are STORED, not deflated — they're PDFs,
// which are already compressed, so deflating would cost time for almost no
// saving, and it keeps this dependency-free. File names are UTF-8.
// Limits: under 65,535 files and 4 GB in total (no ZIP64).

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    table[n] = c >>> 0
  }
  return table
})()

function crc32(bytes) {
  let crc = 0xffffffff
  for (let i = 0; i < bytes.length; i++) crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}

// DOS date/time of "now", as ZIP headers store it.
function dosDateTime(date = new Date()) {
  const time = (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2)
  const day = ((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate()
  return { time, day }
}

export class ZipWriter {
  constructor() {
    this.parts = [] // local headers + file data, in order
    this.central = [] // central directory records
    this.offset = 0
    this.names = new Set()
    this.encoder = new TextEncoder()
  }

  /** A name not already used in this ZIP ("a.pdf" → "a (2).pdf"). */
  uniqueName(name) {
    if (!this.names.has(name)) return name
    const dot = name.lastIndexOf('.')
    const [base, ext] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, '']
    let i = 2
    while (this.names.has(`${base} (${i})${ext}`)) i++
    return `${base} (${i})${ext}`
  }

  /**
   * @param {string} name   path inside the ZIP
   * @param {Uint8Array|string} data
   * @returns {string} the name actually used
   */
  add(name, data) {
    const bytes = typeof data === 'string' ? this.encoder.encode(data) : data
    const finalName = this.uniqueName(name)
    this.names.add(finalName)
    const nameBytes = this.encoder.encode(finalName)
    const crc = crc32(bytes)
    const { time, day } = dosDateTime()

    const local = new DataView(new ArrayBuffer(30))
    local.setUint32(0, 0x04034b50, true) // local file header signature
    local.setUint16(4, 20, true) // version needed
    local.setUint16(6, 0x0800, true) // flags: UTF-8 names
    local.setUint16(8, 0, true) // method: stored
    local.setUint16(10, time, true)
    local.setUint16(12, day, true)
    local.setUint32(14, crc, true)
    local.setUint32(18, bytes.length, true) // compressed size
    local.setUint32(22, bytes.length, true) // uncompressed size
    local.setUint16(26, nameBytes.length, true)
    local.setUint16(28, 0, true) // extra length
    this.parts.push(new Uint8Array(local.buffer), nameBytes, bytes)

    const central = new DataView(new ArrayBuffer(46))
    central.setUint32(0, 0x02014b50, true) // central directory signature
    central.setUint16(4, 20, true) // version made by
    central.setUint16(6, 20, true) // version needed
    central.setUint16(8, 0x0800, true)
    central.setUint16(10, 0, true)
    central.setUint16(12, time, true)
    central.setUint16(14, day, true)
    central.setUint32(16, crc, true)
    central.setUint32(20, bytes.length, true)
    central.setUint32(24, bytes.length, true)
    central.setUint16(28, nameBytes.length, true)
    central.setUint32(42, this.offset, true) // local header offset
    this.central.push(new Uint8Array(central.buffer), nameBytes)

    this.offset += 30 + nameBytes.length + bytes.length
    return finalName
  }

  /** @returns {Blob} the finished ZIP */
  toBlob() {
    const centralSize = this.central.reduce((n, part) => n + part.length, 0)
    const end = new DataView(new ArrayBuffer(22))
    end.setUint32(0, 0x06054b50, true) // end of central directory signature
    end.setUint16(8, this.names.size, true) // entries on this disk
    end.setUint16(10, this.names.size, true) // entries in total
    end.setUint32(12, centralSize, true)
    end.setUint32(16, this.offset, true) // central directory offset
    return new Blob([...this.parts, ...this.central, new Uint8Array(end.buffer)], { type: 'application/zip' })
  }
}
