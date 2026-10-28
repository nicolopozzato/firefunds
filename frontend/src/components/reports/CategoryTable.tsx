import { useTranslation } from 'react-i18next'
import { cn, formatMoney } from '@/lib/utils'
import { changePercent, dateLocale, periodLabel, formatReportRange } from '@/lib/report-format'
import { parseISO } from 'date-fns'
import { ChangePercent } from './report-ui'
import type { CategoryRow, CategoryTable as CategoryTableData, IncomeExpenseComparison, ReportGranularity, ReportPeriod } from '@/types/report'

/** A report cell opened to see its transactions. */
export interface CategoryCell {
  flow: 'expense' | 'income'
  /** null = no category */
  categoryId: string | null
  categoryName: string
  from: string
  to: string
  /** The column's name, or the whole range's. */
  label: string
  total: string
}

interface CategoryTableProps {
  title: string
  flow: 'expense' | 'income'
  table: CategoryTableData
  periods: ReportPeriod[]
  by: ReportGranularity
  comparison: IncomeExpenseComparison | null
  /** The report's whole range, for the total column. */
  from: string
  to: string
  currency: string | null
  onOpen: (cell: CategoryCell) => void
}

/** Widths of the columns after the periods, kept in view on the right. */
const TOTAL_WIDTH = 104
const AVERAGE_WIDTH = 92
const COMPARISON_WIDTH = 100
const CHANGE_WIDTH = 64
/** A shadow on the edge of a column kept in view, so the columns scrolling under it are seen to go on. */
const LEFT_EDGE = 'shadow-[6px_0_6px_-6px_hsl(var(--foreground)/0.25)]'
const RIGHT_EDGE = 'shadow-[-6px_0_6px_-6px_hsl(var(--foreground)/0.25)]'

/** Strength of a cell's shading, 0 to 1, against the table's largest cell. */
function shade(value: number, max: number): number {
  if (max <= 0 || value <= 0) return 0
  return 0.05 + 0.3 * (value / max)
}

/**
 * Categories as rows, the report's columns as columns, as on Firefly III:
 * then the total, the monthly average and, when comparing, the other range's
 * total and the change. The category stays in view on the left and the
 * totals on the right however many columns there are; the larger a cell,
 * the stronger its shading. A cell opens its transactions.
 */
