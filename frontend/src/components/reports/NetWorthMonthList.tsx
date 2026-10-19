import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format, parseISO } from 'date-fns'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { MobileListSection, MobileListCard } from '@/components/MobileList'
import { cn, formatMoney } from '@/lib/utils'
import { dateLocale, signedMoney } from '@/lib/report-format'
import { cellColor, formatCell, useNetWorthGroups, type Line, type NetWorthView } from './net-worth-lines'
import type { NetWorthReport } from '@/types/report'

interface NetWorthMonthListProps {
  report: NetWorthReport
  view: NetWorthView
  /** The same month of the year before under each value, in grey. */
  compare: boolean
}

/** A value with its full precision: money with cents and currency, prices and percentages as in the table. */
function formatValue(value: number, line: Line, currency: string | null): string {
  switch (line.kind) {
    case 'money':
      return formatMoney(value, currency)
    case 'signed':
      return signedMoney(value, currency)
    default:
      return formatCell(value, line.kind)
  }
}

/**
 * Phones: the net worth report one month at a time (the table's twelve
 * columns don't fit), the same lines as a list, the month chosen with the
 * arrows. Starts on the last month gone by.
 */
export default function NetWorthMonthList({ report, view, compare }: NetWorthMonthListProps) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const groups = useNetWorthGroups(report, view)
  const lastMonth = Math.max(0, report.months.filter((month) => month.date !== null).length - 1)
  const [chosen, setChosen] = useState<{ year: number; index: number } | null>(null)
  // Another year starts again on its last month gone by.
  const index = chosen?.year === report.year ? chosen.index : lastMonth
  const move = (direction: -1 | 1) => setChosen({ year: report.year, index: index + direction })

  const month = report.months[index]
  const title = format(parseISO(`${month.month}-01`), 'LLLL yyyy', { locale })

  const value = (line: Line, previous: boolean) => {
    const amount = (previous ? line.data.previousValues : line.data.values)[index]
    if (amount === null) return <span className="text-muted-foreground/50">–</span>
    return <span className={cellColor(amount, line.kind)}>{formatValue(amount, line, report.currencySymbol)}</span>
  }

  const row = (line: Line) => (
    <div key={line.key} className={cn('flex items-start gap-3 px-4 py-2.5', line.part && 'py-1.5 pl-8', line.total && 'font-semibold')}>
      <span className={cn('min-w-0 flex-1 truncate', line.part ? 'text-xs text-muted-foreground' : 'text-sm')}>{line.label}</span>
      <span className="shrink-0 text-right tabular-nums">
        <span className={cn('block', line.part ? 'text-xs' : 'text-sm')}>{value(line, false)}</span>
        {compare && (
          <span className="block text-xs font-normal opacity-60">
            {report.year - 1}: {value(line, true)}
          </span>
        )}
      </span>
    </div>
  )

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between rounded-2xl border bg-card p-1.5">
        <Button variant="ghost" size="icon" aria-label={t('timeframe.previousPeriod')} disabled={index <= 0} onClick={() => move(-1)}>
          <ChevronLeft className="h-5 w-5" />
        </Button>
        <div className="text-center">
          <div className="font-semibold capitalize">{title}</div>
          <div className="text-xs text-muted-foreground">
            {month.date === null
              ? t('reports.netWorthReport.toCome')
              : view === 'values'
                ? t('reports.netWorthReport.on', { date: format(parseISO(month.date), 'd MMMM', { locale }) })
                : t('reports.netWorthReport.tableChanges')}
          </div>
        </div>
        <Button variant="ghost" size="icon" aria-label={t('timeframe.nextPeriod')} disabled={index >= report.months.length - 1} onClick={() => move(1)}>
          <ChevronRight className="h-5 w-5" />
        </Button>
      </div>

      {groups.map((group) =>
        group.title ? (
          <MobileListSection key={group.key} title={group.title}>
            {group.lines.map(row)}
          </MobileListSection>
        ) : (
          <MobileListCard key={group.key}>{group.lines.map(row)}</MobileListCard>
        )
      )}
    </div>
  )
}
