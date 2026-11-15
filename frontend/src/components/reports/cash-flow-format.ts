import { format, parseISO } from 'date-fns'
import type { DateLocale } from '@/lib/report-format'
import type { CashFlowBy, CashFlowLayers, CashFlowPeriod } from '@/types/report'

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1)

/** Under the chart's axis: "17 nov", "17 nov" (a week's Monday), "nov 26". */
export function cashFlowAxisLabel(period: CashFlowPeriod, by: CashFlowBy, locale: DateLocale): string {
  return format(parseISO(period.start), by === 'month' ? 'LLL yy' : 'd MMM', { locale })
}

/**
 * A column's full name: "Lunedì 17 novembre", "17 – 23 novembre", "Novembre
 * 2026"; the first, starting today, says from which day ("15 – 30 novembre").
 */
export function cashFlowPeriodLabel(period: CashFlowPeriod, by: CashFlowBy, locale: DateLocale): string {
  const start = parseISO(period.start)
  const end = parseISO(period.end)
  if (by === 'day') return capitalize(format(start, 'EEEE d MMMM', { locale }))
  if (by === 'month' && period.start.endsWith('-01')) return capitalize(format(start, 'LLLL yyyy', { locale }))
  if (period.start === period.end) return format(start, 'd MMMM', { locale })
  const sameMonth = period.start.slice(0, 7) === period.end.slice(0, 7)
  return `${format(start, sameMonth ? 'd' : 'd MMM', { locale })} – ${format(end, 'd MMM', { locale })}`
}

export function layersTotal(layers: CashFlowLayers): number {
  return Number(layers.entered) + Number(layers.recurring) + Number(layers.estimated)
}
