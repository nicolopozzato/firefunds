import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { CalendarRange, SearchX, Search as SearchIcon } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FilterBar from '@/components/FilterBar'
import PillGroup from '@/components/ui/pill-group'
import TransactionList from '@/components/TransactionList'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { useSearchResults, useSearchTotals } from '@/hooks/use-search'
import { searchTerms } from '@/lib/search'
import { cn, formatMoney } from '@/lib/utils'
import type { SearchKind, TransactionTotals } from '@/types/search'

const KINDS: SearchKind[] = ['all', 'withdrawal', 'deposit', 'transfer', 'trade']

/** Each total in the summary: money leaving shows as negative, transfers are neutral. */
const TOTALS: { key: keyof TransactionTotals; sign: -1 | 0 | 1 }[] = [
  { key: 'withdrawal', sign: -1 },
  { key: 'deposit', sign: 1 },
  { key: 'transfer', sign: 0 },
  { key: 'investmentBuy', sign: -1 },
  { key: 'investmentSell', sign: 1 },
]

function EmptyState({ icon: Icon, title, description }: { icon: typeof SearchX; title: string; description: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 text-center">
      <div className="mb-4 rounded-full bg-muted p-4">
        <Icon className="h-8 w-8 text-muted-foreground" />
      </div>
      <h3 className="mb-2 text-lg font-semibold">{title}</h3>
      <p className="max-w-md text-sm text-muted-foreground">{description}</p>
    </div>
  )
}

/**
 * The transactions found by the search box, over every date unless narrowed
 * to the selected period. What is searched, the kind, the period and the page
 * are in the URL, so going back from a transaction returns here as it was.
 */
export default function Search() {
  const { t, i18n } = useTranslation()
  const { timeframe } = useTimeframe()
  const [searchParams, setSearchParams] = useSearchParams()

  const q = searchParams.get('q') ?? ''
  const kindParam = searchParams.get('kind') as SearchKind | null
  const kind: SearchKind = kindParam && KINDS.includes(kindParam) ? kindParam : 'all'
  const inPeriod = searchParams.get('period') === 'selected'
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const terms = searchTerms(q)

  const { data, isLoading } = useSearchResults(q, kind, page, inPeriod)
  const { data: totals } = useSearchTotals(q, kind, inPeriod)

  /** Changes one filter of the URL; any change but the page goes back to the first page. */
  const update = (key: 'kind' | 'period' | 'page', value: string | null) => {
    const next = new URLSearchParams(searchParams)
    if (value === null) next.delete(key)
    else next.set(key, value)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  const locale = i18n.language === 'it' ? it : enUS
  const rangeLabel = t('transactionsCommon.rangeSuffix', {
    from: format(timeframe.from, 'd MMMM yyyy', { locale }),
    to: format(timeframe.to, 'd MMMM yyyy', { locale }),
  })

  if (q.trim() === '') {
    return (
      <div className="space-y-6">
        <PageHeader title={t('search.title')} />
        <Card>
          <CardContent className="p-0">
            <EmptyState icon={SearchIcon} title={t('search.noQuery')} description={t('search.noQueryDescription')} />
          </CardContent>
        </Card>
      </div>
    )
  }

  const shownTotals = totals
    ? TOTALS.map((total) => ({ ...total, amount: Number(totals[total.key]) })).filter(({ amount }) => amount !== 0)
    : []

  return (
    <div className="space-y-6">
      <PageHeader title={t('search.title')} description={t('search.resultsFor', { query: q.trim() })} />

      <FilterBar
        end={
          <PillGroup
            label={t('search.period')}
            options={[
              { value: 'all', label: t('search.period_all') },
              { value: 'selected', label: t('search.period_selected') },
            ]}
            value={inPeriod ? 'selected' : 'all'}
            onChange={(value) => update('period', value === 'selected' ? 'selected' : null)}
          />
        }
      >
        <PillGroup
          label={t('search.kind')}
          options={KINDS.map((value) => ({ value, label: t(`search.kind_${value}`) }))}
          value={kind}
          onChange={(value) => update('kind', value === 'all' ? null : value)}
        />
      </FilterBar>

      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <span className="flex items-center gap-2 text-muted-foreground">
          <CalendarRange className="h-4 w-4" />
          {inPeriod ? rangeLabel : t('search.allDates')}
        </span>
        {data ? (
          <span className="font-medium">{t('search.found', { count: data.total })}</span>
        ) : (
          <Skeleton className="h-4 w-32" />
        )}
        {shownTotals.map(({ key, sign, amount }) => (
          <span key={key} className="flex items-baseline gap-1.5">
            <span className="text-muted-foreground">{t(`search.total_${key}`)}</span>
            <span
              className={cn(
                'font-medium tabular-nums',
                sign < 0 && 'text-negative',
                sign > 0 && 'text-positive'
              )}
            >
              {formatMoney(sign < 0 ? -amount : amount)}
            </span>
          </span>
        ))}
      </div>

      <TransactionList
        data={data}
        isLoading={isLoading}
        onPageChange={(next) => update('page', String(next))}
        highlight={terms}
        empty={<EmptyState icon={SearchX} title={t('search.empty')} description={t('search.emptyDescription')} />}
      />
    </div>
  )
}
