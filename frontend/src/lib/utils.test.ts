import { afterEach, describe, expect, it } from 'vitest'
import { formatMoney, formatQuantity, formatWholeMoney, fromDatetimeLocal, parseDate, setAmountsHidden, setNumberFormat, toDatetimeLocal } from './utils'

describe('parseDate', () => {
  it('reads a day as that day here, not as UTC midnight', () => {
    const day = parseDate('2026-10-01')
    expect([day.getFullYear(), day.getMonth(), day.getDate(), day.getHours()]).toEqual([2026, 9, 1, 0])
  })

  it('reads a transaction time as the clock time it shows', () => {
    const moment = parseDate('2026-10-01T00:30:00')
    expect([moment.getDate(), moment.getHours(), moment.getMinutes()]).toEqual([1, 0, 30])
  })

  it('reads a timestamp with an offset as an instant', () => {
    expect(parseDate('2026-10-01T00:30:00Z').getTime()).toBe(Date.UTC(2026, 9, 1, 0, 30))
  })
})

describe('datetime-local values', () => {
  it('fills the input with the time as written', () => {
    expect(toDatetimeLocal('2026-10-01T00:30:00')).toBe('2026-10-01T00:30')
  })

  it('sends the input back with seconds and no offset', () => {
    expect(fromDatetimeLocal('2026-10-01T00:30')).toBe('2026-10-01T00:30:00')
    expect(fromDatetimeLocal('2026-10-01T00:30:15')).toBe('2026-10-01T00:30:15')
  })
})

describe('number format', () => {
  afterEach(() => setNumberFormat('it-IT'))

  it('follows the preference', () => {
    expect(formatMoney('1234.5')).toBe('1.234,50\u00a0€')
    setNumberFormat('en-US')
    expect(formatMoney('1234.5')).toBe('1,234.50\u00a0€')
  })

  it('puts the sign before the amount', () => {
    expect(formatMoney(-42, null)).toBe('-42,00')
  })

  it('shows quantities with up to six decimals', () => {
    expect(formatQuantity('1.500000')).toBe('1,5')
    expect(formatQuantity('0.1234567')).toBe('0,123457')
  })
})

describe('hidden amounts', () => {
  afterEach(() => setAmountsHidden(false))

  it('shows dots instead of every amount, keeping the currency', () => {
    setAmountsHidden(true)
    expect(formatMoney(-1234.56, '€')).toBe('•••••\u00a0€')
    expect(formatMoney(10, null)).toBe('•••••')
    expect(formatWholeMoney(7550, '$')).toBe('•••••\u00a0$')
  })

  it('shows them again when turned off', () => {
    setAmountsHidden(false)
    expect(formatWholeMoney(7550, null)).not.toContain('•')
  })
})
