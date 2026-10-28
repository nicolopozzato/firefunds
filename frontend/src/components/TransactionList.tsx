import { useEffect, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Copy } from 'lucide-react'
import Pagination from '@/components/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import TableSkeleton from '@/components/TableSkeleton'
import Highlight from '@/components/Highlight'
import { useDeleteTransaction } from '@/hooks/use-transactions'
import { useDeleteInvestmentTransaction } from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import AccountName from '@/components/AccountName'
import ClassificationLink from '@/components/ClassificationLink'
import { containsTerm, matchExcerpt } from '@/lib/search'
import { amountColorClass, clonePath, detailPath, editPath, isInvestmentRow, signedAmount } from '@/lib/transaction-display'
import TransactionKindIcon from '@/components/TransactionKindIcon'
import { cn, currencySymbolFor, formatMoney } from '@/lib/utils'
import { useIsMobile } from '@/hooks/use-media-query'
import { groupByDay, useDayLabel } from '@/hooks/use-day-groups'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSection, MobileListSkeleton } from '@/components/MobileList'
import type { Transaction } from '@/types/transaction'
import type { PaginatedResponse } from '@/types/pagination'
import EmptyValue from '@/components/EmptyValue'
import ConfirmDialog from '@/components/ConfirmDialog'
import TransactionBulkBar from '@/components/TransactionBulkBar'
import { Checkbox } from '@/components/ui/checkbox'

/**
 * Under a search result's description, what matched but isn't in a column:
 * an excerpt of the notes and the tags.
 */
function HiddenMatches({ transaction, terms }: { transaction: Transaction; terms: string[] }) {
  const { t } = useTranslation()
  const notes = matchExcerpt(transaction.notes, terms)
  const tags = transaction.tags.filter((tag) => containsTerm(tag.name, terms))

  if (!notes && tags.length === 0) return null

  return (
    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-normal text-muted-foreground">
      {notes && (
        <span className="max-w-md truncate">
          {t('transactionsCommon.notes')}: <Highlight text={notes} terms={terms} />
        </span>
      )}
      {tags.map((tag) => (
        <Badge key={tag.id} variant="secondary" className="font-normal">
          <Highlight text={tag.name} terms={terms} />
        </Badge>
      ))}
    </div>
  )
}

/**
 * Phones: the same page of transactions as a list grouped by day, as banking
 * apps do. Each row says what and where, and opens the detail page, where
 * edit, clone and delete are.
 */
function TransactionDayList({ data, isLoading, onPageChange, empty, highlight }: TransactionListProps) {
  const navigate = useNavigate()
  const dayLabel = useDayLabel()
  const transactions = data?.items
  // Holding a money row down starts a selection (TransactionBulkBar); then a tap adds or removes a row.
  const [selected, setSelected] = useState<Set<string> | null>(null)
  useEffect(() => setSelected(null), [data])
  const toggle = (id: string) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next.size > 0 ? next : null
    })

  if (isLoading) return <MobileListSkeleton />

  if (!transactions || transactions.length === 0) {
    return <MobileListCard>{empty}</MobileListCard>
  }

  return (
    <div className="space-y-4">
      {groupByDay(transactions, (transaction) => transaction.occurredAt).map((group) => (
        <MobileListSection key={group.day} title={dayLabel(group.date)}>
          {group.items.map((transaction) => {
            const selectable = !isInvestmentRow(transaction)
            return (
              <MobileListRow
                key={transaction.id}
                onClick={() => (selected ? selectable && toggle(transaction.id) : navigate(detailPath(transaction)))}
                onLongPress={selectable && !selected ? () => toggle(transaction.id) : undefined}
                selected={selected && selectable ? selected.has(transaction.id) : undefined}
                leading={
                  <MobileListIcon>
                    <TransactionKindIcon transaction={transaction} />
                  </MobileListIcon>
                }
                title={transaction.description ? <Highlight text={transaction.description} terms={highlight} /> : <EmptyValue />}
                subtitle={
                  <>
                    {[transaction.sourceAccount?.name ?? '—', transaction.destinationAccount?.name ?? '—'].join(' → ')}
                    {transaction.categoryName && ` · ${transaction.categoryName}`}
                  </>
                }
                extra={highlight && highlight.length > 0 && <HiddenMatches transaction={transaction} terms={highlight} />}
                value={formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
                valueClassName={amountColorClass(transaction)}
              />
            )
          })}
        </MobileListSection>
      ))}

      {data && data.total > data.pageSize && (
        <MobileListCard className="[&>div]:border-t-0">
          <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={onPageChange} />
        </MobileListCard>
      )}

      {selected && (
        <>
          {/* Room under the last rows, so the panel never covers them. */}
          <div className="h-28" aria-hidden />
          <TransactionBulkBar floating ids={[...selected]} onDone={() => setSelected(null)} />
        </>
      )}
    </div>
  )
}

interface TransactionListProps {
  data: PaginatedResponse<Transaction> | undefined
  isLoading: boolean
  onPageChange: (page: number) => void
  /** Shown in the card when there is nothing to list. */
  empty: ReactNode
  /** Search terms: marked in the row, with what matched in the notes or tags shown under the description. */
  highlight?: string[]
}

/**
 * A page of transactions (money and investment BUY/SELLs) in a card, each
 * opening its detail, with edit, clone and delete; a list by day on phones.
 */
export default function TransactionList(props: TransactionListProps) {
  const isMobile = useIsMobile()
  return isMobile ? <TransactionDayList {...props} /> : <TransactionTable {...props} />
}

