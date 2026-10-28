import { describe, expect, it } from 'vitest'
import i18n from '@/i18n/config'
import { describeSchedule, monthDayOptions } from './recurring'

const schedule = {
  startDate: '2026-01-13',
  unit: 'month' as const,
  interval: 1,
  monthDay: 'day' as const,
  weekendShift: 'none' as const,
  months: null,
  endDate: null,
  count: null,
}

describe('monthDayOptions', () => {
  it('offers what the start date is: its day, its weekday as many weeks in', async () => {
    await i18n.changeLanguage('it')
    // Tuesday 13 January 2026: the second Tuesday.
    expect(monthDayOptions('2026-01-13', i18n.t).map((option) => option.label)).toEqual(['il giorno 13', 'il secondo martedì'])
    // Saturday 31 January: the last day, and the last Saturday (no fifth week offered).
    expect(monthDayOptions('2026-01-31', i18n.t).map((option) => option.value)).toEqual(['day', 'last_day', 'last_weekday'])
    // Sunday is feminine in Italian.
    expect(monthDayOptions('2026-01-11', i18n.t)[1].label).toBe('la seconda domenica')
  })
})

describe('describeSchedule', () => {
  it('says how often, which day, and the extras', async () => {
    await i18n.changeLanguage('it')
    expect(describeSchedule(schedule, i18n.t)).toBe('Ogni mese, il giorno 13')
    expect(describeSchedule({ ...schedule, interval: 2, monthDay: 'weekday', weekendShift: 'before', count: 12 }, i18n.t)).toBe(
      'Ogni 2 mesi, il secondo martedì · nel weekend il venerdì prima · 12 volte',
    )
    expect(describeSchedule({ ...schedule, unit: 'week' }, i18n.t)).toBe('Ogni settimana, il martedì')
    expect(describeSchedule({ ...schedule, months: [9, 10, 11, 12, 1, 2, 3, 4, 5, 6] }, i18n.t)).toBe('Ogni mese, il giorno 13 · solo da set a giu')
    expect(describeSchedule({ ...schedule, months: [1, 7] }, i18n.t)).toBe('Ogni mese, il giorno 13 · solo gen, lug')

    await i18n.changeLanguage('en')
    expect(describeSchedule({ ...schedule, unit: 'day', interval: 3 }, i18n.t)).toBe('Every 3 days')
  })
})
