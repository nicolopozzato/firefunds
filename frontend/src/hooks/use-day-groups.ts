import { useTranslation } from 'react-i18next'
import { format, isToday, isYesterday } from 'date-fns'
import { formatDayHeading } from '@/lib/utils'

/** Items in the order given, grouped by calendar day (the device's timezone). */
export function groupByDay<T>(items: T[], dateOf: (item: T) => string): { day: string; date: Date; items: T[] }[] {
  const groups: { day: string; date: Date; items: T[] }[] = []
  for (const item of items) {
    const date = new Date(dateOf(item))
    const day = format(date, 'yyyy-MM-dd')
    const last = groups[groups.length - 1]
    if (last && last.day === day) last.items.push(item)
    else groups.push({ day, date, items: [item] })
  }
  return groups
}

/** A day heading: "Today", "Yesterday", else "saturday 3 october". */
export function useDayLabel(): (date: Date) => string {
  const { t } = useTranslation()
  return (date) => {
    if (isToday(date)) return t('transactionsCommon.today')
    if (isYesterday(date)) return t('transactionsCommon.yesterday')
    return formatDayHeading(date)
  }
}
