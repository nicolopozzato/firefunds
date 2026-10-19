import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { eachDayOfInterval, endOfMonth, format, parseISO, startOfMonth } from 'date-fns'
import { ArrowDownCircle, ArrowUpCircle, Landmark, PiggyBank } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import StatCard from '@/components/StatCard'
import MultiLineChart from '@/components/MultiLineChart'
import PillGroup from '@/components/ui/pill-group'
import { Skeleton } from '@/components/ui/skeleton'
import { ReportSectionTabs } from '@/components/layout/SectionTabs'
import ReportPeriodPicker from '@/components/reports/ReportPeriodPicker'
import GroupedBarChart, { BarLegend } from '@/components/reports/GroupedBarChart'
import CategoryTable, { type CategoryCell } from '@/components/reports/CategoryTable'
import CategoryTransactionsDialog from '@/components/reports/CategoryTransactionsDialog'
import ReportAccountsTable from '@/components/reports/ReportAccountsTable'
import ReportAccountsList from '@/components/reports/ReportAccountsList'
import CategoryMobileList from '@/components/reports/CategoryMobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { ChangePercent, ReportCard, TooltipLine } from '@/components/reports/report-ui'
import { useIncomeExpenseReport } from '@/hooks/use-reports'
import { transactionsApi } from '@/lib/transactions-api'
import { changePercent, dateLocale, formatReportRange, periodLabel, signedMoney } from '@/lib/report-format'
import {
  defaultGranularity,
  isRollingPreset,
  rangeForReportPreset,
  readIncomeExpenseOptions,
  shiftReportSpan,
  toIsoDay,
  writeIncomeExpenseOptions,
  type IncomeExpenseOptions,
  type ReportPreset,
  type ReportSpan,
} from '@/lib/report-period'
import { cn, formatMoney, formatPercent, moneyColorClass, numberFormatter } from '@/lib/utils'
import type { IncomeExpenseReport as ReportData, ReportComparison, ReportGranularity } from '@/types/report'

const INCOME_COLOR = 'hsl(var(--positive))'
const EXPENSE_COLOR = 'hsl(var(--negative))'
const NET_WORTH_COLOR = 'hsl(var(--series-1))'
const STORAGE_KEY = 'firefunds-report-income-expenses'
/** Without anything in the URL or remembered: this year, a column per month. */
const DEFAULT_OPTIONS = 'preset=currentYear'

/** The options last used here, remembered in this browser only: a convenience, the page works without it. */
function loadOptions(): string {
  try {
    return localStorage.getItem(STORAGE_KEY) ?? DEFAULT_OPTIONS
  } catch {
    return DEFAULT_OPTIONS
  }
}

function saveOptions(search: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, search)
  } catch {
    // Not remembered: nothing else to do.
  }
}

function SummaryCards({ report }: { report: ReportData }) {
  const { t } = useTranslation()
  const { summary, comparison, currencySymbol: currency } = report
  const previous = comparison?.summary

  const versus = (current: string, before: string | undefined, higherIsBetter: boolean) =>
    before !== undefined ? (
      <span>
        <ChangePercent value={changePercent(current, before)} higherIsBetter={higherIsBetter} /> {t('reports.versus', { amount: formatMoney(before, currency) })}
      </span>
    ) : null

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard
        title={t('reports.income')}
        value={formatMoney(summary.income, currency)}
        valueClassName="text-positive"
        icon={ArrowDownCircle}
        iconColor="green"
        detail={versus(summary.income, previous?.income, true) ?? t('reports.perMonth', { amount: formatMoney(Number(summary.income) / summary.months, currency) })}
      />
      <StatCard
        title={t('reports.expenses')}
        value={formatMoney(summary.expenses, currency)}
        valueClassName="text-negative"
        icon={ArrowUpCircle}
        iconColor="orange"
        detail={versus(summary.expenses, previous?.expenses, false) ?? t('reports.perMonth', { amount: formatMoney(Number(summary.expenses) / summary.months, currency) })}
      />
      <StatCard
        title={t('reports.saved')}
        value={signedMoney(summary.balance, currency)}
        valueClassName={moneyColorClass(summary.balance)}
        icon={PiggyBank}
        iconColor="blue"
        detail={
          <span className="tabular-nums">
            {summary.savingsRate !== null && t('reports.savingsRate', { rate: formatPercent(summary.savingsRate, 1) })}
            {Number(summary.invested) !== 0 && (
              <>
                {summary.savingsRate !== null && ' · '}
                {t('reports.ofWhichInvested', { amount: formatMoney(summary.invested, currency) })}
              </>
            )}
            {previous && (
              <>
                {' · '}
                {t('reports.versus', { amount: signedMoney(previous.balance, currency) })}
              </>
            )}
          </span>
        }
      />
      <StatCard
        title={t('reports.netWorth')}
        value={formatMoney(summary.netWorthEnd, currency)}
        icon={Landmark}
        iconColor="yellow"
        detail={
          <span className="tabular-nums">
            <span className={moneyColorClass(summary.netWorthChange)}>{signedMoney(summary.netWorthChange, currency)}</span> {t('reports.inTheRange')}
          </span>
        }
      />
    </div>
  )
}

