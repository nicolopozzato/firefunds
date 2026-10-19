import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { eachDayOfInterval, format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { Landmark, LineChart, PiggyBank, Scale, SlidersHorizontal } from 'lucide-react'
import StatCard from '@/components/StatCard'
import PageHeader from '@/components/PageHeader'
import MultiLineChart, { type LineSeries } from '@/components/MultiLineChart'
import RankedBarChart from '@/components/RankedBarChart'
import DashboardAccountCard from '@/components/DashboardAccountCard'
import PillGroup from '@/components/ui/pill-group'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useDashboard } from '@/hooks/use-dashboard'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { OTHER_COLOR, SERIES_COLORS } from '@/lib/chart'
import { cn, formatMoney, formatPercent, moneyColorClass, numberFormatter } from '@/lib/utils'
import type { Dashboard as DashboardData } from '@/types/dashboard'

const EXPENSE_COLOR = 'hsl(var(--series-2))'
const REVENUE_COLOR = 'hsl(var(--series-1))'

type TrendView = 'accounts' | 'investments'
type InvestmentUnit = 'percent' | 'money'
const TREND_VIEW_KEY = 'firefunds-dashboard-trend'
const INVESTMENT_UNIT_KEY = 'firefunds-dashboard-investment-unit'

/** A choice remembered in this browser only: a convenience, the page works without it. */
function loadChoice<T extends string>(key: string, options: readonly T[], fallback: T): T {
  try {
    const stored = localStorage.getItem(key)
    return options.find((option) => option === stored) ?? fallback
  } catch {
    return fallback
  }
}

function saveChoice(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Not remembered: nothing else to do.
  }
}

/** A fixed slot per series, in the order given; past the eighth a neutral gray, never a cycled hue. */
function seriesColor(index: number): string {
  return SERIES_COLORS[index] ?? OTHER_COLOR
}

function signedMoney(amount: string | number, currency: string | null): string {
  return `${Number(amount) > 0 ? '+' : ''}${formatMoney(amount, currency)}`
}

function signedPercent(value: string | number, decimals = 2): string {
  return `${Number(value) > 0 ? '+' : ''}${formatPercent(value, decimals)}`
}

function SummaryCards({ dashboard }: { dashboard: DashboardData }) {
  const { t } = useTranslation()
  const { summary, currencySymbol: currency } = dashboard

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard
        title={t('dashboard.balance')}
        value={signedMoney(summary.balance, currency)}
        valueClassName={moneyColorClass(summary.balance)}
        icon={Scale}
        iconColor="blue"
        detail={
          <span className="tabular-nums">
            <span className="text-positive">{formatMoney(summary.income, null)}</span>
            {' − '}
            <span className="text-negative">{formatMoney(summary.expenses, null)}</span>
          </span>
        }
      />
      <StatCard
        title={t('dashboard.savingsRate')}
        value={summary.savingsRate !== null ? formatPercent(summary.savingsRate, 1) : '—'}
        valueClassName={summary.savingsRate !== null && Number(summary.savingsRate) < 0 ? 'text-negative' : undefined}
        icon={PiggyBank}
        iconColor="green"
        detail={summary.savingsRate !== null ? t('dashboard.savingsRateDetail') : t('dashboard.noIncome')}
      />
      <StatCard
        title={t('dashboard.investments')}
        value={formatMoney(summary.investmentsEnd, currency)}
        icon={LineChart}
        iconColor="orange"
        detail={
          <span className="tabular-nums">
            <span className={moneyColorClass(summary.investmentGain)}>{signedMoney(summary.investmentGain, currency)}</span>{' '}
            {t('dashboard.fromMarket')}
            {Number(summary.investmentContributions) !== 0 && (
              <> · {t('dashboard.contributed', { amount: signedMoney(summary.investmentContributions, currency) })}</>
            )}
          </span>
        }
      />
      <StatCard
        title={t('dashboard.netWorth')}
        value={formatMoney(summary.netWorthEnd, currency)}
        icon={Landmark}
        iconColor="yellow"
        detail={
          <span className="tabular-nums">
            <span className={moneyColorClass(summary.netWorthChange)}>
              {signedMoney(summary.netWorthChange, currency)}
              {summary.netWorthChangePercent !== null && ` (${signedPercent(summary.netWorthChangePercent, 1)})`}
            </span>{' '}
            {t('dashboard.sinceStart')}
          </span>
        }
      />
    </div>
  )
}

