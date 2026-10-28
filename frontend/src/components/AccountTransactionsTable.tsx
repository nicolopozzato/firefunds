import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, CalendarRange, ListFilter, Copy } from 'lucide-react'
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
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSection, MobileListSkeleton } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { groupByDay, useDayLabel } from '@/hooks/use-day-groups'
import DateWithTime from '@/components/DateWithTime'
import Pagination from '@/components/Pagination'
import TableSkeleton from '@/components/TableSkeleton'
import FilterBar from '@/components/FilterBar'
import PillGroup from '@/components/ui/pill-group'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { useTransactions, useDeleteTransaction } from '@/hooks/use-transactions'
import { useDeleteInvestmentTransaction } from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import AccountName from '@/components/AccountName'
import ClassificationLink from '@/components/ClassificationLink'
import { amountColorClass, amountCurrency, clonePath, detailPath, editPath, isInvestmentRow, signedAmount } from '@/lib/transaction-display'
import TransactionKindIcon from '@/components/TransactionKindIcon'
import { cn, currencySymbolFor, formatMoney, formatQuantity } from '@/lib/utils'
import type { Transaction, TransactionListKind } from '@/types/transaction'
import EmptyValue from '@/components/EmptyValue'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'
import EmptyState from '@/components/EmptyState'
import ConfirmDialog from '@/components/ConfirmDialog'

/** Investment accounts: every transaction, only the trades, or only the money moving in and out. */
type InvestmentListFilter = Extract<TransactionListKind, 'all' | 'trade' | 'cash'>
const INVESTMENT_LIST_FILTERS: InvestmentListFilter[] = ['all', 'trade', 'cash']

interface AccountTransactionsTableProps {
  accountId: string
  /**
   * Broker account: the single amount column becomes the trade ("3 × 150,22 €"),
   * fees, taxes and total, so BUY/SELL trades and plain money moving in or
   * out of the account read side by side (the trade cells stay empty for the
   * latter). No category column (trades never have one), and a filter for
   * trades only / cash only.
   */
  isInvestmentAccount?: boolean
}

