import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { parseISO } from 'date-fns'
import { MobileListCard, MobileListRow } from '@/components/MobileList'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { cn, formatMoney } from '@/lib/utils'
import { changePercent, dateLocale, formatReportRange, periodLabel } from '@/lib/report-format'
import { ChangePercent } from './report-ui'
import type { CategoryCell } from './CategoryTable'
import type { CategoryRow, CategoryTable, IncomeExpenseComparison, ReportGranularity, ReportPeriod } from '@/types/report'

interface CategoryMobileListProps {
  title: string
  flow: 'expense' | 'income'
  table: CategoryTable
  periods: ReportPeriod[]
  by: ReportGranularity
  comparison: IncomeExpenseComparison | null
  from: string
  to: string
  currency: string | null
  onOpen: (cell: CategoryCell) => void
}

/**
 * Phones: the category table as a list (its columns don't fit): each
 * category with its total, monthly average and change, and a bar against
 * the largest. A category opens its columns one under the other in a panel
 * from the bottom; a column opens its transactions.
 */
export default function CategoryMobileList({ title, flow, table, periods, by, comparison, from, to, currency, onOpen }: CategoryMobileListProps) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const [open, setOpen] = useState<CategoryRow | null>(null)
  const color = flow === 'expense' ? 'hsl(var(--negative))' : 'hsl(var(--positive))'
  const largest = Math.max(0, ...table.rows.map((row) => Number(row.total)))
  const sameColumns = comparison !== null && comparison.periods.length === periods.length
  const name = (row: CategoryRow) => row.name ?? t('reports.noCategory')
  const wholeRange = formatReportRange(parseISO(from), parseISO(to), locale)

  const openCell = (row: CategoryRow, value: string, period: ReportPeriod | null) => {
    if (Number(value) === 0) return
    setOpen(null)
    onOpen({
      flow,
      categoryId: row.id,
      categoryName: name(row),
      from: period?.from ?? from,
      to: period?.to ?? to,
      label: period ? periodLabel(period.key, by, locale, true) : wholeRange,
      total: value,
    })
  }

  return (
    <section className="space-y-1.5">
      <div className="flex items-baseline justify-between gap-2 px-1">
        <h3 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{title}</h3>
        <span className="text-xs font-semibold tabular-nums">{formatMoney(table.total, currency)}</span>
      </div>
      {table.rows.length === 0 ? (
        <MobileListCard>
          <p className="py-6 text-center text-sm text-muted-foreground">{t(flow === 'expense' ? 'reports.noExpenses' : 'reports.noIncome')}</p>
        </MobileListCard>
      ) : (
        <MobileListCard>
          {table.rows.map((row) => (
            <MobileListRow
              key={row.id ?? 'none'}
              onClick={() => setOpen(row)}
              title={<span className={cn(row.id === null && 'italic text-muted-foreground')}>{name(row)}</span>}
              subtitle={t('reports.perMonth', { amount: formatMoney(row.average, currency) })}
              extra={
                <span className="mt-1.5 block h-1 w-full overflow-hidden rounded-full bg-muted">
                  <span className="block h-full rounded-full" style={{ width: `${largest > 0 ? (Number(row.total) / largest) * 100 : 0}%`, backgroundColor: color }} />
                </span>
              }
              value={formatMoney(row.total, currency)}
              valueDetail={
                row.comparisonTotal !== null ? <ChangePercent value={changePercent(row.total, row.comparisonTotal)} higherIsBetter={flow === 'income'} /> : undefined
              }
            />
          ))}
        </MobileListCard>
      )}

      <Drawer open={open !== null} onOpenChange={(isOpen) => !isOpen && setOpen(null)}>
        <DrawerContent className="max-h-[85dvh]">
          {open && (
            <>
              <DrawerHeader>
                <DrawerTitle>{name(open)}</DrawerTitle>
                <DrawerDescription>
                  {wholeRange}: {formatMoney(open.total, currency)}
                  {open.comparisonTotal !== null && (
                    <>
                      {' · '}
                      {t('reports.versus', { amount: formatMoney(open.comparisonTotal, currency) })}
                    </>
                  )}
                </DrawerDescription>
              </DrawerHeader>
              <div className="overflow-y-auto px-4 pb-6">
                <MobileListCard>
                  {periods.map((period, index) => {
                    const value = open.values[index]
                    const previous = sameColumns ? open.comparisonValues?.[index] : null
                    return (
                      <MobileListRow
                        key={period.key}
                        onClick={() => openCell(open, value, period)}
                        title={`${periodLabel(period.key, by, locale, true)}${period.partial ? '*' : ''}`}
                        subtitle={
                          previous !== null && previous !== undefined && comparison
                            ? `${periodLabel(comparison.periods[index].key, by, locale, true)}: ${formatMoney(previous, currency)}`
                            : undefined
                        }
                        value={Number(value) === 0 ? '–' : formatMoney(value, currency)}
                        valueClassName={Number(value) === 0 ? 'font-normal text-muted-foreground' : undefined}
                        chevron={Number(value) !== 0}
                      />
                    )
                  })}
                </MobileListCard>
                {Number(open.total) !== 0 && (
                  <button
                    type="button"
                    onClick={() => openCell(open, open.total, null)}
                    className="mt-3 w-full rounded-xl border py-2.5 text-sm font-medium text-primary active:bg-accent"
                  >
                    {t('reports.allTransactions')}
                  </button>
                )}
              </div>
            </>
          )}
        </DrawerContent>
      </Drawer>
    </section>
  )
}
