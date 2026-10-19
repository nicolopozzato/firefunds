import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { endOfMonth, format, parseISO } from 'date-fns'
import { cn, formatMoney } from '@/lib/utils'
import { dateLocale } from '@/lib/report-format'
import { cellColor, formatCell, useNetWorthGroups, type Kind, type NetWorthView } from './net-worth-lines'
import type { NetWorthReport } from '@/types/report'

interface NetWorthTableProps {
  report: NetWorthReport
  view: NetWorthView
  /** The year before shown in grey next to each month. */
  compare: boolean
}

/** A shadow on the name column's edge, so the months scrolling under it are seen to go on. */
const LEFT_EDGE = 'shadow-[6px_0_6px_-6px_hsl(var(--foreground)/0.25)]'

/**
 * The net worth spreadsheet: every asset at the end of each month of the
 * year (or how much it changed in the month), grouped into cash and
 * investments, the total and what its change came from, then each
 * instrument's closing price. The year before can sit in grey next to each
 * month. The asset's name stays in view while scrolling sideways.
 */
export default function NetWorthTable({ report, view, compare }: NetWorthTableProps) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = report.currencySymbol
  const groups = useNetWorthGroups(report, view)

  const monthName = (month: string) => format(parseISO(`${month}-01`), 'LLL', { locale })
  const columns = 1 + report.months.length * (compare ? 2 : 1)
  /** The current month: taken today, not at its end. */
  const isCurrentMonth = (index: number) => {
    const date = report.months[index].date
    return date !== null && date !== format(endOfMonth(parseISO(date)), 'yyyy-MM-dd')
  }

  const cell = (value: number | null, kind: Kind, muted: boolean, exactTitle: boolean) => (
    <span
      className={cn(value === null ? 'text-muted-foreground/40' : cellColor(value, kind), muted && 'opacity-60')}
      title={value !== null && exactTitle && (kind === 'money' || kind === 'signed') ? formatMoney(value, currency) : undefined}
    >
      {value === null ? '–' : formatCell(value, kind)}
    </span>
  )

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-separate border-spacing-0 text-sm">
        <thead>
          <tr className="text-xs text-muted-foreground">
            <th rowSpan={compare ? 2 : 1} className={cn('sticky left-0 z-20 min-w-48 max-w-64 border-b bg-card py-2 pl-4 pr-2 text-left font-medium', LEFT_EDGE)}>
              {t('reports.amountsIn', { currency: currency ?? '' })}
            </th>
            {report.months.map((month, index) => (
              <th
                key={month.month}
                colSpan={compare ? 2 : 1}
                className={cn('whitespace-nowrap px-2 py-2 font-medium', compare ? 'border-l pb-0 text-center' : 'border-b text-right')}
                title={isCurrentMonth(index) ? t('reports.netWorthReport.asOfToday') : undefined}
              >
                {monthName(month.month)}
                {isCurrentMonth(index) && '*'}
              </th>
            ))}
          </tr>
          {compare && (
            <tr className="text-[11px] text-muted-foreground">
              {report.months.map((month) => (
                <Fragment key={month.month}>
                  <th className="border-b border-l px-2 pb-1.5 text-right font-medium">{report.year}</th>
                  <th className="border-b px-2 pb-1.5 text-right font-normal opacity-60">{report.year - 1}</th>
                </Fragment>
              ))}
            </tr>
          )}
        </thead>
        <tbody>
          {groups.map((group) => (
            <Fragment key={group.key}>
              {group.title && (
                <tr>
                  <td colSpan={columns} className="sticky left-0 bg-card pb-1 pl-4 pt-4 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {group.title}
                  </td>
                </tr>
              )}
              {group.lines.map((line) => (
                <tr key={line.key} className={cn('group', line.total && 'font-semibold')}>
                  <td
                    className={cn(
                      'sticky left-0 z-10 max-w-64 truncate bg-card py-1.5 pr-2 group-hover:bg-muted',
                      LEFT_EDGE,
                      line.part ? 'pl-8 text-xs text-muted-foreground' : 'pl-4',
                      line.total && 'border-t'
                    )}
                  >
                    {line.label}
                  </td>
                  {line.data.values.map((value, index) => (
                    <Fragment key={index}>
                      <td
                        className={cn(
                          'whitespace-nowrap px-2 py-1.5 text-right tabular-nums group-hover:bg-muted/60',
                          line.part && 'text-xs',
                          line.total && 'border-t',
                          compare && 'border-l'
                        )}
                      >
                        {cell(value, line.kind, false, true)}
                      </td>
                      {compare && (
                        <td
                          className={cn(
                            'whitespace-nowrap px-2 py-1.5 text-right text-xs tabular-nums group-hover:bg-muted/60',
                            line.total && 'border-t'
                          )}
                        >
                          {cell(line.data.previousValues[index], line.kind, true, true)}
                        </td>
                      )}
                    </Fragment>
                  ))}
                </tr>
              ))}
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  )
}
