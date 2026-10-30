import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { Plus, CalendarRange, ListFilter } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import DraftInboxButton from '@/components/DraftInboxButton'
import TransactionList from '@/components/TransactionList'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { Button } from '@/components/ui/button'
import PieChart from '@/components/PieChart'
import PieChartSkeleton from '@/components/PieChartSkeleton'
import { useTransactions, useTransactionDistributions } from '@/hooks/use-transactions'
import { TransactionSectionTabs } from '@/components/layout/SectionTabs'
import { useIsMobile } from '@/hooks/use-media-query'
import { cn } from '@/lib/utils'
import type { TransactionKind } from '@/types/transaction'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'
import EmptyState from '@/components/EmptyState'

interface TransactionsTableProps {
  kind: TransactionKind
  title: string
  description: string
  emptyTitle: string
  emptyDescription: string
}

export default function TransactionsTable({
  kind,
  title,
  description,
  emptyTitle,
  emptyDescription,
}: TransactionsTableProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { timeframe } = useTimeframe()
  const isMobile = useIsMobile()
  const { page, setPage, showAll, setShowAll } = useListPage([timeframe.from.getTime(), timeframe.to.getTime()])
  const { data, isLoading, isPlaceholderData } = useTransactions(kind, page, showAll)
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const transactions = data?.items
  const { data: distributions } = useTransactionDistributions(kind, !showAll)

  const locale = i18n.language === 'it' ? it : enUS
  const rangeLabel = t('transactionsCommon.rangeSuffix', {
    from: format(timeframe.from, 'd MMMM yyyy', { locale }),
    to: format(timeframe.to, 'd MMMM yyyy', { locale }),
  })

  const newTransactionPath = kind === 'all' ? '/transactions/new' : `/transactions/new?type=${kind}`

  // Phones: the list comes first and the pies follow it, side by side in a row to swipe.
  const chartsClass = isMobile
    ? '-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&>*]:w-[calc(100%-1.5rem)] [&>*]:shrink-0 [&>*]:snap-center'
    : 'grid gap-4 sm:grid-cols-2 xl:grid-cols-3'

  const charts =
    !showAll &&
    (isLoading ? (
      <div className={chartsClass}>
        <PieChartSkeleton />
        <PieChartSkeleton />
        <PieChartSkeleton />
      </div>
    ) : (
      transactions &&
      transactions.length > 0 && (
        <div className={chartsClass}>
          <PieChart
            title={t('charts.categories')}
            entries={distributions?.categories ?? []}
            emptyLabel={t('charts.noData')}
          />
          <PieChart
            title={t('charts.sourceAccounts')}
            entries={distributions?.sourceAccounts ?? []}
            emptyLabel={t('charts.noData')}
            nullLabel="—"
          />
          <PieChart
            title={t('charts.destinationAccounts')}
            entries={distributions?.destinationAccounts ?? []}
            emptyLabel={t('charts.noData')}
            nullLabel="—"
          />
        </div>
      )
    ))

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={title}
        description={description}
        compactActions
        actions={
          <>
            <DraftInboxButton />
            {/* Phones add from the bottom bar's "+". */}
            <Button onClick={() => navigate(newTransactionPath)} className="hidden md:inline-flex">
              <Plus className="mr-2 h-4 w-4" />
              {t('transactionsCommon.add')}
            </Button>
          </>
        }
      />

      <TransactionSectionTabs />

      {/* Phones change the period in the top bar ("All" included): the row is only there to leave "every date". */}
      <div className={cn('flex-wrap items-center justify-between gap-2', showAll ? 'flex' : 'hidden md:flex')}>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <CalendarRange className="h-4 w-4" />
          {showAll ? t('transactionsCommon.allRange') : rangeLabel}
        </div>
        <button
          type="button"
          onClick={() => setShowAll(!showAll)}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
          <ListFilter className="h-4 w-4" />
          {showAll ? t('transactionsCommon.showCurrentRange') : t('transactionsCommon.showAll')}
        </button>
      </div>

      {!isMobile && charts}

      <TransactionList
        data={data}
        isLoading={isLoading}
        onPageChange={setPage}
        empty={
          <EmptyState title={emptyTitle} description={emptyDescription} action={{ label: t('transactionsCommon.add'), onClick: () => navigate(newTransactionPath) }} />
        }
      />

      {isMobile && charts}

      {showAll && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => setShowAll(false)}
            className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
          >
            <ListFilter className="h-4 w-4" />
            {t('transactionsCommon.showCurrentRange')}
          </button>
        </div>
      )}
    </div>
  )
}
