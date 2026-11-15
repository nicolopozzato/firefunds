/**
 * An Excel or OpenDocument spreadsheet (.xlsx, .xls, .ods) as the same
 * table of text a CSV file gives, so the rest of the import reads both
 * alike: numbers written with "." for decimals, dates as yyyy-MM-dd (with
 * the time after a space, when there is one). SheetJS is loaded only when
 * such a file is opened.
 */

export const SPREADSHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods']

export function isSpreadsheet(fileName: string): boolean {
  return SPREADSHEET_EXTENSIONS.includes(fileName.split('.').pop()?.toLowerCase() ?? '')
}

export interface Workbook {
  sheets: string[]
  table: (sheet: string) => string[][]
}

export async function readWorkbook(data: ArrayBuffer): Promise<Workbook> {
  const XLSX = await import('xlsx')
  const book = XLSX.read(data, { type: 'array', cellDates: false, cellText: false })
  return {
    sheets: book.SheetNames,
    table: (name) => {
      const sheet = book.Sheets[name]
      if (!sheet?.['!ref']) return []
      const range = XLSX.utils.decode_range(sheet['!ref'])
      const rows: string[][] = []
      for (let r = range.s.r; r <= range.e.r; r++) {
        const row: string[] = []
        for (let c = range.s.c; c <= range.e.c; c++) {
          const cell = sheet[XLSX.utils.encode_cell({ r, c })]
          row.push(cell ? cellText(cell, XLSX.SSF) : '')
        }
        if (row.some((value) => value.trim() !== '')) rows.push(row)
      }
      return rows
    },
  }
}

interface Cell {
  t: string
  v?: unknown
  z?: string | number
  w?: string
}

interface Ssf {
  is_date: (format: string | number) => boolean
  parse_date_code: (value: number) => { y: number; m: number; d: number; H: number; M: number; S: number } | null
}

function cellText(cell: Cell, ssf: Ssf): string {
  if (cell.t === 'n' && typeof cell.v === 'number') {
    if (cell.z !== undefined && ssf.is_date(cell.z)) {
      const date = ssf.parse_date_code(cell.v)
      if (date) {
        const day = `${date.y}-${pad(date.m)}-${pad(date.d)}`
        return date.H || date.M || date.S ? `${day} ${pad(date.H)}:${pad(date.M)}:${pad(date.S)}` : day
      }
    }
    // Rounded like a currency figure is: binary floats write 0.1 + 0.2 as 0.30000000000000004.
    return String(Math.round(cell.v * 1e8) / 1e8)
  }
  if (cell.t === 'b') return cell.v ? 'TRUE' : 'FALSE'
  if (cell.t === 'e') return ''
  return String(cell.v ?? cell.w ?? '')
}

const pad = (value: number) => String(value).padStart(2, '0')
