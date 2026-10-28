import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format, parseISO } from 'date-fns'
import { Landmark, LineChart, PiggyBank, TrendingUp } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import StatCard from '@/components/StatCard'
import PillGroup from '@/components/ui/pill-group'
import { Skeleton } from '@/components/ui/skeleton'
import { ReportSectionTabs } from '@/components/layout/SectionTabs'
import GroupedBarChart, { BarLegend } from '@/components/reports/GroupedBarChart'
import MonthlyLineChart from '@/components/reports/MonthlyLineChart'
import NetWorthTable from '@/components/reports/NetWorthTable'
import NetWorthMonthList from '@/components/reports/NetWorthMonthList'
import { useIsMobile } from '@/hooks/use-media-query'
import type { NetWorthView } from '@/components/reports/net-worth-lines'
import { ReportCard, TooltipLine } from '@/components/reports/report-ui'
import { useNetWorthReport } from '@/hooks/use-reports'
import { OTHER_COLOR } from '@/lib/chart'
import { dateLocale, signedMoney } from '@/lib/report-format'
import { monthlyChanges, monthlyValues, withOpening } from '@/lib/net-worth'
import { cn, formatMoney, formatPercent, moneyColorClass } from '@/lib/utils'
import type { NetWorthReport as ReportData } from '@/types/report'
import LoadFailed from '@/components/LoadFailed'
import Stepper from '@/components/Stepper'

const CURRENT_COLOR = 'hsl(var(--series-1))'
const PREVIOUS_COLOR = OTHER_COLOR
const CHOICES_KEY = 'firefunds-report-net-worth'

/** View and comparison last chosen, remembered in this browser only. */
function loadChoices(): { view: NetWorthView; compare: boolean } {
  try {
    const stored = new URLSearchParams(localStorage.getItem(CHOICES_KEY) ?? '')
    return { view: stored.get('view') === 'changes' ? 'changes' : 'values', compare: stored.get('compare') === '1' }
  } catch {
    return { view: 'values', compare: false }
  }
}

function saveChoices(view: NetWorthView, compare: boolean): void {
  try {
    localStorage.setItem(CHOICES_KEY, new URLSearchParams({ view, compare: compare ? '1' : '0' }).toString())
  } catch {
    // Not remembered: nothing else to do.
  }
}

/** Sum of the months gone by. */
function total(values: (number | null)[]): number {
  return values.reduce<number>((sum, value) => sum + (value ?? 0), 0)
}

/** The months of the year gone by (the current one included), as in the year before for a fair comparison. */
function elapsed(report: ReportData): number {
  return report.months.filter((month) => month.date !== null).length
}

function SummaryCards({ report, compare }: { report: ReportData; compare: boolean }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = report.currencySymbol
  const months = elapsed(report)
  const last = months - 1
  const values = monthlyValues(report.total)
  const opening = values.previousValues[11]
  const end = last >= 0 ? values.values[last] : null
  const change = end !== null && opening !== null ? end - opening : null
  const saved = total(monthlyValues(report.saved).values)
  const market = total(monthlyValues(report.market).values)

  // The year before over the same months.
  const before = monthlyValues(report.total)
  const beforeChange =
    last >= 0 && before.previousValues[last] !== null && report.total.previousOpening !== null
      ? (before.previousValues[last] as number) - Number(report.total.previousOpening)
      : null
  const beforeSaved = total(monthlyValues(report.saved).previousValues.slice(0, months))
  const beforeMarket = total(monthlyValues(report.market).previousValues.slice(0, months))
  const versus = (amount: number | null) => (compare && amount !== null ? t('reports.netWorthReport.versusYear', { year: report.year - 1, amount: signedMoney(amount, currency) }) : null)

  const lastDate = last >= 0 ? report.months[last].date : null

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard
        title={t('reports.netWorth')}
        value={end !== null ? formatMoney(end, currency) : '—'}
        icon={Landmark}
        iconColor="yellow"
        detail={lastDate ? t('reports.netWorthReport.on', { date: format(parseISO(lastDate), 'd MMMM yyyy', { locale }) }) : t('reports.netWorthReport.yearToCome')}
      />
      <StatCard
        title={t('reports.netWorthReport.sinceYearStart')}
        value={change !== null ? signedMoney(change, currency) : '—'}
        valueClassName={change !== null ? moneyColorClass(change) : undefined}
        icon={TrendingUp}
        iconColor="blue"
        detail={
          versus(beforeChange) ??
          (change !== null && opening ? `${change > 0 ? '+' : ''}${formatPercent((change / Math.abs(opening)) * 100, 1)}` : null)
        }
      />
      <StatCard
        title={t('reports.netWorthReport.fromSaving')}
        value={signedMoney(saved, currency)}
        valueClassName={moneyColorClass(saved)}
        icon={PiggyBank}
        iconColor="green"
        detail={versus(beforeSaved) ?? t('reports.netWorthReport.savingHint')}
      />
      <StatCard
        title={t('reports.netWorthReport.fromMarket')}
        value={signedMoney(market, currency)}
        valueClassName={moneyColorClass(market)}
        icon={LineChart}
        iconColor="orange"
        detail={versus(beforeMarket) ?? t('reports.netWorthReport.marketHint')}
      />
    </div>
  )
}

