import { useState, type ReactNode } from 'react'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import DateWithTime from '@/components/DateWithTime'
import TableSkeleton from '@/components/TableSkeleton'
import Highlight from '@/components/Highlight'
import { useDeleteTransaction } from '@/hooks/use-transactions'
import { useDeleteInvestmentTransaction } from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import AccountName from '@/components/AccountName'
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
        <Badge key={tag.id} variant="secondary" className="px-1.5 py-0 text-xs font-normal">
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

  if (isLoading) return <MobileListSkeleton />

  if (!transactions || transactions.length === 0) {
    return <MobileListCard>{empty}</MobileListCard>
  }

  return (
    <div className="space-y-4">
      {groupByDay(transactions, (transaction) => transaction.occurredAt).map((group) => (
        <MobileListSection key={group.day} title={dayLabel(group.date)}>
          {group.items.map((transaction) => (
            <MobileListRow
              key={transaction.id}
              onClick={() => navigate(detailPath(transaction))}
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
          ))}
        </MobileListSection>
      ))}

      {data && data.total > data.pageSize && (
        <MobileListCard className="[&>div]:border-t-0">
          <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={onPageChange} />
        </MobileListCard>
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

  const header = (
    <TableHeader>
      <TableRow>
        <TableHead className="w-[34px] pl-4 pr-0.5" />
        <TableHead>{t('transactionsCommon.description')}</TableHead>
        <TableHead className="text-right">{t('transactionsCommon.amount')}</TableHead>
        <TableHead>{t('transactionsCommon.date')}</TableHead>
        <TableHead>{t('transactionsCommon.source')}</TableHead>
        <TableHead>{t('transactionsCommon.destination')}</TableHead>
        <TableHead>{t('transactionsCommon.category')}</TableHead>
        <TableHead className="text-right">{t('accountsCommon.actions')}</TableHead>
      </TableRow>
    </TableHeader>
  )

  return (
    <>
      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <Table>
              {header}
              <TableSkeleton
                columns={[
                  { cellClassName: 'pl-4 pr-0.5', skeletonClassName: 'h-4 w-4 rounded-full' },
                  { skeletonClassName: 'w-40' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto w-20' },
                  { skeletonClassName: 'w-24' },
                  { skeletonClassName: 'w-28' },
                  { skeletonClassName: 'w-28' },
                  { skeletonClassName: 'w-24' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                ]}
              />
            </Table>
          ) : !transactions || transactions.length === 0 ? (
            empty
          ) : (
            <Table>
              {header}
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
                      {transaction.description ? (
                        <Highlight text={transaction.description} terms={highlight} />
                      ) : (
                        <EmptyValue />
                      )}
                      {highlight && highlight.length > 0 && <HiddenMatches transaction={transaction} terms={highlight} />}
                    </TableCell>
                    <TableCell className={cn('text-right tabular-nums', amountColorClass(transaction))}>
                      {formatMoney(signedAmount(transaction), currencySymbolFor(transaction.currency))}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      <DateWithTime date={transaction.occurredAt} />
                    </TableCell>
                    <TableCell><AccountName account={transaction.sourceAccount} highlight={highlight} /></TableCell>
                    <TableCell><AccountName account={transaction.destinationAccount} highlight={highlight} /></TableCell>
                    <TableCell className="text-muted-foreground">
                      {transaction.categoryName ? (
                        <Highlight text={transaction.categoryName} terms={highlight} />
                      ) : (
                        <EmptyValue />
                      )}
                    </TableCell>
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
            onPageChange={onPageChange}
          />
        )}
      </Card>

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
    </>
  )
}
