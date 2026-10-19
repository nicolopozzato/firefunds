import {
  addDays,
  addMonths,
  differenceInCalendarDays,
  differenceInCalendarMonths,
  endOfMonth,
  endOfYear,
  format,
  isSameDay,
  isValid,
  parseISO,
  startOfMonth,
  startOfYear,
  subMonths,
  subYears,
} from 'date-fns'
import type { ReportComparison, ReportGranularity } from '@/types/report'

/**
 * A report's own range, apart from the app's period: the presets follow
 * today (recomputed on every load, so a saved link keeps meaning "this
 * year"); `month` and `year` are a given calendar month or year reached with
 * the arrows; `custom` is any range; `all` runs from the first transaction's
 * month to the end of this month.
 */
export type ReportPreset =
  | 'currentMonth'
  | 'previousMonth'
  | 'last12Months'
  | 'yearToDate'
  | 'currentYear'
  | 'previousYear'
  | 'all'
  | 'month'
  | 'year'
  | 'custom'

export interface ReportSpan {
  preset: ReportPreset
  from: Date
  to: Date
}

/** The menu, in groups divided by a line. */
export const REPORT_PRESET_GROUPS: ReportPreset[][] = [
  ['currentMonth', 'previousMonth'],
  ['last12Months', 'yearToDate'],
  ['currentYear', 'previousYear'],
  ['all', 'custom'],
]

const ROLLING_PRESETS = ['currentMonth', 'previousMonth', 'last12Months', 'yearToDate', 'currentYear', 'previousYear'] as const
type RollingPreset = (typeof ROLLING_PRESETS)[number]

export function isRollingPreset(preset: string): preset is RollingPreset {
  return (ROLLING_PRESETS as readonly string[]).includes(preset)
}

export function rangeForReportPreset(preset: RollingPreset, today: Date = new Date()): { from: Date; to: Date } {
  switch (preset) {
    case 'currentMonth':
      return { from: startOfMonth(today), to: endOfMonth(today) }
    case 'previousMonth': {
      const month = subMonths(today, 1)
      return { from: startOfMonth(month), to: endOfMonth(month) }
    }
    case 'last12Months':
      return { from: startOfMonth(subMonths(today, 11)), to: endOfMonth(today) }
    case 'yearToDate':
      return { from: startOfYear(today), to: today }
    case 'currentYear':
      return { from: startOfYear(today), to: endOfYear(today) }
    case 'previousYear': {
      const year = subYears(today, 1)
      return { from: startOfYear(year), to: endOfYear(year) }
    }
  }
}

function isWholeMonths(from: Date, to: Date): boolean {
  return from.getDate() === 1 && isSameDay(to, endOfMonth(to))
}

/** How many calendar months the range touches. */
export function monthCount(from: Date, to: Date): number {
  return differenceInCalendarMonths(to, from) + 1
}

/** The preset these days are: one that follows today, a calendar month or year, or else custom. */
export function presetForReportRange(from: Date, to: Date, today: Date = new Date()): ReportPreset {
  const rolling = ROLLING_PRESETS.find((preset) => {
    const range = rangeForReportPreset(preset, today)
    return isSameDay(range.from, from) && isSameDay(range.to, to)
  })
  if (rolling) return rolling
  if (isWholeMonths(from, to) && monthCount(from, to) === 1) return 'month'
  if (isSameDay(from, startOfYear(from)) && isSameDay(to, endOfYear(from))) return 'year'
  return 'custom'
}

/**
 * The range before (-1) or after (+1): as many whole months when the range
 * is whole months (a year moves by a year, the last 12 months by 12), else
 * as many days.
 */
export function shiftReportSpan(span: ReportSpan, direction: -1 | 1, today: Date = new Date()): ReportSpan {
  let from: Date
  let to: Date
  if (isWholeMonths(span.from, span.to)) {
    const months = monthCount(span.from, span.to) * direction
    from = startOfMonth(addMonths(span.from, months))
    to = endOfMonth(addMonths(startOfMonth(span.to), months))
  } else {
    const days = (differenceInCalendarDays(span.to, span.from) + 1) * direction
    from = addDays(span.from, days)
    to = addDays(span.to, days)
  }
  return { preset: presetForReportRange(from, to, today), from, to }
}

/** A column per month up to two years, per year beyond. */
export function defaultGranularity(from: Date, to: Date): ReportGranularity {
  return monthCount(from, to) <= 24 ? 'month' : 'year'
}

export function toIsoDay(date: Date): string {
  return format(date, 'yyyy-MM-dd')
}

function parseDay(value: string | null): Date | null {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null
  const date = parseISO(value)
  return isValid(date) ? date : null
}

/** The income and expenses report's options, as kept in the URL. */
export interface IncomeExpenseOptions {
  preset: ReportPreset
  /** null for a preset that follows today (computed from it) or for `all` (to be resolved). */
  from: Date | null
  to: Date | null
  by: ReportGranularity | null
  compare: ReportComparison
}

export function readIncomeExpenseOptions(params: URLSearchParams): IncomeExpenseOptions | null {
  const preset = params.get('preset') as ReportPreset | null
  if (!preset) return null

  const by = params.get('by')
  const compare = params.get('compare')
  const options = {
    by: by === 'month' || by === 'year' ? by : null,
    compare: compare === 'previous' || compare === 'lastYear' ? compare : 'none',
  } as const

  if (isRollingPreset(preset)) {
    return { preset, ...rangeForReportPreset(preset), ...options }
  }
  if (preset === 'all') {
    return { preset, from: null, to: null, ...options }
  }

  const from = parseDay(params.get('from'))
  const to = parseDay(params.get('to'))
  if (!from || !to || from > to) return null
  return { preset: presetForReportRange(from, to), from, to, ...options }
}

export function writeIncomeExpenseOptions(options: IncomeExpenseOptions): URLSearchParams {
  const params = new URLSearchParams({ preset: options.preset })
  if (!isRollingPreset(options.preset) && options.preset !== 'all' && options.from && options.to) {
    params.set('from', toIsoDay(options.from))
    params.set('to', toIsoDay(options.to))
  }
  if (options.by) params.set('by', options.by)
  if (options.compare !== 'none') params.set('compare', options.compare)
  return params
}
