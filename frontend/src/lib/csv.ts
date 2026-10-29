/**
 * Reading a bank's CSV file in the browser: the text (in the file's
 * encoding), the separator, the table, and each row's day, signed amount and
 * words, as the user mapped the columns (`CsvSettings`, saved as a preset).
 * Pure functions: the server only sees the rows.
 */

export type CsvEncoding = 'utf-8' | 'windows-1252'
export type DecimalSeparator = ',' | '.'
export const DATE_FORMATS = ['dd/MM/yyyy', 'dd-MM-yyyy', 'dd.MM.yyyy', 'yyyy-MM-dd', 'MM/dd/yyyy', 'dd/MM/yy'] as const
export type DateFormat = (typeof DATE_FORMATS)[number]

export interface CsvSettings {
  encoding: CsvEncoding
  delimiter: string
  /** Lines before the header (some banks put the account's details on top). */
  skipRows: number
  dateColumn: number
  dateFormat: DateFormat
  /** One signed column, or two: money out (debit) and in (credit). */
  amountMode: 'single' | 'split'
  amountColumn: number
  debitColumn: number
  creditColumn: number
  decimal: DecimalSeparator
  /** Some banks write money out as positive. */
  invert: boolean
  /** Joined with " · ": "Operazione" and "Dettagli", for instance. */
  descriptionColumns: number[]
  /** The other side's name, when the file has it. */
  counterpartyColumn: number | null
}

export interface CsvRow {
  /** The line in the file (1-based, header included), to say which one is wrong. */
  line: number
  date: string | null
  /** Signed, "." for decimals: negative is out of the account. */
  amount: string | null
  description: string
  counterparty: string
}

/** The file as text: UTF-8 when it is valid UTF-8, else what the user chose (older Italian banks write Windows-1252). */
export function decode(buffer: ArrayBuffer, encoding: CsvEncoding): string {
  return new TextDecoder(encoding).decode(buffer).replace(/^\uFEFF/, '')
}

/** The separator that splits the first lines into the same number of fields, the most of them. */
export function detectDelimiter(text: string): string {
  const lines = text.split(/\r?\n/).filter((line) => line.trim() !== '').slice(0, 15)
  let best = ';'
  let bestScore = 0
  for (const candidate of [';', ',', '\t', '|']) {
    const counts = lines.map((line) => parse(line, candidate)[0]?.length ?? 0)
    const common = mode(counts)
    const consistent = counts.filter((count) => count === common).length
    const score = common > 1 ? consistent * common : 0
    if (score > bestScore) {
      best = candidate
      bestScore = score
    }
  }
  return best
}

/** The table: quoted fields, "" inside quotes, separators and line breaks inside quotes, CRLF or LF. */
export function parse(text: string, delimiter: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let quoted = false
  for (let i = 0; i < text.length; i++) {
    const char = text[i]
    if (quoted) {
      if (char === '"' && text[i + 1] === '"') {
        field += '"'
        i++
      } else if (char === '"') {
        quoted = false
      } else {
        field += char
      }
    } else if (char === '"' && field.trim() === '') {
      quoted = true
      field = ''
    } else if (char === delimiter) {
      row.push(field)
      field = ''
    } else if (char === '\n' || char === '\r') {
      if (char === '\r' && text[i + 1] === '\n') i++
      row.push(field)
      rows.push(row)
      row = []
      field = ''
    } else {
      field += char
    }
  }
  if (field !== '' || row.length > 0) {
    row.push(field)
    rows.push(row)
  }
  return rows.filter((r) => r.some((cell) => cell.trim() !== ''))
}

/**
 * An amount as banks write it ("-1.234,56", "1,234.56", "€ 12,50", "(12.50)",
 * "12,50-") as a signed decimal with "."; null when it isn't one.
 */
export function parseAmount(raw: string, decimal: DecimalSeparator): string | null {
  let text = raw.trim().replace(/[€$£\s\u00a0]|EUR|USD|GBP/gi, '')
  if (text === '') return null
  let negative = false
  if (/^\(.*\)$/.test(text)) {
    negative = true
    text = text.slice(1, -1)
  }
  if (text.endsWith('-')) {
    negative = true
    text = text.slice(0, -1)
  }
  if (text.startsWith('-')) {
    negative = !negative
    text = text.slice(1)
  } else if (text.startsWith('+')) {
    text = text.slice(1)
  }
  const thousands = decimal === ',' ? '.' : ','
  text = text.split(thousands).join('').replace(decimal, '.')
  if (!/^\d+(\.\d+)?$/.test(text)) return null
  const value = text.replace(/^0+(?=\d)/, '')
  return negative && Number(value) !== 0 ? `-${value}` : value
}

/** "," when the amounts use it for decimals (12,50 or 1.234,56), else ".". */
export function detectDecimal(samples: string[]): DecimalSeparator {
  let comma = 0
  let dot = 0
  for (const sample of samples) {
    const text = sample.replace(/[^\d.,]/g, '')
    const lastComma = text.lastIndexOf(',')
    const lastDot = text.lastIndexOf('.')
    if (lastComma > lastDot && text.length - lastComma <= 3) comma++
    else if (lastDot > lastComma && text.length - lastDot <= 3) dot++
  }
  return comma >= dot ? ',' : '.'
}

