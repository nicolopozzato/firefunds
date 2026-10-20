import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Plus, ArrowUpCircle, ArrowDownCircle, Repeat, CalendarRange, ListFilter, Copy, ArrowDownToLine, ArrowUpToLine } from 'lucide-react'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { isDeposit, isWithdrawal } from '@/lib/transaction-sides'
import { cn, currencySymbolFor, formatMoney, formatQuantity } from '@/lib/utils'
import type { Transaction, TransactionListKind } from '@/types/transaction'
import EmptyValue from '@/components/EmptyValue'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'

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

function isInvestmentRow(transaction: Transaction): boolean {
  return transaction.kind === 'investmentBuy' || transaction.kind === 'investmentSell'
}

function detailPath(transaction: Transaction): string {
  return isInvestmentRow(transaction) ? `/transactions/investments/${transaction.id}` : `/transactions/${transaction.id}`
}

function editPath(transaction: Transaction): string {
  return isInvestmentRow(transaction)
    ? `/transactions/investments/${transaction.id}/edit`
    : `/transactions/${transaction.id}/edit`
}

function clonePath(transaction: Transaction): string {
  return isInvestmentRow(transaction)
    ? `/transactions/investments/new?cloneFrom=${transaction.id}`
    : `/transactions/new?cloneFrom=${transaction.id}`
}

/** Withdrawals and BUYs show as a negative (money leaving), deposits and SELLs stay positive; transfers are neutral. */
function signedAmount(transaction: Transaction): number {
  if (isWithdrawal(transaction) || transaction.kind === 'investmentBuy') return -Number(transaction.amount)
  return Number(transaction.amount)
}

function amountColorClass(transaction: Transaction): string {
  if (isWithdrawal(transaction) || transaction.kind === 'investmentBuy') return 'text-negative'
  if (isDeposit(transaction) || transaction.kind === 'investmentSell') return 'text-positive'
  return ''
}

