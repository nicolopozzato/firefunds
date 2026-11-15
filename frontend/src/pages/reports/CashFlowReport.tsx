import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format, parseISO } from 'date-fns'
import { ArrowDownToLine, CalendarRange, Scale, Wallet } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import StatCard from '@/components/StatCard'
import LoadFailed from '@/components/LoadFailed'
import PillGroup from '@/components/ui/pill-group'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { ReportSectionTabs } from '@/components/layout/SectionTabs'
import { BarLegend } from '@/components/reports/GroupedBarChart'
import MonthlyLineChart from '@/components/reports/MonthlyLineChart'
import CashFlowPeriodDialog from '@/components/reports/CashFlowPeriodDialog'
import { cashFlowAxisLabel, cashFlowPeriodLabel, layersTotal } from '@/components/reports/cash-flow-format'
import { ReportCard, TooltipLine } from '@/components/reports/report-ui'
import { useIsMobile } from '@/hooks/use-media-query'
import { useCashFlowReport } from '@/hooks/use-reports'
import { OTHER_COLOR, SERIES_COLORS } from '@/lib/chart'
import { dateLocale, signedMoney } from '@/lib/report-format'
import { cn, currencySymbolFor, formatMoney, moneyColorClass } from '@/lib/utils'
import type { CashFlowAccount, CashFlowBy, CashFlowLayer, CashFlowOptions, CashFlowPeriod, CashFlowReport as ReportData } from '@/types/report'

const MONTHS = [1, 3, 6, 12, 24] as const
const AVERAGES = [1, 3, 6, 12, 24] as const
const SEASONAL = [0, 25, 50, 75] as const
const LAYERS: CashFlowLayer[] = ['entered', 'recurring', 'estimated']
/** The finer columns only that far ahead, as the server allows. */
const MAX_MONTHS: Record<CashFlowBy, number> = { day: 3, week: 12, month: 24 }
const CHOICES_KEY = 'firefunds-report-cash-flow'
const TOTAL_COLOR = SERIES_COLORS[0]

type View = 'total' | 'accounts'
/** The net worth (cash and what is held, at today's prices) or the cash alone. */
type Measure = 'netWorth' | 'cash'
interface Choices extends CashFlowOptions {
  view: View
  measure: Measure
}

const DEFAULTS: Choices = { months: 12, by: 'month', average: 6, seasonal: 0, layers: LAYERS, view: 'total', measure: 'netWorth' }

/** The figure a column, an account or the start shows, as chosen. */
const periodValue = (period: CashFlowPeriod, measure: Measure) => Number(measure === 'cash' ? period.balance : period.netWorth)
const accountValues = (account: CashFlowAccount, measure: Measure) => (measure === 'cash' ? account.balances : account.values).map(Number)
const openingValue = (report: ReportData, measure: Measure) => Number(measure === 'cash' ? report.opening : report.openingNetWorth)

/** The column that suits a horizon: days for a month, weeks up to half a year, then months. */
function defaultBy(months: number): CashFlowBy {
  return months <= 1 ? 'day' : months <= 6 ? 'week' : 'month'
}

function oneOf<T extends number>(choices: readonly T[], value: number, fallback: T): T {
  return (choices as readonly number[]).includes(value) ? (value as T) : fallback
}

/** The options from a query string (the URL, else the last ones used in this browser). */
function readChoices(params: URLSearchParams): Choices {
  const months = oneOf(MONTHS, Number(params.get('months')), DEFAULTS.months)
  const by = (['day', 'week', 'month'] as const).find((value) => value === params.get('by')) ?? defaultBy(months)
  return {
    months,
    by: months <= MAX_MONTHS[by] ? by : defaultBy(months),
    average: oneOf(AVERAGES, Number(params.get('average')), DEFAULTS.average),
    seasonal: oneOf(SEASONAL, Number(params.get('seasonal')), DEFAULTS.seasonal),
    layers: params.has('layers') ? LAYERS.filter((layer) => params.get('layers')!.split(',').includes(layer)) : DEFAULTS.layers,
    view: params.get('view') === 'accounts' ? 'accounts' : 'total',
    measure: params.get('show') === 'cash' ? 'cash' : 'netWorth',
  }
}

function toParams(choices: Choices): URLSearchParams {
  return new URLSearchParams({
    months: String(choices.months),
    by: choices.by,
    average: String(choices.average),
    seasonal: String(choices.seasonal),
    layers: choices.layers.join(','),
    view: choices.view,
    show: choices.measure,
  })
}

