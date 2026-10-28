import type { TFunction } from 'i18next'
import { dateLocale, formatShortDate, parseDate } from '@/lib/utils'
import type { RecurrenceMonthDay, RecurringKind, RecurringTransaction } from '@/types/recurring'

type Schedule = Pick<RecurringTransaction, 'startDate' | 'unit' | 'interval' | 'monthDay' | 'weekendShift' | 'months' | 'endDate' | 'count'>

const weekdayName = (date: Date) => new Intl.DateTimeFormat(dateLocale(), { weekday: 'long' }).format(date)
const monthName = (month: number, style: 'long' | 'short') =>
  new Intl.DateTimeFormat(dateLocale(), { month: style }).format(new Date(2026, month - 1, 1))

/** Which week of its month a day is in: 1 for days 1-7, … 5 for the 29th on. */
const weekOfMonth = (date: Date) => Math.floor((date.getDate() - 1) / 7) + 1
const isLastWeek = (date: Date) => date.getDate() + 7 > new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()

/**
 * The days of the month a start date offers, as calendars do: its day ("the
 * 8th"), the last day when it is one, its weekday as many weeks in ("the
 * second Tuesday", up to the fourth) and "the last Tuesday" in the last week.
 */
export function monthDayOptions(startDate: string, t: TFunction): { value: RecurrenceMonthDay; label: string }[] {
  const date = parseDate(startDate)
  const weekday = weekdayName(date)
  // Italian: "la seconda domenica", the other days are masculine.
  const context = date.getDay() === 0 ? 'female' : undefined
  const lastDayOfMonth = date.getDate() === new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()
  const options: { value: RecurrenceMonthDay; label: string }[] = [{ value: 'day', label: t('recurring.onDay', { day: date.getDate() }) }]
  if (lastDayOfMonth || date.getDate() >= 28) options.push({ value: 'last_day', label: t('recurring.onLastDay') })
  if (weekOfMonth(date) <= 4) options.push({ value: 'weekday', label: t('recurring.onWeekday', { nth: t(`recurring.nth_${weekOfMonth(date)}`, { context }), weekday, context }) })
  if (isLastWeek(date)) options.push({ value: 'last_weekday', label: t('recurring.onLastWeekday', { weekday, context }) })
  return options
}

/**
 * The months as said: one run in a row (even across the new year) is "Sep–Jun",
 * anything else the names one by one.
 */
function monthList(months: number[], t: TFunction): string {
  const sorted = [...months].sort((a, b) => a - b)
  // The run starts at the month whose previous one is left out.
  const first = sorted.find((month) => !sorted.includes(month === 1 ? 12 : month - 1))
  if (first !== undefined && sorted.length > 2) {
    const run = Array.from({ length: sorted.length }, (_, i) => ((first - 1 + i) % 12) + 1)
    if (run.every((month) => sorted.includes(month))) {
      return t('recurring.monthRange', { from: monthName(run[0], 'short'), to: monthName(run[run.length - 1], 'short') })
    }
  }
  return sorted.map((month) => monthName(month, 'short')).join(', ')
}

/** "Every month, on the 8th", "Every 2 weeks, on Monday", with the months, the weekend rule and the end. */
export function describeSchedule(schedule: Schedule, t: TFunction): string {
  const start = parseDate(schedule.startDate)
  const every = t(`recurring.every_${schedule.unit}`, { count: schedule.interval })
  let on = ''
  if (schedule.unit === 'week') {
    on = t('recurring.onWeekdayPlain', { weekday: weekdayName(start), context: start.getDay() === 0 ? 'female' : undefined })
  } else if (schedule.unit === 'month' || schedule.unit === 'year') {
    const day = monthDayOptions(schedule.startDate, t).find((option) => option.value === schedule.monthDay)?.label ?? ''
    on = schedule.unit === 'year' ? t('recurring.inMonth', { day, month: monthName(start.getMonth() + 1, 'long') }) : day
  }

  const parts = [on ? `${every}, ${on}` : every]
  if (schedule.months && schedule.months.length < 12) {
    parts.push(t('recurring.onlyMonths', { months: monthList(schedule.months, t) }))
  }
  if (schedule.weekendShift !== 'none') parts.push(t(`recurring.weekend_${schedule.weekendShift}`))
  if (schedule.endDate) parts.push(t('recurring.until', { date: formatShortDate(schedule.endDate) }))
  else if (schedule.count) parts.push(t('recurring.times', { count: schedule.count }))
  return parts.join(' · ')
}

/** Out is negative, in is positive, a transfer only moves: the colour and sign of its amount. */
export function recurringAmountClass(kind: RecurringKind): string {
  return kind === 'withdrawal' ? 'text-negative' : kind === 'deposit' ? 'text-positive' : 'text-info'
}

export function signedRecurringAmount(kind: RecurringKind, amount: string | number): number {
  return kind === 'withdrawal' ? -Number(amount) : Number(amount)
}
