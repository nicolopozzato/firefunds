import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import PageHeader from '@/components/PageHeader'
import { InvestmentSectionTabs } from '@/components/layout/SectionTabs'
import PositionMiniChart from '@/components/PositionMiniChart'
import OpenPositionCard, { PositionStat } from '@/components/OpenPositionCard'
import PieChart from '@/components/PieChart'
import MonthlyFlowsChart from '@/components/MonthlyFlowsChart'
import AfterTaxTooltip from '@/components/AfterTaxTooltip'
import EmptyValue from '@/components/EmptyValue'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useInvestmentPortfolio } from '@/hooks/use-investments'
import { useIsMobile } from '@/hooks/use-media-query'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { cn, formatMoney, formatPercent, formatQuantity, moneyColorClass } from '@/lib/utils'
import type { PortfolioPosition } from '@/types/investment'
import type { DistributionEntry } from '@/types/transaction'
import LoadFailed from '@/components/LoadFailed'

function Figure({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div>
      <div className={cn('text-xl font-semibold tabular-nums text-foreground', className)}>{children}</div>
      <div className="text-sm text-muted-foreground">{label}</div>
    </div>
  )
}

function signed(amount: string, text: string): string {
  return `${Number(amount) >= 0 ? '+' : ''}${text}`
}

/** What the portfolio is made of: each position's market value. */
function portfolioComposition(positions: PortfolioPosition[]): DistributionEntry[] {
  return positions.map((p) => ({ id: p.instrumentId, name: p.instrumentTicker ?? p.instrumentName, total: p.currentValue ?? '0' }))
}

function valueByAccount(positions: PortfolioPosition[]): DistributionEntry[] {
  const byAccount = new Map<string, DistributionEntry>()
  for (const holding of positions.flatMap((p) => p.accounts)) {
    const entry = byAccount.get(holding.accountId) ?? { id: holding.accountId, name: holding.accountName, total: '0' }
    entry.total = (Number(entry.total) + Number(holding.currentValue ?? 0)).toFixed(4)
    byAccount.set(holding.accountId, entry)
  }
  return [...byAccount.values()]
}

