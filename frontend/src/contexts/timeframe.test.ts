import { describe, expect, it } from 'vitest'
import { format } from 'date-fns'
import { presetForRange, rangeForPreset, shiftTimeframe } from './TimeframeContext'

const day = (date: Date) => format(date, 'yyyy-MM-dd')
const today = new Date(2026, 9, 20)

describe('timeframe', () => {
  it('computes the presets from today', () => {
    const last30 = rangeForPreset('last30Days', today)
    expect([day(last30.from), day(last30.to)]).toEqual(['2026-09-21', '2026-10-20'])
    const month = rangeForPreset('currentMonth', today)
    expect([day(month.from), day(month.to)]).toEqual(['2026-10-01', '2026-10-31'])
  })

  it('recognises a preset in a range picked by hand', () => {
    expect(presetForRange(new Date(2026, 8, 21), new Date(2026, 9, 20), today)).toBe('last30Days')
    expect(presetForRange(new Date(2026, 8, 22), new Date(2026, 9, 20), today)).toBe('custom')
  })

  it('moves a month to the previous one, and back to the current month', () => {
    const september = shiftTimeframe({ preset: 'currentMonth', ...rangeForPreset('currentMonth', today) }, -1, today)
    expect([september.preset, day(september.from), day(september.to)]).toEqual(['month', '2026-09-01', '2026-09-30'])
    expect(shiftTimeframe(september, 1, today).preset).toBe('currentMonth')
  })
})