function TransactionTable({ data, isLoading, onPageChange, empty, highlight }: TransactionListProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const transactions = data?.items
  const deleteTransaction = useDeleteTransaction()
  const deleteInvestmentTransaction = useDeleteInvestmentTransaction()
  const [transactionToDelete, setTransactionToDelete] = useState<Transaction | null>(null)

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

  // Selection for a change to several at once (TransactionBulkBar): money rows of this page only.
  const [selected, setSelected] = useState<Set<string>>(new Set())
  useEffect(() => setSelected(new Set()), [data])
  const selectable = (transactions ?? []).filter((transaction) => !isInvestmentRow(transaction)).map((transaction) => transaction.id)
  const anySelected = selected.size > 0
  const allSelected = anySelected && selectable.every((id) => selected.has(id))
  const toggle = (id: string) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const header = (
    <TableHeader>
      <TableRow>
        <TableHead className="w-[34px] pl-4 pr-0.5">
          {anySelected && (
            <Checkbox
              aria-label={t('bulk.selectAll')}
              checked={allSelected}
              onChange={() => setSelected(allSelected ? new Set() : new Set(selectable))}
            />
          )}
        </TableHead>
        <TableHead className="w-[26%]">{t('transactionsCommon.description')}</TableHead>
        <TableHead className="w-28 text-right">{t('transactionsCommon.amount')}</TableHead>
        <TableHead className="w-[6.75rem]">{t('transactionsCommon.date')}</TableHead>
        <TableHead>{t('transactionsCommon.source')}</TableHead>
        <TableHead>{t('transactionsCommon.destination')}</TableHead>
        <TableHead className="hidden xl:table-cell">{t('transactionsCommon.category')}</TableHead>
        <TableHead className="w-16"><span className="sr-only">{t('accountsCommon.actions')}</span></TableHead>
      </TableRow>
    </TableHeader>
  )

  return (
    <>
      <Card className="overflow-hidden">
        <CardContent className="p-0 sm:p-0">
          {isLoading ? (
            <Table className="table-fixed">
              {header}
              <TableSkeleton
                columns={[
                  { cellClassName: 'pl-4 pr-0.5', skeletonClassName: 'h-4 w-4 rounded-full' },
                  { skeletonClassName: 'w-40' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                  { skeletonClassName: 'w-24' },
                  { skeletonClassName: 'w-28' },
                  { skeletonClassName: 'w-28' },
                  { cellClassName: 'hidden xl:table-cell', skeletonClassName: 'w-24' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                ]}
              />
            </Table>
          ) : !transactions || transactions.length === 0 ? (
            empty
          ) : (
            <>
            {anySelected && <TransactionBulkBar ids={[...selected]} onDone={() => setSelected(new Set())} />}
            <Table className="table-fixed">
              {header}
              <TableBody>
                {transactions.map((transaction) => (
                  <TableRow
                    key={transaction.id}
                    className="cursor-pointer"
                    data-state={selected.has(transaction.id) ? 'selected' : undefined}
                    onClick={() => navigate(detailPath(transaction))}
                  >
                    <TableCell className="pl-4 pr-0.5">
                      {isInvestmentRow(transaction) ? (
                        <TransactionKindIcon transaction={transaction} />
                      ) : (
                        // The kind's icon, turning into a checkbox under the pointer or while rows are selected.
                        <span className="relative flex h-5 w-5 items-center justify-center">
                          <span className={cn('transition-opacity duration-150', anySelected ? 'opacity-0' : 'group-hover:opacity-0')}>
                            <TransactionKindIcon transaction={transaction} />
                          </span>
                          <Checkbox
                            aria-label={t('bulk.select')}
                            className={cn(
                              'absolute inset-0 m-auto opacity-0 transition-opacity duration-150 focus-visible:opacity-100 group-hover:opacity-100',
                              anySelected && 'opacity-100'
                            )}
                            checked={selected.has(transaction.id)}
                            onClick={(event) => event.stopPropagation()}
                            onChange={() => toggle(transaction.id)}
                          />
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="font-medium">
                      <span className="cell-text" title={transaction.description ?? undefined}>
                        {transaction.description ? <Highlight text={transaction.description} terms={highlight} /> : <EmptyValue />}
                      </span>
                      {highlight && highlight.length > 0 && <HiddenMatches transaction={transaction} terms={highlight} />}
                    </TableCell>
                    <TableCell className={cn('text-right tabular-nums', amountColorClass(transaction))}>
                      {formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      <DateWithTime date={transaction.occurredAt} />
                    </TableCell>
                    <TableCell><span className="cell-text"><AccountName account={transaction.sourceAccount} highlight={highlight} link /></span></TableCell>
                    <TableCell><span className="cell-text"><AccountName account={transaction.destinationAccount} highlight={highlight} link /></span></TableCell>
                    <TableCell className="hidden xl:table-cell text-muted-foreground">
                      <span className="cell-text">
                        {transaction.categoryName && transaction.categoryId ? (
                          <ClassificationLink to={`/categories/${transaction.categoryId}`}>
                            <Highlight text={transaction.categoryName} terms={highlight} />
                          </ClassificationLink>
                        ) : (
                          <EmptyValue />
                        )}
                      </span>
                    </TableCell>
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
            </>
          )}
        </CardContent>
        {data && (
          <Pagination
            page={data.page}
            pageSize={data.pageSize}
            total={data.total}
            onPageChange={onPageChange}
          />
        )}
      </Card>

      <ConfirmDialog
        open={!!transactionToDelete}
        onOpenChange={(open) => !open && setTransactionToDelete(null)}
        title={t('transactionsCommon.deleteConfirmTitle')}
        description={t('transactionsCommon.deleteConfirmDescription')}
        onConfirm={handleConfirmDelete}
        pending={deleteTransaction.isPending || deleteInvestmentTransaction.isPending}
      />
    </>
  )
}