function remembered(): URLSearchParams {
  try {
    return new URLSearchParams(localStorage.getItem(CHOICES_KEY) ?? '')
  } catch {
    return new URLSearchParams()
  }
}

function remember(params: URLSearchParams): void {
  try {
    localStorage.setItem(CHOICES_KEY, params.toString())
  } catch {
    // Not remembered: nothing else to do.
  }
}

function SummaryCards({ report, measure }: { report: ReportData; measure: Measure }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = currencySymbolFor(report.currency)
  const last = report.periods[report.periods.length - 1]
  const opening = openingValue(report, measure)
  const end = last ? periodValue(last, measure) : opening
  const lowest = report.periods.reduce<{ value: number; period: CashFlowPeriod | null }>(
    (low, period) => (periodValue(period, measure) < low.value ? { value: periodValue(period, measure), period } : low),
    { value: opening, period: null },
  )
  const net = report.periods.reduce((sum, period) => sum + layersTotal(period.income) - layersTotal(period.expense), 0)
  const until = last ? format(parseISO(last.end), 'd MMMM yyyy', { locale }) : ''

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard
        title={t(measure === 'cash' ? 'reports.cashFlow.todayBalance' : 'reports.cashFlow.todayNetWorth')}
        value={formatMoney(opening, currency)}
        icon={Wallet}
        iconColor="blue"
        detail={measure === 'netWorth' ? t('reports.cashFlow.ofWhichCash', { amount: formatMoney(report.opening, currency) }) : undefined}
      />
      <StatCard
        title={t(measure === 'cash' ? 'reports.cashFlow.endBalance' : 'reports.cashFlow.endNetWorth')}
        value={formatMoney(end, currency)}
        icon={CalendarRange}
        iconColor="yellow"
        detail={
          <>
            <span className={moneyColorClass(end - opening)}>{signedMoney(end - opening, currency)}</span> {t('reports.cashFlow.onDate', { date: until })}
          </>
        }
      />
      <StatCard
        title={t('reports.cashFlow.lowest')}
        value={formatMoney(lowest.value, currency)}
        valueClassName={lowest.value < 0 ? 'text-negative' : undefined}
        icon={ArrowDownToLine}
        iconColor="orange"
        detail={lowest.period ? cashFlowPeriodLabel(lowest.period, report.by, locale) : t('reports.cashFlow.today')}
      />
      <StatCard
        title={t('reports.cashFlow.net')}
        value={signedMoney(net, currency)}
        valueClassName={moneyColorClass(net)}
        icon={Scale}
        iconColor="green"
        detail={t('reports.cashFlow.netHint')}
      />
    </div>
  )
}

function BalanceChart({ report, view, measure }: { report: ReportData; view: View; measure: Measure }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = currencySymbolFor(report.currency)
  const labels = report.periods.map((period) => cashFlowAxisLabel(period, report.by, locale))
  const colorOf = (index: number) => SERIES_COLORS[index] ?? OTHER_COLOR

  const series =
    view === 'total'
      ? [{ id: 'total', label: t(measure === 'cash' ? 'reports.cashFlow.cash' : 'reports.cashFlow.netWorth'), color: TOTAL_COLOR, values: report.periods.map((period) => periodValue(period, measure)) }]
      : report.accounts.map((account, index) => ({ id: account.id, label: account.name, color: colorOf(index), values: accountValues(account, measure) }))

  const tooltip = (index: number) => {
    const period = report.periods[index]
    const invested = layersTotal(period.invested)
    return (
      <>
        <span className="whitespace-nowrap text-xs text-muted-foreground">{cashFlowPeriodLabel(period, report.by, locale)}</span>
        {series.map((line) => (
          <TooltipLine key={line.id} color={line.color} label={line.label} value={formatMoney(line.values[index] ?? 0, currency)} />
        ))}
        {view === 'total' && (
          <>
            {measure === 'netWorth' && <TooltipLine label={t('reports.cashFlow.cash')} value={formatMoney(period.balance, currency)} />}
            <TooltipLine label={t('reports.income')} value={formatMoney(layersTotal(period.income), currency)} />
            <TooltipLine label={t('reports.expenses')} value={formatMoney(layersTotal(period.expense), currency)} />
            {invested > 0 && <TooltipLine label={t('reports.cashFlow.invested')} value={formatMoney(invested, currency)} />}
          </>
        )}
      </>
    )
  }

  return (
    <ReportCard title={t(measure === 'cash' ? 'reports.cashFlow.chartTitle' : 'reports.cashFlow.chartTitleNetWorth')} actions={view === 'accounts' ? <BarLegend series={series} /> : undefined}>
      <MonthlyLineChart labels={labels} series={series} currency={currency} emptyLabel={t('reports.cashFlow.noAccounts')} tooltip={tooltip} />
    </ReportCard>
  )
}

