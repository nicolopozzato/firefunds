import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Plus, ArrowDownToLine, ArrowUpToLine, Copy, CalendarRange, ListFilter } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import Pagination from '@/components/Pagination'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import DateWithTime from '@/components/DateWithTime'
import PieChart from '@/components/PieChart'
import PieChartSkeleton from '@/components/PieChartSkeleton'
import TableSkeleton from '@/components/TableSkeleton'
import {
  useInvestmentTransactions,
  useInvestmentTransactionDistributions,
  useDeleteInvestmentTransaction,
} from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import { cn, currencySymbolFor, formatMoney } from '@/lib/utils'
import type { InvestmentTransaction } from '@/types/investment'
import { TransactionSectionTabs } from '@/components/layout/SectionTabs'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSection, MobileListSkeleton } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { groupByDay, useDayLabel } from '@/hooks/use-day-groups'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'
import EmptyState from '@/components/EmptyState'
import ConfirmDialog from '@/components/ConfirmDialog'

const basePath = '/transactions/investments'

function TransactionIcon({ transaction }: { transaction: InvestmentTransaction }) {
  return transaction.type === 'buy' ? (
    <ArrowDownToLine className="h-4 w-4 text-negative" />
  ) : (
    <ArrowUpToLine className="h-4 w-4 text-positive" />
  )
}

function signedTotal(transaction: InvestmentTransaction): number {
  return transaction.type === 'buy' ? -Number(transaction.totalAmount) : Number(transaction.totalAmount)
}