/** The icon in front of a phone list row, by kind of transaction. */
function TransactionIcon({ transaction }: { transaction: Transaction }) {
  if (transaction.kind === 'investmentBuy') {
    return <ArrowDownToLine className="h-4 w-4 text-negative" />
  }
  if (transaction.kind === 'investmentSell') {
    return <ArrowUpToLine className="h-4 w-4 text-positive" />
  }
  if (isDeposit(transaction)) {
    return <ArrowDownCircle className="h-4 w-4 text-positive" />
  }
  if (isWithdrawal(transaction)) {
    return <ArrowUpCircle className="h-4 w-4 text-negative" />
  }
  return <Repeat className="h-4 w-4 text-info" />
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
                      ? `${formatQuantity(transaction.quantity)} × ${formatMoney(transaction.unitPrice, currencySymbolFor(transaction.currency))}`
                      : null
                  return (
                    <MobileListRow
                      key={transaction.id}
                      onClick={() => navigate(detailPath(transaction))}
                      leading={
                        <MobileListIcon>
                          <TransactionIcon transaction={transaction} />
                        </MobileListIcon>
                      }
                      title={transaction.description ?? <EmptyValue />}
                      subtitle={[
                        trade ?? `${outgoing ? '→' : '←'} ${other?.name ?? '—'}`,
                        transaction.categoryName,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                      value={formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
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
        <Card>
          <CardContent className="p-0">
            {isLoading ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[34px] pl-4 pr-0.5" />
                    <TableHead>{t('transactionsCommon.description')}</TableHead>
                    {isInvestmentAccount ? (
                      <>
                        <TableHead className="text-right">{t('investmentTransactions.operation')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.fees')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.taxes')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.total')}</TableHead>
                      </>
                    ) : (
                      <TableHead className="text-right">{t('transactionsCommon.amount')}</TableHead>
                    )}
                    <TableHead>{t('transactionsCommon.date')}</TableHead>
                    <TableHead>{t('transactionsCommon.source')}</TableHead>
                    <TableHead>{t('transactionsCommon.destination')}</TableHead>
                    {!isInvestmentAccount && <TableHead>{t('transactionsCommon.category')}</TableHead>}
                    <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableSkeleton
                  columns={[
                    { cellClassName: 'pl-4 pr-0.5', skeletonClassName: 'h-4 w-4 rounded-full' },
                    { skeletonClassName: 'w-40' },
                    ...(isInvestmentAccount
                      ? [
                          { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-24' },
                          { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-12' },
                          { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-12' },
                        ]
                      : []),
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                    { skeletonClassName: 'w-24' },
                    { skeletonClassName: 'w-28' },
                    { skeletonClassName: 'w-28' },
                    ...(isInvestmentAccount ? [] : [{ skeletonClassName: 'w-24' }]),
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                  ]}
                />
              </Table>
            ) : !transactions || transactions.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="rounded-full bg-muted p-4 mb-4">
                  <Plus className="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 className="text-lg font-semibold mb-2">{t('accountDetail.noTransactionsTitle')}</h3>
                <p className="text-sm text-muted-foreground mb-4 max-w-sm">
                  {t('accountDetail.noTransactionsDescription')}
                </p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[34px] pl-4 pr-0.5" />
                    <TableHead>{t('transactionsCommon.description')}</TableHead>
                    {isInvestmentAccount ? (
                      <>
                        <TableHead className="text-right">{t('investmentTransactions.operation')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.fees')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.taxes')}</TableHead>
                        <TableHead className="text-right">{t('investmentTransactions.total')}</TableHead>
                      </>
                    ) : (
                      <TableHead className="text-right">{t('transactionsCommon.amount')}</TableHead>
                    )}
                    <TableHead>{t('transactionsCommon.date')}</TableHead>
                    <TableHead>{t('transactionsCommon.source')}</TableHead>
                    <TableHead>{t('transactionsCommon.destination')}</TableHead>
                    {!isInvestmentAccount && <TableHead>{t('transactionsCommon.category')}</TableHead>}
                    <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
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
                        <TransactionIcon transaction={transaction} />
                      </TableCell>
                      <TableCell className="font-medium">
                        {transaction.description ?? <EmptyValue />}
                      </TableCell>
                      {isInvestmentAccount && (
                        <>
                          {/* Trade cells stay empty on money rows: nothing to show there. */}
                          <TableCell className="whitespace-nowrap text-right tabular-nums">
                            {transaction.quantity !== null && transaction.unitPrice !== null &&
                              `${formatQuantity(transaction.quantity)} × ${formatMoney(transaction.unitPrice, currencySymbolFor(transaction.currency))}`}
                          </TableCell>
                          <TableCell className="text-right tabular-nums text-muted-foreground">
                            {transaction.fees !== null && formatMoney(transaction.fees, currencySymbolFor(transaction.currency))}
                          </TableCell>
                          <TableCell className="text-right tabular-nums text-muted-foreground">
                            {transaction.taxes !== null && formatMoney(transaction.taxes, currencySymbolFor(transaction.currency))}
                          </TableCell>
                        </>
                      )}
                      <TableCell className={cn('text-right tabular-nums', isInvestmentAccount && 'font-medium', amountColorClass(transaction))}>
                        {formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        <DateWithTime date={transaction.occurredAt} />
                      </TableCell>
                      <TableCell><AccountName account={transaction.sourceAccount} /></TableCell>
                      <TableCell><AccountName account={transaction.destinationAccount} /></TableCell>
                      {!isInvestmentAccount && (
                        <TableCell className="text-muted-foreground">{transaction.categoryName}</TableCell>
                      )}
                      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon">
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

      <Dialog open={!!transactionToDelete} onOpenChange={(open) => !open && setTransactionToDelete(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('transactionsCommon.deleteConfirmTitle')}</DialogTitle>
            <DialogDescription>
              {t('transactionsCommon.deleteConfirmDescription')}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setTransactionToDelete(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteTransaction.isPending || deleteInvestmentTransaction.isPending}
            >
              {t('common.delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