function IncomeExpenseChart({ report }: { report: ReportData }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const { periods, comparison, by, currencySymbol: currency } = report
  const sameColumns = comparison !== null && comparison.periods.length === periods.length

  const series = useMemo(
    () => [
      { id: 'income', label: t('reports.income'), color: INCOME_COLOR, values: periods.map((p) => Number(p.income)) },
      { id: 'expenses', label: t('reports.expenses'), color: EXPENSE_COLOR, values: periods.map((p) => Number(p.expenses)) },
    ],
    [periods, t]
  )
  const labels = useMemo(() => periods.map((p) => `${periodLabel(p.key, by, locale)}${p.partial ? '*' : ''}`), [periods, by, locale])

  return (
    <ReportCard title={t('reports.incomeVsExpenses')} actions={<BarLegend series={series} />}>
      <GroupedBarChart
        labels={labels}
        series={series}
        currency={currency}
        emptyLabel={t('charts.noData')}
        tooltip={(index) => {
          const period = periods[index]
          const before = sameColumns ? comparison.periods[index] : null
          return (
            <>
              <span className="whitespace-nowrap text-xs text-muted-foreground">
                {periodLabel(period.key, by, locale, true)}
                {period.partial && ` (${formatReportRange(parseISO(period.from), parseISO(period.to), locale)})`}
              </span>
              <TooltipLine color={INCOME_COLOR} label={t('reports.income')} value={formatMoney(period.income, currency)} />
              <TooltipLine color={EXPENSE_COLOR} label={t('reports.expenses')} value={formatMoney(period.expenses, currency)} />
              <TooltipLine label={t('reports.difference')} value={signedMoney(period.balance, currency)} />
              {Number(period.invested) !== 0 && <TooltipLine label={t('reports.invested')} value={formatMoney(period.invested, currency)} />}
              {before && (
                <>
                  <span className="mt-1 whitespace-nowrap border-t pt-1 text-xs text-muted-foreground">{periodLabel(before.key, by, locale, true)}</span>
                  <TooltipLine muted label={t('reports.income')} value={formatMoney(before.income, currency)} />
                  <TooltipLine muted label={t('reports.expenses')} value={formatMoney(before.expenses, currency)} />
                </>
              )}
            </>
          )
        }}
      />
      {periods.some((p) => p.partial) && <p className="mt-2 text-xs text-muted-foreground">{t('reports.partialNote')}</p>}
    </ReportCard>
  )
}

function NetWorthChart({ report }: { report: ReportData }) {
  const { t } = useTranslation()
  const currency = report.currencySymbol
  const days = useMemo(
    () => eachDayOfInterval({ start: parseISO(report.from), end: parseISO(report.to) }).map((day) => format(day, 'yyyy-MM-dd')),
    [report.from, report.to]
  )
  const wholeMoney = (value: number) => `${numberFormatter({ maximumFractionDigits: 0 }).format(value)}${currency ? ` ${currency}` : ''}`

  return (
    <ReportCard title={t('reports.netWorthTrend')}>
      <MultiLineChart
        days={days}
        series={[{ id: 'net-worth', label: t('reports.netWorth'), color: NET_WORTH_COLOR, points: report.netWorth }]}
        formatValue={(value) => formatMoney(value, currency)}
        formatTick={wholeMoney}
        legendColumns={1}
        height={260}
        emptyLabel={t('charts.noData')}
      />
    </ReportCard>
  )
}

/**
 * Income and expenses over any range, a column per month or per year,
 * optionally next to the range before or the same one a year earlier;
 * modelled on Firefly III's default report. Everything chosen is in the URL.
 */