function TrendCard({ dashboard, days }: { dashboard: DashboardData; days: string[] }) {
  const { t } = useTranslation()
  const [view, setView] = useState<TrendView>(() => loadChoice(TREND_VIEW_KEY, ['accounts', 'investments'], 'accounts'))
  const [unit, setUnit] = useState<InvestmentUnit>(() => loadChoice(INVESTMENT_UNIT_KEY, ['percent', 'money'], 'percent'))
  const currency = dashboard.currencySymbol
  const hasInstruments = dashboard.instruments.length > 0
  const shownView: TrendView = hasInstruments ? view : 'accounts'

  const changeView = (next: TrendView) => {
    setView(next)
    saveChoice(TREND_VIEW_KEY, next)
  }
  const changeUnit = (next: InvestmentUnit) => {
    setUnit(next)
    saveChoice(INVESTMENT_UNIT_KEY, next)
  }

  // Investment accounts belong to the investments view: their value would flatten the cash accounts' lines.
  // Colors follow the account's place among all the shown ones, as its box on the right.
  const accountSeries: LineSeries[] = dashboard.accounts.flatMap((account, index) =>
    account.assetKind === 'investment'
      ? []
      : [{ id: account.id, label: account.name, color: seriesColor(index), points: account.history }]
  )

  const instrumentSeries: LineSeries[] = dashboard.instruments.map((instrument, index) => ({
    id: instrument.instrumentId,
    label: instrument.instrumentTicker ?? instrument.instrumentName,
    color: seriesColor(index),
    points: unit === 'percent' ? instrument.performance : instrument.values,
    detail: (
      <>
        {instrument.priceChangePercent !== null && (
          <span className={moneyColorClass(instrument.priceChangePercent)}>{signedPercent(instrument.priceChangePercent)}</span>
        )}
        <span className="text-muted-foreground"> · {formatMoney(instrument.valueEnd, currency)} · </span>
        <span className={moneyColorClass(instrument.gain)}>{signedMoney(instrument.gain, currency)}</span>
      </>
    ),
  }))

  const wholeMoney = (value: number) => `${numberFormatter({ maximumFractionDigits: 0 }).format(value)}${currency ? ` ${currency}` : ''}`

  return (
    <div className="rounded-xl border bg-card p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-foreground">
          {shownView === 'accounts' ? t('dashboard.accountsTrend') : t('dashboard.investmentsTrend')}
        </h3>
        <div className="flex flex-wrap items-center gap-3">
          {shownView === 'investments' && (
            <PillGroup
              label={t('dashboard.unit')}
              value={unit}
              onChange={changeUnit}
              options={[
                { value: 'percent', label: '%' },
                { value: 'money', label: currency ?? '€' },
              ]}
            />
          )}
          {hasInstruments && (
            <PillGroup
              label={t('dashboard.show')}
              value={shownView}
              onChange={changeView}
              options={[
                { value: 'accounts', label: t('dashboard.viewAccounts') },
                { value: 'investments', label: t('dashboard.viewInvestments') },
              ]}
            />
          )}
        </div>
      </div>

      {shownView === 'accounts' ? (
        <MultiLineChart
          key="accounts"
          days={days}
          series={accountSeries}
          formatValue={(value) => formatMoney(value, currency)}
          formatTick={wholeMoney}
          legendColumns={3}
          emptyLabel={t('dashboard.noCashAccountsShown')}
        />
      ) : (
        <>
          {unit === 'percent' ? (
            <MultiLineChart
              key="investments-percent"
              days={days}
              series={instrumentSeries}
              formatValue={(value) => signedPercent(value)}
              formatTick={(value) => formatPercent(value, Math.abs(value) < 10 ? 1 : 0)}
              baseline={0}
              legendColumns={1}
              emptyLabel={t('charts.noData')}
            />
          ) : (
            <MultiLineChart
              key="investments-money"
              days={days}
              series={instrumentSeries}
              formatValue={(value) => formatMoney(value, currency)}
              formatTick={wholeMoney}
              legendColumns={1}
              emptyLabel={t('charts.noData')}
            />
          )}
          <p className="mt-2 text-xs text-muted-foreground">
            {unit === 'percent' ? t('dashboard.investmentsTrendHint') : t('dashboard.investmentsValueHint')}
          </p>
        </>
      )}
    </div>
  )
}

