import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { formatPercent, numberFormatter } from '@/lib/utils'
import { monthlyChanges, monthlyValues, remainder, sumRows, withOpening, type MonthlyValues } from '@/lib/net-worth'
import type { NetWorthReport, NetWorthRow } from '@/types/report'

export type NetWorthView = 'values' | 'changes'

export type Kind = 'money' | 'signed' | 'price' | 'percent'

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

const whole = (value: number) => numberFormatter({ maximumFractionDigits: 0 }).format(value)

export function formatCell(value: number, kind: Kind): string {
  switch (kind) {
    case 'money':
      return whole(value)
    case 'signed':
      return `${value > 0 ? '+' : ''}${whole(value)}`
    case 'price':
      return numberFormatter({ minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
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
    label: <span title={row.name ?? undefined}>{row.ticker ?? row.name}</span>,
    data: shown(withOpening(row)),
    kind: valueKind,
  })

  const total = withOpening(report.total)
  const totalChange = monthlyChanges(total)
  const saved = monthlyValues(report.saved)
  const market = monthlyValues(report.market)
  const other = remainder(totalChange, saved, market)
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

  return groups
}
