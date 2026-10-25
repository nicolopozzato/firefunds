import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { formatPercent, formatWholeMoney, numberFormatter } from '@/lib/utils'
import { monthlyChanges, monthlyValues, remainder, sumRows, withOpening, type MonthlyValues } from '@/lib/net-worth'
import type { NetWorthReport, NetWorthRow } from '@/types/report'

export type NetWorthView = 'values' | 'changes'

export type Kind = 'money' | 'signed' | 'price' | 'rate' | 'percent'

export interface Line {
  key: string
  label: ReactNode
  data: MonthlyValues
  kind: Kind
  /** Bold, with a line above: a total. */
  total?: boolean
  /** Indented and muted: part of the line above. */
  part?: boolean
}

export interface LineGroup {
  key: string
  /** null: the total, right under the groups above. */
  title: string | null
  lines: Line[]
}

const whole = (value: number) => formatWholeMoney(value, null)

export function formatCell(value: number, kind: Kind): string {
  switch (kind) {
    case 'money':
      return whole(value)
    case 'signed':
      return `${value > 0 ? '+' : ''}${whole(value)}`
    case 'price':
      return numberFormatter({ minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
    case 'rate':
      return numberFormatter({ minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(value)
    case 'percent':
      return `${value > 0 ? '+' : ''}${formatPercent(value, 1)}`
  }
}

export function cellColor(value: number, kind: Kind): string {
  if (kind !== 'signed' && kind !== 'percent') return ''
  if (value > 0) return 'text-positive'
  if (value < 0) return 'text-negative'
  return 'text-muted-foreground'
}

/**
 * The lines of the net worth report, shared by the table (larger screens)
 * and the month-by-month list (phones): each asset grouped into cash and
 * investments with their totals, the net worth and what its change came
 * from, then each instrument's closing price. In the changes view every
 * value line gives its change from the month before.
 */
export function useNetWorthGroups(report: NetWorthReport, view: NetWorthView): LineGroup[] {
  const { t } = useTranslation()
  const changes = view === 'changes'

  // In the changes view every value line shows its change from the month before.
  const shown = (row: MonthlyValues & { previousOpening: number | null }): MonthlyValues => (changes ? monthlyChanges(row) : row)
  const valueKind: Kind = changes ? 'signed' : 'money'
  const rowLine = (row: NetWorthRow, prefix: string): Line => ({
    key: `${prefix}-${row.id}`,
    // An instrument goes by its ticker (VWCE), the full name on hover; an account, or an instrument without one, by its name.
    label: (
      <span title={row.name ?? undefined}>
        {row.ticker ?? row.name}
        {/* A price is in its own currency and a cash row in its account's: said, when it isn't the user's. */}
        {row.currencyCode && <span className="ml-1 text-xs font-normal text-muted-foreground">{row.currencyCode}</span>}
      </span>
    ),
    data: shown(withOpening(row)),
    kind: valueKind,
  })

  const total = withOpening(report.total)
  const totalChange = monthlyChanges(total)
  const saved = monthlyValues(report.saved)
  const market = monthlyValues(report.market)
  const exchange = report.exchange ? monthlyValues(report.exchange) : null
  const hasExchange = exchange !== null && [...exchange.values, ...exchange.previousValues].some((value) => value !== null && Math.abs(value) >= 0.005)
  const other = remainder(totalChange, saved, market, ...(exchange ? [exchange] : []))
  const hasOther = [...other.values, ...other.previousValues].some((value) => value !== null && Math.abs(value) >= 0.005)

  const groups: LineGroup[] = [
    {
      key: 'cash',
      title: t('reports.netWorthReport.cash'),
      lines: [
        ...report.cash.map((row) => rowLine(row, 'cash')),
        { key: 'cash-total', label: t('reports.netWorthReport.cashTotal'), data: shown(sumRows(report.cash)), kind: valueKind, total: true },
      ],
    },
  ]
  if (report.investments.length > 0) {
    groups.push({
      key: 'investments',
      title: t('reports.netWorthReport.investments'),
      lines: [
        ...report.investments.map((row) => rowLine(row, 'investment')),
        { key: 'investments-total', label: t('reports.netWorthReport.investmentsTotal'), data: shown(sumRows(report.investments)), kind: valueKind, total: true },
      ],
    })
  }
  groups.push({
    key: 'total',
    title: null,
    lines: [
      ...(changes ? [] : [{ key: 'net-worth', label: t('reports.netWorth'), data: monthlyValues(report.total), kind: 'money' as const, total: true }]),
      { key: 'change', label: t('reports.netWorthReport.change'), data: totalChange, kind: 'signed', total: changes },
      { key: 'change-percent', label: t('reports.netWorthReport.changePercent'), data: monthlyChanges(total, true), kind: 'percent', part: true },
      { key: 'saved', label: t('reports.netWorthReport.fromSaving'), data: saved, kind: 'signed', part: true },
      { key: 'market', label: t('reports.netWorthReport.fromMarket'), data: market, kind: 'signed', part: true },
      ...(hasExchange && exchange ? [{ key: 'exchange', label: t('reports.netWorthReport.fromExchange'), data: exchange, kind: 'signed' as const, part: true }] : []),
      ...(hasOther ? [{ key: 'other', label: t('reports.netWorthReport.fromOther'), data: other, kind: 'signed' as const, part: true }] : []),
    ],
  })
  if (report.prices.length > 0) {
    groups.push({
      key: 'prices',
      title: changes ? t('reports.netWorthReport.priceChanges') : t('reports.netWorthReport.prices'),
      lines: report.prices.map((row) => ({
        ...rowLine(row, 'price'),
        data: changes ? monthlyChanges(withOpening(row), true) : monthlyValues(row),
        kind: changes ? 'percent' : 'price',
      })),
    })
  }

  const byCurrency = report.currencies ?? []
  if (byCurrency.length > 0) {
    // Between the total and the prices: what the net worth is made of, currency by currency.
    const totalIndex = groups.findIndex((group) => group.key === 'total')
    groups.splice(totalIndex + 1, 0, {
      key: 'currencies',
      title: t('reports.netWorthReport.byCurrency'),
      lines: byCurrency.map((row) => ({
        key: `currency-${row.id}`,
        label: <span>{row.name}</span>,
        data: shown(withOpening(row)),
        kind: valueKind,
      })),
    })
  }

  const rates = report.rates ?? []
  if (rates.length > 0) {
    groups.push({
      key: 'rates',
      title: changes ? t('reports.netWorthReport.rateChanges') : t('reports.netWorthReport.rates'),
      lines: rates.map((row) => ({
        ...rowLine(row, 'rate'),
        data: changes ? monthlyChanges(withOpening(row), true) : monthlyValues(row),
        kind: changes ? 'percent' : 'rate',
      })),
    })
  }

  return groups
}