export default function InvestmentTransactionsTable() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { timeframe } = useTimeframe()
  const isMobile = useIsMobile()
  const dayLabel = useDayLabel()
  const { page, setPage, showAll, setShowAll } = useListPage([timeframe.from.getTime(), timeframe.to.getTime()])
  const { data, isLoading, isPlaceholderData } = useInvestmentTransactions(page, showAll)
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const transactions = data?.items
  const { data: distributions } = useInvestmentTransactionDistributions(!showAll)
  const deleteTransaction = useDeleteInvestmentTransaction()
  const [transactionToDelete, setTransactionToDelete] = useState<InvestmentTransaction | null>(null)

  const locale = i18n.language === 'it' ? it : enUS
  const rangeLabel = t('transactionsCommon.rangeSuffix', {
    from: format(timeframe.from, 'd MMMM yyyy', { locale }),
    to: format(timeframe.to, 'd MMMM yyyy', { locale }),
  })

  const handleConfirmDelete = async () => {
    if (!transactionToDelete) return
    try {
      await deleteTransaction.mutateAsync(transactionToDelete.id)
      setTransactionToDelete(null)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  // Phones: the list comes first and the pies follow it, side by side in a row to swipe.
  const chartsClass = isMobile
    ? '-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&>*]:w-[calc(100%-1.5rem)] [&>*]:shrink-0 [&>*]:snap-center'
    : 'grid gap-4 sm:grid-cols-2 xl:grid-cols-3'

  const charts = (
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
                title={t('charts.instrumentTypes')}
                entries={(distributions?.instrumentTypes ?? []).map((entry) => ({
                  ...entry,
                  name: t(`investmentInstruments.type_${entry.id}`),
                }))}
                emptyLabel={t('charts.noData')}
              />
              <PieChart
                title={t('charts.instruments')}
                entries={distributions?.instruments ?? []}
                emptyLabel={t('charts.noData')}
              />
              <PieChart
                title={t('charts.accounts')}
                entries={distributions?.accounts ?? []}
                emptyLabel={t('charts.noData')}
              />
            </div>
          )
        ))
  )

  const mobileList = isLoading ? (
    <MobileListSkeleton />
  ) : !transactions || transactions.length === 0 ? (
    <MobileListCard>
      <p className="px-4 py-10 text-center text-sm text-muted-foreground">{t('investmentTransactions.empty')}</p>
    </MobileListCard>
  ) : (
    <div className="space-y-4">
      {groupByDay(transactions, (transaction) => transaction.occurredAt).map((group) => (
        <MobileListSection key={group.day} title={dayLabel(group.date)}>
          {group.items.map((transaction) => (
            <MobileListRow
              key={transaction.id}
              onClick={() => navigate(`${basePath}/${transaction.id}`)}
              leading={
                <MobileListIcon>
                  <TransactionIcon transaction={transaction} />
                </MobileListIcon>
              }
              title={transaction.instrumentTicker ?? transaction.instrumentName}
              subtitle={`${transaction.account.name} · ${transaction.quantity} × ${formatMoney(transaction.unitPrice, currencySymbolFor(transaction.unitPriceCurrency))}`}
              value={formatMoney(signedTotal(transaction), currencySymbolFor(transaction.currency))}
              valueClassName={transaction.type === 'buy' ? 'text-negative' : 'text-positive'}
            />
          ))}
        </MobileListSection>
      ))}
      {data && data.total > data.pageSize && (
        <MobileListCard className="[&>div]:border-t-0">
          <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
        </MobileListCard>
      )}
    </div>
  )

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={t('investmentTransactions.title')}
        description={t('investmentTransactions.description')}
        actions={
          // Phones add from the bottom bar's "+".
          <Button onClick={() => navigate(`${basePath}/new`)} className="hidden md:inline-flex">
            <Plus className="mr-2 h-4 w-4" />
            {t('investmentTransactions.add')}
          </Button>
        }
      />

      <TransactionSectionTabs />

      <div className="flex flex-wrap items-center justify-between gap-2">
        {/* Phones show the period in the top bar. */}
        <div className={cn('items-center gap-2 text-sm text-muted-foreground', showAll ? 'flex' : 'hidden md:flex')}>
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

      {isMobile ? mobileList : (
      <Card className="overflow-hidden">
        <CardContent className="p-0 sm:p-0">
          {isLoading ? (
            <Table className="table-fixed">
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[34px] pl-4 pr-0.5" />
                  <TableHead className="w-[26%]">{t('investmentTransactions.instrument')}</TableHead>
                  <TableHead className="w-28 text-right">{t('transactionsCommon.amount')}</TableHead>
                  <TableHead className="w-[6.75rem]">{t('investmentTransactions.date')}</TableHead>
                  <TableHead>{t('investmentTransactions.account')}</TableHead>
                  <TableHead className="w-28 text-right">{t('investmentTransactions.quantity')}</TableHead>
                  <TableHead className="w-28 text-right">{t('investmentTransactions.unitPrice')}</TableHead>
                  <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.fees')}</TableHead>
                  <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.taxes')}</TableHead>
                  <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                </TableRow>
              </TableHeader>
              <TableSkeleton
                columns={[
                  { cellClassName: 'pl-4 pr-0.5', skeletonClassName: 'h-4 w-4 rounded-full' },
                  { skeletonClassName: 'w-32' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                  { skeletonClassName: 'w-24' },
                  { skeletonClassName: 'w-28' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-16' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-16' },
                  { cellClassName: 'hidden xl:table-cell text-right', skeletonClassName: 'ml-auto w-16' },
                  { cellClassName: 'hidden xl:table-cell text-right', skeletonClassName: 'ml-auto w-16' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                ]}
              />
            </Table>
          ) : !transactions || transactions.length === 0 ? (
            <EmptyState title={t('investmentTransactions.empty')} description={t('investmentTransactions.emptyDescription')} action={{ label: t('investmentTransactions.add'), onClick: () => navigate(`${basePath}/new`) }} />
          ) : (
            <Table className="table-fixed">
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[34px] pl-4 pr-0.5" />
                  <TableHead className="w-[26%]">{t('investmentTransactions.instrument')}</TableHead>
                  <TableHead className="w-28 text-right">{t('transactionsCommon.amount')}</TableHead>
                  <TableHead className="w-[6.75rem]">{t('investmentTransactions.date')}</TableHead>
                  <TableHead>{t('investmentTransactions.account')}</TableHead>
                  <TableHead className="w-28 text-right">{t('investmentTransactions.quantity')}</TableHead>
                  <TableHead className="w-28 text-right">{t('investmentTransactions.unitPrice')}</TableHead>
                  <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.fees')}</TableHead>
                  <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.taxes')}</TableHead>
                  <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions.map((transaction) => (
                  <TableRow
                    key={transaction.id}
                    className="cursor-pointer"
                    onClick={() => navigate(`${basePath}/${transaction.id}`)}
                  >
                    <TableCell className="pl-4 pr-0.5">
                      <TransactionIcon transaction={transaction} />
                    </TableCell>
                    <TableCell className="font-medium">
                      <span className="cell-text" title={transaction.instrumentName}>
                        {transaction.instrumentName}
                        {transaction.instrumentTicker && <span className="ml-1.5 text-xs text-muted-foreground">{transaction.instrumentTicker}</span>}
                      </span>
                    </TableCell>
                    <TableCell
                      className={cn(
                        'text-right tabular-nums',
                        transaction.type === 'buy' ? 'text-negative' : 'text-positive'
                      )}
                    >
                      {formatMoney(signedTotal(transaction), currencySymbolFor(transaction.currency))}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      <DateWithTime date={transaction.occurredAt} />
                    </TableCell>
                    <TableCell><span className="cell-text">{transaction.account.name}</span></TableCell>
                    <TableCell className="text-right tabular-nums">{transaction.quantity}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatMoney(transaction.unitPrice, currencySymbolFor(transaction.unitPriceCurrency))}</TableCell>
                    <TableCell className="hidden xl:table-cell text-right tabular-nums">{formatMoney(transaction.fees)}</TableCell>
                    <TableCell className="hidden xl:table-cell text-right tabular-nums">{formatMoney(transaction.taxes)}</TableCell>
                    <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" aria-label={t('common.moreActions')}>
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => navigate(`${basePath}/${transaction.id}/edit`)}>
                            <Pencil className="mr-2 h-4 w-4" />
                            {t('common.edit')}
                          </DropdownMenuItem>
                          <DropdownMenuItem onClick={() => navigate(`${basePath}/new?cloneFrom=${transaction.id}`)}>
                            <Copy className="mr-2 h-4 w-4" />
                            {t('transactionsCommon.clone')}
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => setTransactionToDelete(transaction)}
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {t('common.delete')}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
        {data && (
          <Pagination
            page={data.page}
            pageSize={data.pageSize}
            total={data.total}
            onPageChange={setPage}
          />
        )}
      </Card>
      )}

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

      <ConfirmDialog
        open={!!transactionToDelete}
        onOpenChange={(open) => !open && setTransactionToDelete(null)}
        title={t('investmentTransactions.deleteConfirmTitle')}
        description={t('investmentTransactions.deleteConfirmDescription')}
        onConfirm={handleConfirmDelete}
        pending={deleteTransaction.isPending}
      />
    </div>
  )
}