function PeriodTable({ report, measure, onOpen }: { report: ReportData; measure: Measure; onOpen: (period: CashFlowPeriod) => void }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = currencySymbolFor(report.currency)
  const amount = (value: number, className: string, sign: string) => (value === 0 ? <span className="text-muted-foreground">—</span> : <span className={className}>{`${sign}${formatMoney(value, currency)}`}</span>)

  return (
    <section className="min-w-0 surface pb-2">
      <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4">
        <h3 className="section-title">{t('reports.cashFlow.tableTitle')}</h3>
      </div>
      <table className="mt-2 w-full table-fixed text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="px-4 py-2 text-left font-medium">{t('reports.cashFlow.period')}</th>
            <th className="w-36 px-2 py-2 text-right font-medium">{t('reports.income')}</th>
            <th className="w-36 px-2 py-2 text-right font-medium">{t('reports.expenses')}</th>
            <th className="w-36 px-2 py-2 text-right font-medium">{t('reports.cashFlow.invested')}</th>
            <th className="w-40 px-4 py-2 text-right font-medium">{t(measure === 'cash' ? 'reports.cashFlow.balance' : 'reports.cashFlow.netWorth')}</th>
          </tr>
        </thead>
        <tbody className="tabular-nums">
          {report.periods.map((period) => (
            <tr key={period.start} onClick={() => onOpen(period)} className="cursor-pointer border-b last:border-0 hover:bg-muted/50">
              <td className="cell-text px-4 py-2">{cashFlowPeriodLabel(period, report.by, locale)}</td>
              <td className="px-2 py-2 text-right">{amount(layersTotal(period.income), 'text-positive', '+')}</td>
              <td className="px-2 py-2 text-right">{amount(layersTotal(period.expense), 'text-negative', '−')}</td>
              <td className="px-2 py-2 text-right">{amount(layersTotal(period.invested), 'text-invest', '−')}</td>
              <td className={cn('px-4 py-2 text-right font-semibold', periodValue(period, measure) < 0 && 'text-negative')}>{formatMoney(periodValue(period, measure), currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

/** Phones: a card per column, its balance and what comes in and goes out. */
function PeriodList({ report, measure, onOpen }: { report: ReportData; measure: Measure; onOpen: (period: CashFlowPeriod) => void }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = currencySymbolFor(report.currency)

  return (
    <section className="space-y-2">
      <h3 className="section-title">{t('reports.cashFlow.tableTitle')}</h3>
      <ul className="surface divide-y">
        {report.periods.map((period) => {
          const out = layersTotal(period.expense) + layersTotal(period.invested)
          return (
            <li key={period.start}>
              <button type="button" onClick={() => onOpen(period)} className="flex w-full items-center gap-3 px-4 py-3 text-left">
                <div className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{cashFlowPeriodLabel(period, report.by, locale)}</span>
                  <span className="block text-xs tabular-nums text-muted-foreground">
                    <span className="text-positive">+{formatMoney(layersTotal(period.income), currency)}</span> · <span className="text-negative">−{formatMoney(out, currency)}</span>
                  </span>
                </div>
                <span className={cn('figure-sm tabular-nums', periodValue(period, measure) < 0 && 'text-negative')}>{formatMoney(periodValue(period, measure), currency)}</span>
              </button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}

/**
 * Where the asset accounts' cash is going from today on: what is already
 * entered for the days to come, what the recurrences will move and an
 * estimate of the rest from the past, each optional; with what is held (at
 * today's prices, no return assumed) it is the net worth ahead, the net
 * worth report being the past. The options are in the URL.
 */
export default function CashFlowReport() {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const [searchParams, setSearchParams] = useSearchParams()
  const isMobile = useIsMobile()
  const choices = useMemo(() => readChoices(searchParams.has('months') ? searchParams : remembered()), [searchParams])
  const { view, measure, ...options } = choices
  const { data: report, isLoading, isPlaceholderData, error, refetch } = useCashFlowReport(options)
  const [opened, setOpened] = useState<CashFlowPeriod | null>(null)

  const update = (next: Partial<Choices>) => {
    const chosen = { ...choices, ...next }
    // A horizon too long for the column chosen takes the one that suits it.
    if (next.months !== undefined && next.by === undefined) chosen.by = defaultBy(chosen.months)
    const params = toParams(chosen)
    remember(params)
    setSearchParams(params, { replace: true })
  }
  const toggleLayer = (layer: CashFlowLayer) =>
    update({ layers: LAYERS.filter((one) => (one === layer ? !choices.layers.includes(one) : choices.layers.includes(one))) })

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader title={t('reports.cashFlow.title')} description={t('reports.cashFlow.description')} />
      <ReportSectionTabs />

      <div className="flex flex-wrap items-center gap-x-6 gap-y-3 surface p-3">
        <PillGroup
          label={t('reports.cashFlow.horizon')}
          value={String(choices.months)}
          onChange={(value) => update({ months: Number(value) })}
          options={MONTHS.map((months) => ({ value: String(months), label: t(`reports.cashFlow.months_${months}`) }))}
        />
        <PillGroup
          label={t('reports.cashFlow.by')}
          value={choices.by}
          onChange={(value) => update({ by: value })}
          options={(['day', 'week', 'month'] as const).filter((by) => choices.months <= MAX_MONTHS[by]).map((by) => ({ value: by, label: t(`reports.cashFlow.${by}`) }))}
        />
        <PillGroup
          label={t('reports.cashFlow.show')}
          value={measure}
          onChange={(value) => update({ measure: value })}
          options={[
            { value: 'netWorth', label: t('reports.cashFlow.netWorth') },
            { value: 'cash', label: t('reports.cashFlow.cashOnly') },
          ]}
        />
        <PillGroup
          label={t('reports.cashFlow.view')}
          value={view}
          onChange={(value) => update({ view: value })}
          options={[
            { value: 'total', label: t('reports.cashFlow.total') },
            { value: 'accounts', label: t('reports.cashFlow.perAccount') },
          ]}
        />
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2" role="group" aria-label={t('reports.cashFlow.include')}>
          <span className="text-sm text-muted-foreground">{t('reports.cashFlow.include')}</span>
          {LAYERS.map((layer) => (
            <label key={layer} className="flex h-9 cursor-pointer items-center gap-2 text-sm">
              <Checkbox checked={choices.layers.includes(layer)} onChange={() => toggleLayer(layer)} />
              {t(`reports.cashFlow.${layer}`)}
            </label>
          ))}
        </div>
        {choices.layers.includes('estimated') && (
          <>
            <PillGroup
              label={t('reports.cashFlow.average')}
              value={String(choices.average)}
              onChange={(value) => update({ average: Number(value) })}
              options={AVERAGES.map((months) => ({ value: String(months), label: t('reports.cashFlow.averageMonths', { count: months }) }))}
            />
            <PillGroup
              label={t('reports.cashFlow.seasonal')}
              value={String(choices.seasonal)}
              onChange={(value) => update({ seasonal: Number(value) })}
              options={SEASONAL.map((weight) => ({ value: String(weight), label: weight === 0 ? t('reports.cashFlow.seasonalNone') : `${weight}%` }))}
            />
          </>
        )}
      </div>

      {!report && error ? (
        <LoadFailed error={error} onRetry={() => void refetch()} />
      ) : isLoading || !report ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            {[0, 1, 2, 3].map((index) => (
              <Skeleton key={index} className="h-28 w-full rounded-xl" />
            ))}
          </div>
          <Skeleton className="h-80 w-full rounded-xl" />
          <Skeleton className="h-96 w-full rounded-xl" />
        </div>
      ) : (
        <div className={cn('space-y-4 transition-opacity md:space-y-6', isPlaceholderData && 'opacity-60')}>
          <SummaryCards report={report} measure={measure} />
          <BalanceChart report={report} view={view} measure={measure} />
          <div className="space-y-1 text-xs text-muted-foreground">
            {measure === 'netWorth' && <p>{t('reports.cashFlow.heldNote')}</p>}
            {choices.layers.includes('estimated') && (
              <p>{report.estimateMonths > 0 ? t('reports.cashFlow.estimateNote', { count: report.estimateMonths }) : t('reports.cashFlow.noEstimate')}</p>
            )}
          </div>
          {isMobile ? <PeriodList report={report} measure={measure} onOpen={setOpened} /> : <PeriodTable report={report} measure={measure} onOpen={setOpened} />}
          <CashFlowPeriodDialog
            period={opened}
            title={opened ? cashFlowPeriodLabel(opened, report.by, locale) : ''}
            currency={currencySymbolFor(report.currency)}
            onClose={() => setOpened(null)}
          />
        </div>
      )}
    </div>
  )
}
