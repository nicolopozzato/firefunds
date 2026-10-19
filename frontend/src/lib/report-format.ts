import { format, isSameYear, parseISO } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { formatMoney } from '@/lib/utils'
import type { ReportGranularity } from '@/types/report'

export type DateLocale = typeof it

export function dateLocale(language: string): DateLocale {
  return language === 'it' ? it : enUS
}

/** A column's name: "mar 26" / "marzo 2026" for a month, "2026" for a year. */
export function periodLabel(key: string, by: ReportGranularity, locale: DateLocale, long = false): string {
  if (by === 'year') return key
  const label = format(parseISO(`${key}-01`), long ? 'LLLL yyyy' : 'LLL yy', { locale })
  return long ? label.charAt(0).toUpperCase() + label.slice(1) : label
}

export function signedMoney(amount: string | number, currency: string | null): string {
  return `${Number(amount) > 0 ? '+' : ''}${formatMoney(amount, currency)}`
}

/** current against previous, in percent; null when there is nothing to compare with. */
export function changePercent(current: string | number, previous: string | number | null | undefined): number | null {
  if (previous === null || previous === undefined) return null
  const base = Number(previous)
  if (base === 0) return null
  return ((Number(current) - base) / Math.abs(base)) * 100
}

/** "1 – 15 ott 2026", or "15 dic 2025 – 15 gen 2026" across two years. */
export function formatReportRange(from: Date, to: Date, locale: DateLocale): string {
  const start = format(from, isSameYear(from, to) ? 'd MMM' : 'd MMM yyyy', { locale })
  return `${start} – ${format(to, 'd MMM yyyy', { locale })}`
}

