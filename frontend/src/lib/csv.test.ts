import { describe, expect, it } from 'vitest'
import { detectDateFormat, detectDecimal, detectDelimiter, guessSettings, parse, parseAmount, parseDate, toRows } from './csv'

describe('parse', () => {
  it('reads quoted fields, quotes inside them and line breaks', () => {
    expect(parse('a;"b;c";"say ""hi"""\r\n1;"two\nlines";3\n', ';')).toEqual([
      ['a', 'b;c', 'say "hi"'],
      ['1', 'two\nlines', '3'],
    ])
  })

  it('finds the separator', () => {
    expect(detectDelimiter('Data;Descrizione;Importo\n01/10/2026;Bar, centro;-2,50\n')).toBe(';')
    expect(detectDelimiter('Date,Description,Amount\n2026-10-01,Coffee,-2.50\n')).toBe(',')
  })
})

describe('amounts', () => {
  it('reads how banks write them', () => {
    expect(parseAmount('-1.234,56', ',')).toBe('-1234.56')
    expect(parseAmount('€ 12,50', ',')).toBe('12.50')
    expect(parseAmount('12,50-', ',')).toBe('-12.50')
    expect(parseAmount('(1,234.00)', '.')).toBe('-1234.00')
    expect(parseAmount('+7', '.')).toBe('7')
    expect(parseAmount('n/a', '.')).toBeNull()
  })

  it('tells the decimal separator', () => {
    expect(detectDecimal(['-12,50', '1.234,56'])).toBe(',')
    expect(detectDecimal(['-12.50', '1,234.56'])).toBe('.')
  })
})

describe('dates', () => {
  it('reads a day in a format, and only real days', () => {
    expect(parseDate('08/11/2026', 'dd/MM/yyyy')).toBe('2026-11-08')
    expect(parseDate('2026-11-08 10:30', 'yyyy-MM-dd')).toBe('2026-11-08')
    expect(parseDate('31/02/2026', 'dd/MM/yyyy')).toBeNull()
    expect(parseDate('08/11/26', 'dd/MM/yy')).toBe('2026-11-08')
  })

  it('finds the format every sample reads in', () => {
    expect(detectDateFormat(['25/10/2026', '08/11/2026'])).toBe('dd/MM/yyyy')
    expect(detectDateFormat(['2026-10-25'])).toBe('yyyy-MM-dd')
  })
})

describe('a whole file', () => {
  it('finds the header under the account details', () => {
    const table = parse('Conto;IT60X\nMovimenti dal 01/10\nData;Descrizione;Importo\n25/10/2026;Bar;-2,50\n26/10/2026;Pane;-1,80\n', ';')
    const settings = guessSettings(table, 'utf-8', ';')
    expect(settings.skipRows).toBe(2)
    expect(toRows(table, settings)[0]).toEqual({ line: 4, date: '2026-10-25', amount: '-2.50', description: 'Bar', counterparty: '' })
  })

  it('guesses the columns of an Italian bank with debit and credit', () => {
    const table = parse('Data operazione;Descrizione;Uscite;Entrate\n25/10/2026;POS ESSELUNGA;45,10;\n27/10/2026;Stipendio;;1.500,00\n', ';')
    const settings = guessSettings(table, 'utf-8', ';')

    expect(settings.amountMode).toBe('split')
    expect(toRows(table, settings)).toEqual([
      { line: 2, date: '2026-10-25', amount: '-45.10', description: 'POS ESSELUNGA', counterparty: '' },
      { line: 3, date: '2026-10-27', amount: '1500.00', description: 'Stipendio', counterparty: '' },
    ])
  })
})