export default function IncomeExpenseReport() {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const [searchParams, setSearchParams] = useSearchParams()
  const [openCell, setOpenCell] = useState<CategoryCell | null>(null)
  // Phones get lists instead of the wide tables.
  const isMobile = useIsMobile()
  const Categories = isMobile ? CategoryMobileList : CategoryTable
  const options = useMemo(() => readIncomeExpenseOptions(searchParams), [searchParams])

  // Nothing (valid) in the URL: the options last used here.
  useEffect(() => {
    if (!options) setSearchParams(new URLSearchParams(loadOptions()), { replace: true })
    else saveOptions(writeIncomeExpenseOptions(options).toString())
  }, [options, setSearchParams])

  const { data: dateRange } = useQuery({
    queryKey: ['transactions', 'date-range'],
    queryFn: () => transactionsApi.dateRange(),
    enabled: options?.preset === 'all',
  })

  const span: ReportSpan | null = useMemo(() => {
    if (!options) return null
    if (options.preset === 'all') {
      if (!dateRange) return null
      const today = new Date()
      return { preset: 'all', from: startOfMonth(dateRange.earliest ? new Date(dateRange.earliest) : today), to: endOfMonth(today) }
    }
    return options.from && options.to ? { preset: options.preset, from: options.from, to: options.to } : null
  }, [options, dateRange])

  const by: ReportGranularity = options?.by ?? (span ? defaultGranularity(span.from, span.to) : 'month')
  const compare: ReportComparison = options?.compare ?? 'none'
  const { data: report, isLoading, isPlaceholderData } = useIncomeExpenseReport(
    span ? toIsoDay(span.from) : null,
    span ? toIsoDay(span.to) : null,
    by,
    compare
  )

  const update = (patch: Partial<IncomeExpenseOptions>) => {
    if (options) setSearchParams(writeIncomeExpenseOptions({ ...options, ...patch }))
  }
  // A new range picks its columns again (by month up to two years); the arrows keep them.
  const choosePreset = (preset: ReportPreset) =>
    update(isRollingPreset(preset) ? { preset, ...rangeForReportPreset(preset), by: null } : { preset, from: null, to: null, by: null })
  const chooseRange = (from: Date, to: Date) => update({ preset: 'custom', from, to, by: null })
  const shift = (direction: -1 | 1) => {
    if (span) update(shiftReportSpan(span, direction))
  }

  const comparisonLabel = report?.comparison ? formatReportRange(parseISO(report.comparison.from), parseISO(report.comparison.to), locale) : null

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={t('reports.incomeExpenses.title')}
        description={
          span
            ? `${formatReportRange(span.from, span.to, locale)}${comparisonLabel ? ` · ${t('reports.comparedWith', { range: comparisonLabel })}` : ''}`
            : t('reports.incomeExpenses.description')
        }
      />
      <ReportSectionTabs />

      <div className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border bg-card p-3">
        {span ? <ReportPeriodPicker span={span} onPreset={choosePreset} onRange={chooseRange} onShift={shift} /> : <Skeleton className="h-9 w-56" />}
        <PillGroup
          label={t('reports.columns')}
          value={by}
          onChange={(value) => update({ by: value })}
          options={[
            { value: 'month', label: t('reports.byMonth') },
            { value: 'year', label: t('reports.byYear') },
          ]}
        />
        <PillGroup
          label={t('reports.compare')}
          value={compare}
          onChange={(value) => update({ compare: value })}
          options={[
            { value: 'none', label: t('reports.compareNone') },
            { value: 'previous', label: t('reports.comparePrevious') },
            { value: 'lastYear', label: t('reports.compareLastYear') },
          ]}
        />
      </div>

      {isLoading || !report ? (
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
          <SummaryCards report={report} />
          <IncomeExpenseChart report={report} />

          <div className="grid gap-4 md:gap-6 xl:grid-cols-2">
            {isMobile ? (
              <ReportAccountsList accounts={report.accounts} summary={report.summary} currency={report.currencySymbol} />
            ) : (
              <ReportCard title={t('reports.accounts')}>
                <ReportAccountsTable accounts={report.accounts} summary={report.summary} currency={report.currencySymbol} />
              </ReportCard>
            )}
            <NetWorthChart report={report} />
          </div>

          <Categories
            title={t('reports.expensesByCategory')}
            flow="expense"
            table={report.expenses}
            periods={report.periods}
            by={report.by}
            comparison={report.comparison}
            from={report.from}
            to={report.to}
            currency={report.currencySymbol}
            onOpen={setOpenCell}
          />
          <Categories
            title={t('reports.incomeByCategory')}
            flow="income"
            table={report.income}
            periods={report.periods}
            by={report.by}
            comparison={report.comparison}
            from={report.from}
            to={report.to}
            currency={report.currencySymbol}
            onOpen={setOpenCell}
          />
        </div>
      )}

      <CategoryTransactionsDialog cell={openCell} currency={report?.currencySymbol ?? null} onClose={() => setOpenCell(null)} />
    </div>
  )
}