function PositionAccounts({ position, currencySymbol }: { position: PortfolioPosition; currencySymbol: string | null }) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()

  const gainOf = (holding: PortfolioPosition['accounts'][number]) =>
    holding.gainAmount !== null && holding.gainPercent !== null ? (
      <>
        {signed(holding.gainAmount, formatMoney(holding.gainAmount, currencySymbol))}
        <span className="ml-1.5 text-xs">({signed(holding.gainPercent, formatPercent(holding.gainPercent))})</span>
      </>
    ) : (
      <EmptyValue />
    )

  // Phones: five columns don't fit in the card, so each account is a block
  // with its figures two by two, as the position's own ones above.
  if (isMobile) {
    return (
      <div className="divide-y border-t">
        {position.accounts.map((holding) => (
          <div key={holding.accountId} className="space-y-2 py-3 last:pb-0">
            <Link to={`/accounts/asset/${holding.accountId}`} className="block truncate text-sm font-medium hover:underline">
              {holding.accountName}
            </Link>
            <div className="grid grid-cols-2 gap-x-6 gap-y-2">
              <PositionStat label={t('accountDetail.positionQuantity')}>{formatQuantity(holding.quantity)}</PositionStat>
              <PositionStat label={t('accountDetail.positionAverageCost')}>
                {formatMoney(holding.averageCost, currencySymbol)}
              </PositionStat>
              <PositionStat label={t('accountDetail.positionValue')}>
                {holding.currentValue !== null ? formatMoney(holding.currentValue, currencySymbol) : <EmptyValue />}
              </PositionStat>
              <PositionStat label={t('accountDetail.gain')}>
                <span className={cn(holding.gainAmount !== null && moneyColorClass(holding.gainAmount))}>{gainOf(holding)}</span>
              </PositionStat>
            </div>
          </div>
        ))}
      </div>
    )
  }

  return (
    <div className="border-t pt-3">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('investmentsOverview.account')}</TableHead>
            <TableHead className="text-right">{t('accountDetail.positionQuantity')}</TableHead>
            <TableHead className="text-right">{t('accountDetail.positionAverageCost')}</TableHead>
            <TableHead className="text-right">{t('accountDetail.positionValue')}</TableHead>
            <TableHead className="text-right">{t('accountDetail.gain')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {position.accounts.map((holding) => (
            <TableRow key={holding.accountId}>
              <TableCell>
                <Link to={`/accounts/asset/${holding.accountId}`} className="font-medium hover:underline">
                  {holding.accountName}
                </Link>
              </TableCell>
              <TableCell className="text-right tabular-nums">{formatQuantity(holding.quantity)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatMoney(holding.averageCost, currencySymbol)}</TableCell>
              <TableCell className="text-right tabular-nums">
                {holding.currentValue !== null ? formatMoney(holding.currentValue, currencySymbol) : <EmptyValue />}
              </TableCell>
              <TableCell className={cn('text-right tabular-nums', holding.gainAmount !== null && moneyColorClass(holding.gainAmount))}>
                {gainOf(holding)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

export default function InvestmentsOverview() {
  const { t } = useTranslation()
  const { timeframe } = useTimeframe()
  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')
  const { data: portfolio, isLoading, error, refetch } = useInvestmentPortfolio(from, to)

  const header = (
    <>
      <PageHeader title={t('investmentsOverview.title')} description={t('investmentsOverview.description')} />
      <InvestmentSectionTabs />
    </>
  )

  if (!portfolio && error) {
    return <LoadFailed error={error} onRetry={() => void refetch()} />
  }

  if (isLoading || !portfolio) {
    return (
      <div className="space-y-4 md:space-y-6">
        {header}
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-96 w-full rounded-xl" />
        <Skeleton className="h-56 w-full rounded-xl" />
      </div>
    )
  }

  const currency = portfolio.currencySymbol

  return (
    <div className="space-y-4 md:space-y-6">
      {header}

      <Card>
        <CardContent className="flex flex-wrap items-end gap-x-8 gap-y-4 pt-6">
          <div>
            <div className="text-3xl font-semibold tabular-nums">
              <AfterTaxTooltip variant="value" amount={portfolio.marketValue} estimatedTax={portfolio.estimatedTax} currencySymbol={currency}>
                {formatMoney(portfolio.marketValue, currency)}
              </AfterTaxTooltip>
            </div>
            <div className="text-sm text-muted-foreground">{t('investmentsOverview.marketValue')}</div>
          </div>
          <Figure label={t('investmentsOverview.costBasis')}>{formatMoney(portfolio.costBasis, currency)}</Figure>
          <Figure label={t('accountDetail.gain')} className={moneyColorClass(portfolio.gainAmount)}>
            <AfterTaxTooltip variant="gain" amount={portfolio.gainAmount} estimatedTax={portfolio.estimatedTax} currencySymbol={currency}>
              {signed(portfolio.gainAmount, formatMoney(portfolio.gainAmount, currency))}
            </AfterTaxTooltip>
            <span className="ml-1.5 text-xs">({signed(portfolio.gainPercent, formatPercent(portfolio.gainPercent))})</span>
          </Figure>
          <Figure label={t('investmentsOverview.cash')}>{formatMoney(portfolio.cash, currency)}</Figure>
          <Figure label={t('investmentsOverview.netWorthShareOf', { amount: formatMoney(portfolio.netWorth, currency) })}>
            {portfolio.netWorthShare !== null ? formatPercent(portfolio.netWorthShare, 1) : <EmptyValue />}
          </Figure>
        </CardContent>
      </Card>

      <div className="rounded-xl border bg-card p-4">
        <h3 className="mb-1 text-sm font-semibold text-foreground">{t('investmentsOverview.valueTrend')}</h3>
        <PositionMiniChart history={portfolio.history} currency={currency} height={300} />
      </div>

      <div className="space-y-3">
        <h2 className="text-lg font-semibold">{t('investmentsOverview.positions')}</h2>
        {portfolio.positions.length === 0 ? (
          <Card>
            <CardContent className="flex h-32 items-center justify-center text-sm text-muted-foreground">
              {t('investmentsOverview.noPositions')}
            </CardContent>
          </Card>
        ) : (
          portfolio.positions.map((position) => (
            <OpenPositionCard
              key={position.instrumentId}
              position={position}
              currencySymbol={currency}
              weight={position.portfolioShare}
              weightLabel={t('investmentsOverview.positionWeight')}
              extra={
                position.netWorthShare !== null && (
                  <div className="text-sm text-muted-foreground">
                    {t('investmentsOverview.positionNetWorthShare', { percent: formatPercent(position.netWorthShare, 1) })}
                  </div>
                )
              }
            >
              <PositionAccounts position={position} currencySymbol={currency} />
            </OpenPositionCard>
          ))
        )}
      </div>

      {/* grid-cols-1: a column that can shrink, or a long legend widens the page on phones. */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <PieChart
          title={t('investmentsOverview.portfolioComposition')}
          entries={portfolioComposition(portfolio.positions)}
          currency={currency ?? undefined}
          emptyLabel={t('charts.noData')}
        />
        <PieChart
          title={t('investmentsOverview.valueByAccount')}
          entries={valueByAccount(portfolio.positions)}
          currency={currency ?? undefined}
          emptyLabel={t('charts.noData')}
        />
      </div>

      <MonthlyFlowsChart
        title={t('investmentsOverview.monthlyFlows')}
        flows={portfolio.monthlyFlows}
        currency={currency}
        emptyLabel={t('charts.noData')}
      />
    </div>
  )
}