export default function AccountTransactionsTable({ accountId, isInvestmentAccount = false }: AccountTransactionsTableProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { timeframe } = useTimeframe()
  const isMobile = useIsMobile()
  const dayLabel = useDayLabel()
  const [searchParams, setSearchParams] = useSearchParams()
  // In the URL, so it survives opening a transaction and coming back.
  const listFilter: InvestmentListFilter = isInvestmentAccount
    ? (INVESTMENT_LIST_FILTERS.find((value) => value === searchParams.get('transactions')) ?? 'all')
    : 'all'
  const { page, setPage, showAll, setShowAll } = useListPage([timeframe.from.getTime(), timeframe.to.getTime(), listFilter])
  const { data, isLoading, isPlaceholderData } = useTransactions(listFilter, page, showAll, accountId)
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const transactions = data?.items
  const deleteTransaction = useDeleteTransaction()
  const deleteInvestmentTransaction = useDeleteInvestmentTransaction()
  const [transactionToDelete, setTransactionToDelete] = useState<Transaction | null>(null)

  const setListFilter = (value: InvestmentListFilter) => {
    const next = new URLSearchParams(searchParams)
    if (value === 'all') next.delete('transactions')
    else next.set('transactions', value)
    setSearchParams(next, { replace: true })
  }

  const locale = i18n.language === 'it' ? it : enUS
  const rangeLabel = t('transactionsCommon.rangeSuffix', {
    from: format(timeframe.from, 'd MMMM yyyy', { locale }),
    to: format(timeframe.to, 'd MMMM yyyy', { locale }),
  })

  const handleConfirmDelete = async () => {
    if (!transactionToDelete) return
    try {
      if (isInvestmentRow(transactionToDelete)) {
        await deleteInvestmentTransaction.mutateAsync(transactionToDelete.id)
      } else {
        await deleteTransaction.mutateAsync(transactionToDelete.id)
      }
      setTransactionToDelete(null)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="space-y-4">
      <FilterBar
        end={
          <>
            {isInvestmentAccount && (
              <PillGroup
                label={t('accountDetail.transactionsFilter')}
                options={INVESTMENT_LIST_FILTERS.map((value) => ({
                  value,
                  label: t(`accountDetail.transactionsFilter_${value}`),
                }))}
                value={listFilter}
                onChange={setListFilter}
              />
            )}
            <button
              type="button"
              onClick={() => setShowAll(!showAll)}
              className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            >
              <ListFilter className="h-4 w-4" />
              {showAll ? t('transactionsCommon.showCurrentRange') : t('transactionsCommon.showAll')}
            </button>
          </>
        }
      >
        {/* Phones show the period in the top bar. */}
        <div className={cn('items-center gap-2 text-sm text-muted-foreground', showAll ? 'flex' : 'hidden md:flex')}>
          <CalendarRange className="h-4 w-4" />
          {showAll ? t('transactionsCommon.allRange') : rangeLabel}
        </div>
      </FilterBar>

      {/* Phones: a list by day; each row names the other side of the transaction. */}
      {isMobile ? (
        isLoading ? (
          <MobileListSkeleton rows={4} />
        ) : !transactions || transactions.length === 0 ? (
          <MobileListCard>
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">{t('accountDetail.noTransactionsTitle')}</p>
          </MobileListCard>
        ) : (
          <div className="space-y-4">
            {groupByDay(transactions, (transaction) => transaction.occurredAt).map((group) => (
              <MobileListSection key={group.day} title={dayLabel(group.date)}>
                {group.items.map((transaction) => {
                  const outgoing = transaction.sourceAccount?.id === accountId
                  const other = outgoing ? transaction.destinationAccount : transaction.sourceAccount
                  const trade =
                    isInvestmentRow(transaction) && transaction.quantity && transaction.unitPrice
                      ? `${formatQuantity(transaction.quantity)} × ${formatMoney(transaction.unitPrice, currencySymbolFor(transaction.unitPriceCurrency ?? transaction.currency))}`
                      : null
                  return (
                    <MobileListRow
                      key={transaction.id}
                      onClick={() => navigate(detailPath(transaction))}
                      leading={
                        <MobileListIcon>
                          <TransactionKindIcon transaction={transaction} />
                        </MobileListIcon>
                      }
                      title={transaction.description ?? <EmptyValue />}
                      subtitle={[
                        trade ?? `${outgoing ? '→' : '←'} ${other?.name ?? '—'}`,
                        transaction.categoryName,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                      value={formatMoney(signedAmount(transaction, accountId), currencySymbolFor(amountCurrency(transaction, accountId)))}
                      valueClassName={amountColorClass(transaction)}
                    />
                  )
                })}
              </MobileListSection>
            ))}
            {data && data.total > data.pageSize && (
              <MobileListCard className="[&>div]:border-t-0">
                <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
              </MobileListCard>
            )}
          </div>
        )
      ) : (
        <Card className="overflow-hidden">
          <CardContent className="p-0 sm:p-0">
            {isLoading ? (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[34px] pl-4 pr-0.5" />
                    <TableHead className="w-[26%]">{t('transactionsCommon.description')}</TableHead>
                    {isInvestmentAccount ? (
                      <>
                        <TableHead className="w-44 text-right">{t('investmentTransactions.operation')}</TableHead>
                        <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.fees')}</TableHead>
                        <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.taxes')}</TableHead>
                        <TableHead className="w-28 text-right">{t('investmentTransactions.total')}</TableHead>
                      </>
                    ) : (
                      <TableHead className="w-28 text-right">{t('transactionsCommon.amount')}</TableHead>
                    )}
                    <TableHead className="w-[6.75rem]">{t('transactionsCommon.date')}</TableHead>
                    <TableHead>{t('transactionsCommon.source')}</TableHead>
                    <TableHead>{t('transactionsCommon.destination')}</TableHead>
                    {!isInvestmentAccount && <TableHead className="hidden xl:table-cell">{t('transactionsCommon.category')}</TableHead>}
                    <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableSkeleton
                  columns={[
                    { cellClassName: 'pl-4 pr-0.5', skeletonClassName: 'h-4 w-4 rounded-full' },
                    { skeletonClassName: 'w-40' },
                    ...(isInvestmentAccount
                      ? [
                          { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-24' },
                          { cellClassName: 'hidden xl:table-cell text-right', skeletonClassName: 'ml-auto w-12' },
                          { cellClassName: 'hidden xl:table-cell text-right', skeletonClassName: 'ml-auto w-12' },
                        ]
                      : []),
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                    { skeletonClassName: 'w-24' },
                    { skeletonClassName: 'w-28' },
                    { skeletonClassName: 'w-28' },
                    ...(isInvestmentAccount ? [] : [{ cellClassName: 'hidden xl:table-cell', skeletonClassName: 'w-24' }]),
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                  ]}
                />
              </Table>
            ) : !transactions || transactions.length === 0 ? (
              <EmptyState title={t('accountDetail.noTransactionsTitle')} description={t('accountDetail.noTransactionsDescription')} />
            ) : (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[34px] pl-4 pr-0.5" />
                    <TableHead className="w-[26%]">{t('transactionsCommon.description')}</TableHead>
                    {isInvestmentAccount ? (
                      <>
                        <TableHead className="w-44 text-right">{t('investmentTransactions.operation')}</TableHead>
                        <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.fees')}</TableHead>
                        <TableHead className="hidden xl:table-cell w-24 text-right">{t('investmentTransactions.taxes')}</TableHead>
                        <TableHead className="w-28 text-right">{t('investmentTransactions.total')}</TableHead>
                      </>
                    ) : (
                      <TableHead className="w-28 text-right">{t('transactionsCommon.amount')}</TableHead>
                    )}
                    <TableHead className="w-[6.75rem]">{t('transactionsCommon.date')}</TableHead>
                    <TableHead>{t('transactionsCommon.source')}</TableHead>
                    <TableHead>{t('transactionsCommon.destination')}</TableHead>
                    {!isInvestmentAccount && <TableHead className="hidden xl:table-cell">{t('transactionsCommon.category')}</TableHead>}
                    <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {transactions.map((transaction) => (
                    <TableRow
                      key={transaction.id}
                      className="cursor-pointer"
                      onClick={() => navigate(detailPath(transaction))}
                    >
                      <TableCell className="pl-4 pr-0.5">
                        <TransactionKindIcon transaction={transaction} />
                      </TableCell>
                      <TableCell className="font-medium">
                        <span className="cell-text" title={transaction.description ?? undefined}>{transaction.description ?? <EmptyValue />}</span>
                      </TableCell>
                      {isInvestmentAccount && (
                        <>
                          {/* Trade cells stay empty on money rows: nothing to show there. */}
                          <TableCell className="text-right tabular-nums">
                            <span className="cell-text">
                              {transaction.quantity !== null && transaction.unitPrice !== null &&
                                `${formatQuantity(transaction.quantity)} × ${formatMoney(transaction.unitPrice, currencySymbolFor(transaction.unitPriceCurrency ?? transaction.currency))}`}
                            </span>
                          </TableCell>
                          <TableCell className="hidden xl:table-cell text-right tabular-nums text-muted-foreground">
                            {transaction.fees !== null && formatMoney(transaction.fees, currencySymbolFor(transaction.currency))}
                          </TableCell>
                          <TableCell className="hidden xl:table-cell text-right tabular-nums text-muted-foreground">
                            {transaction.taxes !== null && formatMoney(transaction.taxes, currencySymbolFor(transaction.currency))}
                          </TableCell>
                        </>
                      )}
                      <TableCell className={cn('text-right tabular-nums', isInvestmentAccount && 'font-medium', amountColorClass(transaction))}>
                        {formatMoney(signedAmount(transaction, accountId), currencySymbolFor(amountCurrency(transaction, accountId)))}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        <DateWithTime date={transaction.occurredAt} />
                      </TableCell>
                      <TableCell><span className="cell-text"><AccountName account={transaction.sourceAccount} link /></span></TableCell>
                      <TableCell><span className="cell-text"><AccountName account={transaction.destinationAccount} link /></span></TableCell>
                      {!isInvestmentAccount && (
                        <TableCell className="hidden xl:table-cell text-muted-foreground"><span className="cell-text">
                          {transaction.categoryName && transaction.categoryId && (
                            <ClassificationLink to={`/categories/${transaction.categoryId}`}>{transaction.categoryName}</ClassificationLink>
                          )}
                        </span></TableCell>
                      )}
                      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={t('common.moreActions')}>
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => navigate(editPath(transaction))}>
                              <Pencil className="mr-2 h-4 w-4" />
                              {t('common.edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => navigate(clonePath(transaction))}>
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

      <ConfirmDialog
        open={!!transactionToDelete}
        onOpenChange={(open) => !open && setTransactionToDelete(null)}
        title={t('transactionsCommon.deleteConfirmTitle')}
        description={t('transactionsCommon.deleteConfirmDescription')}
        onConfirm={handleConfirmDelete}
        pending={deleteTransaction.isPending || deleteInvestmentTransaction.isPending}
      />
    </div>
  )
}