export default function CategoryTable({ title, flow, table, periods, by, comparison, from, to, currency, onOpen }: CategoryTableProps) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const hasComparison = comparison !== null && table.comparisonTotal !== null
  const color = flow === 'expense' ? '--negative' : '--positive'
  const max = Math.max(0, ...table.rows.flatMap((row) => row.values.map(Number)))
  const sameColumns = comparison !== null && comparison.periods.length === periods.length

  // Offsets of the right-hand sticky columns, from the right edge.
  const changeRight = 0
  const comparisonRight = hasComparison ? CHANGE_WIDTH : 0
  const averageRight = hasComparison ? CHANGE_WIDTH + COMPARISON_WIDTH : 0
  const totalRight = averageRight + AVERAGE_WIDTH

  const rowName = (row: CategoryRow) => row.name ?? t('reports.noCategory')
  const amount = (value: string) => formatMoney(value, null)
  const wholeRange = formatReportRange(parseISO(from), parseISO(to), locale)

  const open = (row: CategoryRow, value: string, period: ReportPeriod | null) => {
    if (Number(value) === 0) return
    onOpen({
      flow,
      categoryId: row.id,
      categoryName: rowName(row),
      from: period?.from ?? from,
      to: period?.to ?? to,
      label: period ? periodLabel(period.key, by, locale, true) : wholeRange,
      total: value,
    })
  }

  /** A column kept in view on the right: its offset from the right edge, its width, its own classes. */
  const stickyRight = (right: number, width: number, className: string, first = false) => ({
    className: cn('sticky z-10 bg-card', first && cn('border-l', RIGHT_EDGE), className),
    style: { right, minWidth: width, width },
  })

  if (table.rows.length === 0) {
    return (
      <section className="surface p-4 sm:p-5">
        <h3 className="mb-3 section-title">{title}</h3>
        <p className="py-8 text-center text-sm text-muted-foreground">{t(flow === 'expense' ? 'reports.noExpenses' : 'reports.noIncome')}</p>
      </section>
    )
  }

  return (
    <section className="min-w-0 surface">
      <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4">
        <h3 className="section-title">{title}</h3>
        <span className="text-xs text-muted-foreground">
          {t('reports.amountsIn', { currency: currency ?? '' })} · {t('reports.cellHint')}
        </span>
      </div>
      <div className="mt-3 overflow-x-auto">
        <table className="w-full border-separate border-spacing-0 text-sm">
          <thead>
            <tr className="text-xs text-muted-foreground">
              <th className={cn('sticky left-0 z-20 min-w-44 max-w-60 border-b bg-card px-4 py-2 text-left font-medium', LEFT_EDGE)}>{t('reports.category')}</th>
              {periods.map((period) => (
                <th
                  key={period.key}
                  className="min-w-20 whitespace-nowrap border-b px-2 py-2 text-right font-medium"
                  title={period.partial ? t('reports.partialColumn', { range: formatReportRange(parseISO(period.from), parseISO(period.to), locale) }) : undefined}
                >
                  {periodLabel(period.key, by, locale)}
                  {period.partial && '*'}
                </th>
              ))}
              <th {...stickyRight(totalRight, TOTAL_WIDTH, 'border-b px-2 py-2 text-right font-medium', true)}>
                {t('reports.total')}
              </th>
              <th {...stickyRight(averageRight, AVERAGE_WIDTH, cn('border-b px-2 py-2 text-right font-medium', !hasComparison && 'pr-4'))}>
                {t('reports.monthlyAverage')}
              </th>
              {hasComparison && (
                <>
                  <th {...stickyRight(comparisonRight, COMPARISON_WIDTH, 'border-b px-2 py-2 text-right font-medium')}>
                    {t('reports.comparisonTotal')}
                  </th>
                  <th {...stickyRight(changeRight, CHANGE_WIDTH, 'border-b py-2 pl-2 pr-4 text-right font-medium')}>
                    Δ
                  </th>
                </>
              )}
            </tr>
          </thead>
          <tbody>
            {table.rows.map((row) => (
              <tr key={row.id ?? 'none'} className="group">
                <td
                  className={cn(
                    'sticky left-0 z-10 max-w-60 truncate border-b bg-card px-4 py-1.5 group-hover:bg-muted',
                    LEFT_EDGE,
                    row.id === null && 'italic text-muted-foreground'
                  )}
                  title={rowName(row)}
                >
                  {rowName(row)}
                </td>
                {row.values.map((value, index) => {
                  const number = Number(value)
                  const previous = sameColumns ? row.comparisonValues?.[index] : undefined
                  return (
                    <td
                      key={periods[index].key}
                      onClick={() => open(row, value, periods[index])}
                      title={
                        previous !== undefined && previous !== null && comparison
                          ? `${periodLabel(comparison.periods[index].key, by, locale, true)}: ${formatMoney(previous, currency)}`
                          : undefined
                      }
                      className={cn(
                        'whitespace-nowrap border-b px-2 py-1.5 text-right tabular-nums group-hover:bg-muted/60',
                        number === 0 ? 'text-muted-foreground/50' : 'cursor-pointer hover:underline'
                      )}
                      style={number > 0 ? { backgroundColor: `hsl(var(${color}) / ${shade(number, max)})` } : undefined}
                    >
                      {number === 0 ? '–' : amount(value)}
                    </td>
                  )
                })}
                <td
                  {...stickyRight(totalRight, TOTAL_WIDTH, 'cursor-pointer whitespace-nowrap border-b px-2 py-1.5 text-right font-semibold tabular-nums hover:underline group-hover:bg-muted', true)}
                  onClick={() => open(row, row.total, null)}
                >
                  {amount(row.total)}
                </td>
                <td
                  {...stickyRight(averageRight, AVERAGE_WIDTH, cn('whitespace-nowrap border-b px-2 py-1.5 text-right tabular-nums text-muted-foreground group-hover:bg-muted', !hasComparison && 'pr-4'))}
                >
                  {amount(row.average)}
                </td>
                {hasComparison && (
                  <>
                    <td
                      {...stickyRight(comparisonRight, COMPARISON_WIDTH, 'whitespace-nowrap border-b px-2 py-1.5 text-right tabular-nums text-muted-foreground group-hover:bg-muted')}
                    >
                      {Number(row.comparisonTotal) === 0 ? '–' : amount(row.comparisonTotal ?? '0')}
                    </td>
                    <td
                      {...stickyRight(changeRight, CHANGE_WIDTH, 'whitespace-nowrap border-b py-1.5 pl-2 pr-4 text-right text-xs group-hover:bg-muted')}
                    >
                      <ChangePercent value={changePercent(row.total, row.comparisonTotal)} higherIsBetter={flow === 'income'} />
                    </td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="font-semibold">
              <td className={cn('sticky left-0 z-10 bg-card px-4 py-2', LEFT_EDGE)}>{t('reports.total')}</td>
              {table.totals.map((value, index) => (
                <td key={periods[index].key} className="whitespace-nowrap px-2 py-2 text-right tabular-nums">
                  {amount(value)}
                </td>
              ))}
              <td {...stickyRight(totalRight, TOTAL_WIDTH, 'whitespace-nowrap px-2 py-2 text-right tabular-nums', true)}>
                {amount(table.total)}
              </td>
              <td
                {...stickyRight(averageRight, AVERAGE_WIDTH, cn('whitespace-nowrap px-2 py-2 text-right tabular-nums text-muted-foreground', !hasComparison && 'pr-4'))}
              >
                {amount(table.average)}
              </td>
              {hasComparison && (
                <>
                  <td
                    {...stickyRight(comparisonRight, COMPARISON_WIDTH, 'whitespace-nowrap px-2 py-2 text-right tabular-nums text-muted-foreground')}
                  >
                    {amount(table.comparisonTotal ?? '0')}
                  </td>
                  <td {...stickyRight(changeRight, CHANGE_WIDTH, 'whitespace-nowrap py-2 pl-2 pr-4 text-right text-xs')}>
                    <ChangePercent value={changePercent(table.total, table.comparisonTotal)} higherIsBetter={flow === 'income'} />
                  </td>
                </>
              )}
            </tr>
          </tfoot>
        </table>
      </div>
      {periods.some((period) => period.partial) && <p className="px-4 pb-3 pt-2 text-xs text-muted-foreground">{t('reports.partialNote')}</p>}
      {!periods.some((period) => period.partial) && <div className="pb-1" />}
    </section>
  )
}