/** A day as "yyyy-MM-dd", or null when it isn't one in that format (a time after it is ignored). */
export function parseDate(raw: string, format: DateFormat): string | null {
  const text = raw.trim().split(/[ T]/)[0]
  const parts = text.split(/[/.-]/)
  if (parts.length !== 3) return null
  const order = format.split(/[/.-]/)
  let day = 0
  let month = 0
  let year = 0
  order.forEach((piece, i) => {
    const value = Number(parts[i])
    if (piece.startsWith('d')) day = value
    else if (piece.startsWith('M')) month = value
    else year = piece.length === 2 ? 2000 + value : value
  })
  if (!year || month < 1 || month > 12 || day < 1 || day > 31) return null
  const date = new Date(Date.UTC(year, month - 1, day))
  if (date.getUTCMonth() !== month - 1) return null
  const separator = format.includes('/') ? '/' : format.includes('.') ? '.' : '-'
  if (!text.includes(separator)) return null
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/** The first format that reads every sample, else the Italian one. */
export function detectDateFormat(samples: string[]): DateFormat {
  const filled = samples.filter((sample) => sample.trim() !== '')
  return DATE_FORMATS.find((format) => filled.length > 0 && filled.every((sample) => parseDate(sample, format) !== null)) ?? 'dd/MM/yyyy'
}

/** The header, as presets compare it: lower-case, trimmed, no empty trailing columns. */
export function headerKey(headers: string[]): string {
  const cells = headers.map((header) => header.trim().toLowerCase())
  while (cells.length > 0 && cells[cells.length - 1] === '') cells.pop()
  return cells.join('|')
}

/** The header's line: the first with as many fields as most lines have (some banks put the account's details above it). */
export function detectSkipRows(table: string[][]): number {
  const width = mode(table.map((row) => row.length))
  return Math.max(0, table.findIndex((row) => row.length === width))
}

/**
 * A first guess of the columns from the header's names (Italian and English
 * banks) and the data under them; the header found by itself unless given.
 */
export function guessSettings(table: string[][], encoding: CsvEncoding, delimiter: string, skipRows = detectSkipRows(table)): CsvSettings {
  const header = (table[skipRows] ?? []).map((cell) => cell.trim().toLowerCase())
  const body = table.slice(skipRows + 1, skipRows + 30)
  const find = (...names: string[]) => header.findIndex((cell) => names.some((name) => cell.includes(name)))
  const dateColumn = Math.max(0, find('data contabile', 'data operazione', 'data valuta', 'data', 'date', 'booking'))
  const debitColumn = find('uscite', 'addebit', 'dare', 'debit')
  const creditColumn = find('entrate', 'accredit', 'avere', 'credit')
  const amountColumn = Math.max(0, find('importo', 'amount', 'ammontare', 'valore'))
  const split = debitColumn >= 0 && creditColumn >= 0
  const descriptionColumns = header
    .map((cell, i) => (/(descri|causale|operazione|dettagli|details|memo|reference|riferimento)/.test(cell) ? i : -1))
    .filter((i) => i >= 0 && i !== dateColumn)
  const counterpartyColumn = find('beneficiario', 'controparte', 'esercente', 'merchant', 'payee', 'counterparty')
  const amountSamples = body.flatMap((row) => (split ? [row[debitColumn] ?? '', row[creditColumn] ?? ''] : [row[amountColumn] ?? '']))
  return {
    encoding,
    delimiter,
    skipRows,
    dateColumn,
    dateFormat: detectDateFormat(body.map((row) => row[dateColumn] ?? '')),
    amountMode: split ? 'split' : 'single',
    amountColumn,
    debitColumn: Math.max(0, debitColumn),
    creditColumn: Math.max(0, creditColumn),
    decimal: detectDecimal(amountSamples.filter((sample) => sample.trim() !== '')),
    invert: false,
    descriptionColumns: descriptionColumns.length > 0 ? descriptionColumns : [Math.min(header.length - 1, dateColumn + 1)],
    counterpartyColumn: counterpartyColumn >= 0 && !descriptionColumns.includes(counterpartyColumn) ? counterpartyColumn : null,
  }
}

/** The data lines read as the settings say (header and lines before it left out). */
export function toRows(table: string[][], settings: CsvSettings): CsvRow[] {
  return table.slice(settings.skipRows + 1).map((cells, i) => {
    let amount: string | null
    if (settings.amountMode === 'split') {
      const debit = parseAmount(cells[settings.debitColumn] ?? '', settings.decimal)
      const credit = parseAmount(cells[settings.creditColumn] ?? '', settings.decimal)
      amount = debit !== null && Number(debit) !== 0 ? `-${debit.replace(/^-/, '')}` : credit !== null ? credit.replace(/^-/, '') : null
    } else {
      amount = parseAmount(cells[settings.amountColumn] ?? '', settings.decimal)
    }
    if (amount !== null && settings.invert) amount = amount.startsWith('-') ? amount.slice(1) : `-${amount}`
    return {
      line: settings.skipRows + i + 2,
      date: parseDate(cells[settings.dateColumn] ?? '', settings.dateFormat),
      amount,
      description: settings.descriptionColumns.map((column) => (cells[column] ?? '').trim()).filter(Boolean).join(' · '),
      counterparty: settings.counterpartyColumn !== null ? (cells[settings.counterpartyColumn] ?? '').trim() : '',
    }
  })
}

function mode(values: number[]): number {
  const counts = new Map<number, number>()
  for (const value of values) counts.set(value, (counts.get(value) ?? 0) + 1)
  let best = 0
  let bestCount = 0
  for (const [value, count] of counts) {
    if (count > bestCount || (count === bestCount && value > best)) {
      best = value
      bestCount = count
    }
  }
  return best
}