export default function Dashboard() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const locale = i18n.language === 'it' ? it : enUS
  const { timeframe } = useTimeframe()
  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')
  const { data: dashboard, isLoading } = useDashboard(from, to)

  const days = useMemo(
    () => (timeframe.from <= timeframe.to ? eachDayOfInterval({ start: timeframe.from, end: timeframe.to }).map((day) => format(day, 'yyyy-MM-dd')) : []),
    [timeframe.from, timeframe.to]
  )

  const header = (
    <PageHeader
      title={t('dashboard.title')}
      description={t('dashboard.period', {
        from: format(timeframe.from, 'd MMM yyyy', { locale }),
        to: format(timeframe.to, 'd MMM yyyy', { locale }),
      })}
      actions={
        <Button variant="outline" onClick={() => navigate('/preferences?tab=dashboard')} aria-label={t('dashboard.customize')}>
          <SlidersHorizontal className="h-4 w-4 md:mr-2" />
          <span className="hidden md:inline">{t('dashboard.customize')}</span>
        </Button>
      }
    />
  )

  if (isLoading || !dashboard) {
    return (
      <div className="space-y-6">
        {header}
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
          {[0, 1, 2, 3].map((index) => (
            <Skeleton key={index} className="h-28 w-full rounded-xl" />
          ))}
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-96 w-full rounded-xl lg:col-span-2" />
          <Skeleton className="h-96 w-full rounded-xl" />
        </div>
      </div>
    )
  }

  const currency = dashboard.currencySymbol

  return (
    <div className="space-y-4 md:space-y-6">
      {header}

      <SummaryCards dashboard={dashboard} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          <TrendCard dashboard={dashboard} days={days} />

          <RankedBarChart
            title={t('dashboard.expensesByCategory')}
            entries={dashboard.expensesByCategory}
            color={EXPENSE_COLOR}
            currency={currency}
            emptyLabel={t('charts.noData')}
          />

          <RankedBarChart
            title={t('dashboard.expenseAccounts')}
            entries={dashboard.expenseAccounts}
            color={EXPENSE_COLOR}
            currency={currency}
            emptyLabel={t('charts.noData')}
            nullLabel={t('dashboard.noAccount')}
          />

          <RankedBarChart
            title={t('dashboard.revenueAccounts')}
            entries={dashboard.revenueAccounts}
            color={REVENUE_COLOR}
            currency={currency}
            emptyLabel={t('charts.noData')}
            nullLabel={t('dashboard.noAccount')}
          />
        </div>

        {/* Phones: the accounts right under the summary, before the charts. */}
        <div className="min-w-0 space-y-4 max-md:order-first">
          {dashboard.accounts.length === 0 ? (
            <Card>
              <CardContent className="space-y-2 pt-6 text-sm text-muted-foreground">
                <p>{t('dashboard.noAccountsShown')}</p>
                <Link to="/preferences?tab=dashboard" className={cn('font-medium text-primary hover:underline')}>
                  {t('dashboard.chooseAccounts')}
                </Link>
              </CardContent>
            </Card>
          ) : (
            dashboard.accounts.map((account, index) => (
              <DashboardAccountCard key={account.id} account={account} color={seriesColor(index)} to={to} />
            ))
          )}
        </div>
      </div>
    </div>
  )
}