function TrendChart({ report, view, compare }: { report: ReportData; view: NetWorthView; compare: boolean }) {
  const { t, i18n } = useTranslation()
  const locale = dateLocale(i18n.language)
  const currency = report.currencySymbol
  const labels = report.months.map((month) => format(parseISO(`${month.month}-01`), 'LLL', { locale }))
  const monthTitle = (index: number, year: number) => {
    const label = format(parseISO(`${year}-${String(index + 1).padStart(2, '0')}-01`), 'LLLL yyyy', { locale })
    return label.charAt(0).toUpperCase() + label.slice(1)
  }

  const values = monthlyValues(report.total)
  const changes = monthlyChanges(withOpening(report.total))
  const saved = monthlyValues(report.saved)
  const market = monthlyValues(report.market)
  const data = view === 'values' ? values : changes

  const legend = [
    { id: 'current', label: String(report.year), color: CURRENT_COLOR },
    ...(compare ? [{ id: 'previous', label: String(report.year - 1), color: PREVIOUS_COLOR }] : []),
  ]

  const tooltip = (index: number) => {
    const current = data.values[index]
    const previous = data.previousValues[index]
    const amount = (value: number) => (view === 'values' ? formatMoney(value, currency) : signedMoney(value, currency))
    return (
      <>
        <span className="whitespace-nowrap text-xs text-muted-foreground">{monthTitle(index, report.year)}</span>
        {current !== null ? (
          <TooltipLine color={CURRENT_COLOR} label={String(report.year)} value={amount(current)} />
        ) : (
          <span className="text-xs text-muted-foreground">{t('reports.netWorthReport.toCome')}</span>
        )}
        {view === 'changes' && current !== null && (
          <>
            <TooltipLine label={t('reports.netWorthReport.fromSaving')} value={signedMoney(saved.values[index] ?? 0, currency)} />
            <TooltipLine label={t('reports.netWorthReport.fromMarket')} value={signedMoney(market.values[index] ?? 0, currency)} />
          </>
        )}
        {compare && previous !== null && (
          <>
            <TooltipLine muted color={PREVIOUS_COLOR} label={String(report.year - 1)} value={amount(previous)} />
            {current !== null && (
              <span className="whitespace-nowrap text-xs">
                <span className="text-muted-foreground">{t('reports.difference')}: </span>
                <span className={cn('font-semibold tabular-nums', moneyColorClass(current - previous))}>{signedMoney(current - previous, currency)}</span>
              </span>
            )}
          </>
        )}
      </>
    )
  }

  return (
    <ReportCard title={view === 'values' ? t('reports.netWorthTrend') : t('reports.netWorthReport.monthlyChange')} actions={<BarLegend series={legend} />}>
      {view === 'values' ? (
        <MonthlyLineChart
          labels={labels}
          series={[
            ...(compare ? [{ id: 'previous', color: PREVIOUS_COLOR, values: data.previousValues, dashed: true }] : []),
            { id: 'current', color: CURRENT_COLOR, values: data.values },
          ]}
          currency={currency}
          emptyLabel={t('charts.noData')}
          tooltip={tooltip}
        />
      ) : (
        <GroupedBarChart
          labels={labels}
          series={[
            { id: 'current', label: String(report.year), color: CURRENT_COLOR, values: data.values },
            ...(compare ? [{ id: 'previous', label: String(report.year - 1), color: PREVIOUS_COLOR, values: data.previousValues }] : []),
          ]}
          currency={currency}
          emptyLabel={t('charts.noData')}
          tooltip={tooltip}
        />
      )}
    </ReportCard>
  )
}

/**
 * What every asset was worth at the end of each month of a year, like a net
 * worth spreadsheet filled in by itself; the year before can sit next to it.
 * Year, view and comparison are in the URL.
 */
export default function NetWorthReport() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const remembered = useMemo(loadChoices, [])
  const currentYear = new Date().getFullYear()
  // Phones: a month at a time instead of the twelve columns.
  const isMobile = useIsMobile()

  const year = Number(searchParams.get('year')) || currentYear
  const view: NetWorthView = searchParams.has('view') ? (searchParams.get('view') === 'changes' ? 'changes' : 'values') : remembered.view
  const compare = searchParams.has('compare') ? searchParams.get('compare') === '1' : remembered.compare
  const { data: report, isLoading, isPlaceholderData, error, refetch } = useNetWorthReport(year)

  const update = (next: { year?: number; view?: NetWorthView; compare?: boolean }) => {
    const chosen = { year, view, compare, ...next }
    saveChoices(chosen.view, chosen.compare)
    setSearchParams(new URLSearchParams({ year: String(chosen.year), view: chosen.view, compare: chosen.compare ? '1' : '0' }))
  }

  const firstYear = report?.firstYear ?? year
  const lastYear = report?.currentYear ?? currentYear

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader title={t('reports.netWorthReport.title')} description={t('reports.netWorthReport.description')} />
      <ReportSectionTabs />

      <div className="flex flex-wrap items-center gap-x-6 gap-y-3 surface p-3">
        <Stepper
          previousLabel={t('reports.netWorthReport.previousYear')}
          nextLabel={t('reports.netWorthReport.nextYear')}
          previousDisabled={year <= firstYear}
          nextDisabled={year >= lastYear}
          onPrevious={() => update({ year: year - 1 })}
          onNext={() => update({ year: year + 1 })}
        >
          {year}
        </Stepper>
        <PillGroup
          label={t('reports.netWorthReport.show')}
          value={view}
          onChange={(value) => update({ view: value })}
          options={[
            { value: 'values', label: t('reports.netWorthReport.values') },
            { value: 'changes', label: t('reports.netWorthReport.changes') },
          ]}
        />
        <PillGroup
          label={t('reports.compare')}
          value={compare ? 'previous' : 'none'}
          onChange={(value) => update({ compare: value === 'previous' })}
          options={[
            { value: 'none', label: t('reports.compareNone') },
            { value: 'previous', label: String(year - 1) },
          ]}
        />
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
          <SummaryCards report={report} compare={compare} />
          <TrendChart report={report} view={view} compare={compare} />
          {isMobile ? (
            <NetWorthMonthList report={report} view={view} compare={compare} />
          ) : (
            <section className="min-w-0 surface pb-2">
              <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4">
                <h3 className="section-title">
                  {view === 'values' ? t('reports.netWorthReport.tableValues') : t('reports.netWorthReport.tableChanges')}
                </h3>
                <span className="text-xs text-muted-foreground">{t('reports.netWorthReport.tableHint')}</span>
              </div>
              <NetWorthTable report={report} view={view} compare={compare} />
            </section>
          )}
        </div>
      )}
    </div>
  )
}
