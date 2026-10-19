import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  ArrowRight,
  ArrowUpCircle,
  ArrowDownCircle,
  Repeat,
  Pencil,
  Trash2,
  History,
  Wallet,
  ShoppingCart,
  Banknote,
  Copy,
} from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import TransactionAmount from '@/components/TransactionAmount'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useTransaction, useDeleteTransaction, useTransactionAuditLog } from '@/hooks/use-transactions'
import AccountName from '@/components/AccountName'
import { handleApiError } from '@/lib/error-handler'
import { isDeposit, isWithdrawal } from '@/lib/transaction-sides'
import { currencySymbolFor, formatDateTime, formatMoney, formatTime24h } from '@/lib/utils'
import { useState } from 'react'
import type { Transaction, TransactionAuditLogChange, TransactionAuditLogEntry } from '@/types/transaction'
import EmptyValue from '@/components/EmptyValue'

/** Withdrawals show as a negative (money leaving), deposits stay positive; transfers are neutral. */
function signedAmount(transaction: Transaction): number {
  if (isWithdrawal(transaction)) return -Number(transaction.amount)
  return Number(transaction.amount)
}

function amountColorClass(transaction: Transaction): string {
  if (isWithdrawal(transaction)) return 'text-negative'
  if (isDeposit(transaction)) return 'text-positive'
  return ''
}

function AccountTypeIcon({ type }: { type: NonNullable<Transaction['sourceAccount']>['type'] }) {
  if (type === 'expense') return <ShoppingCart className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
  if (type === 'revenue') return <Banknote className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
  return <Wallet className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
}

/** One side of the transaction: a link to the account, or "External" when there is none. */
function AccountSide({ account }: { account: Transaction['sourceAccount'] }) {
  const navigate = useNavigate()

  if (account === null) {
    return <AccountName account={null} />
  }

  return (
    <button
      type="button"
      onClick={() => navigate(`/accounts/${account.type}/${account.id}`)}
      className="flex items-center gap-1.5 font-medium underline-offset-4 hover:underline"
    >
      <AccountTypeIcon type={account.type} />
      {account.name}
    </button>
  )
}

function TransactionIcon({ transaction }: { transaction: Transaction }) {
  if (isDeposit(transaction)) {
    return <ArrowDownCircle className="h-8 w-8 shrink-0 text-positive" />
  }
  if (isWithdrawal(transaction)) {
    return <ArrowUpCircle className="h-8 w-8 shrink-0 text-negative" />
  }
  return <Repeat className="h-8 w-8 shrink-0 text-info" />
}

const HISTORY_FIELD_KEYS = new Set([
  'description',
  'sourceAccount',
  'destinationAccount',
  'occurredAt',
  'amount',
  'currency',
  'category',
  'tags',
  'notes',
])

function formatHistoryValue(field: string, value: unknown, t: (key: string) => string): string {
  if ((field === 'sourceAccount' || field === 'destinationAccount') && value === null) {
    return '—'
  }
  if (value === null || value === undefined || value === '') {
    return t('transactionDetail.historyEmptyValue')
  }
  if (field === 'occurredAt' && typeof value === 'string') {
    return `${formatDateTime(value)} · ${formatTime24h(value)}`
  }
  if (field === 'amount' && typeof value === 'string') {
    return formatMoney(value, null)
  }
  if (Array.isArray(value)) {
    return value.length > 0 ? value.join(', ') : t('transactionDetail.historyEmptyValue')
  }
  return String(value)
}

function historyFieldLabel(field: string, t: (key: string) => string): string {
  return HISTORY_FIELD_KEYS.has(field) ? t(`transactionDetail.historyField.${field}`) : field
}

function HistoryValue({ children }: { children: string }) {
  return <code className="rounded bg-muted px-1 py-0.5 text-xs">{children}</code>
}

function TransactionHistoryEntry({ entry }: { entry: TransactionAuditLogEntry }) {
  const { t } = useTranslation()
  const timestamp = `${formatDateTime(entry.createdAt)} · ${formatTime24h(entry.createdAt)}`

  return (
    <li className="space-y-1.5 py-3 first:pt-0 last:pb-0">
      <div className="text-xs text-muted-foreground">{timestamp}</div>
      {entry.action === 'created' ? (
        <div className="text-sm">{t('transactionDetail.historyCreated')}</div>
      ) : (
        <ul className="space-y-1">
          {(entry.changes ?? []).map((change: TransactionAuditLogChange, index: number) => (
            <li key={`${entry.id}-${index}`} className="text-sm">
              {historyFieldLabel(change.field, t)}:{' '}
              <HistoryValue>{formatHistoryValue(change.field, change.oldValue, t)}</HistoryValue>
              {' → '}
              <HistoryValue>{formatHistoryValue(change.field, change.newValue, t)}</HistoryValue>
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

function TransactionHistoryCard({ transactionId }: { transactionId: string }) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useTransactionAuditLog(transactionId)

  return (
    <Card>
      <CardContent className="pt-6">
        <div className="mb-3 flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <History className="h-4 w-4" />
          {t('transactionDetail.history')}
        </div>
        {isLoading ? (
          <Skeleton className="h-5 w-1/2" />
        ) : entries && entries.length > 0 ? (
          <ul className="divide-y divide-border">
            {entries.map((entry) => (
              <TransactionHistoryEntry key={entry.id} entry={entry} />
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{t('transactionDetail.historyEmpty')}</p>
        )}
      </CardContent>
    </Card>
  )
}

export default function TransactionDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { id } = useParams<{ id: string }>()
  const { data: transaction, isLoading } = useTransaction(id ?? '')
  const deleteTransaction = useDeleteTransaction()
  const [confirmDelete, setConfirmDelete] = useState(false)

  const handleConfirmDelete = async () => {
    if (!transaction) return
    try {
      await deleteTransaction.mutateAsync(transaction.id)
      // Back to wherever the user came from (e.g. an account's page); a URL opened directly has no in-app history.
      if (location.key === 'default') {
        navigate('/transactions')
      } else {
        navigate(-1)
      }
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  if (isLoading || !transaction) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('transactionDetail.title')} />
        <Card>
          <CardContent className="space-y-4 pt-6">
            <Skeleton className="h-8 w-2/3" />
            <Skeleton className="h-6 w-1/3" />
            <Skeleton className="h-6 w-1/2" />
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={transaction.description ?? t('transactionDetail.noDescription')}
        description={t('transactionDetail.title')}
        actions={
          <>
            <Button variant="outline" onClick={() => navigate(`/transactions/${transaction.id}/edit`)}>
              <Pencil className="mr-2 h-4 w-4" />
              {t('common.edit')}
            </Button>
            <Button variant="outline" onClick={() => navigate(`/transactions/new?cloneFrom=${transaction.id}`)}>
              <Copy className="mr-2 h-4 w-4" />
              {t('transactionsCommon.clone')}
            </Button>
            <Button
              variant="outline"
              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => setConfirmDelete(true)}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('common.delete')}
            </Button>
          </>
        }
      />

      <Card>
        <CardContent className="grid gap-6 pt-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
          <div className="flex items-start gap-4">
            <TransactionIcon transaction={transaction} />
            <div>
              <div className="flex flex-wrap items-baseline gap-x-2">
                <TransactionAmount
                  amount={signedAmount(transaction)}
                  currencySymbol={currencySymbolFor(transaction.currency)}
                  className={amountColorClass(transaction)}
                />
                {transaction.foreignAmount !== null && transaction.foreignCurrency && (
                  <span className="text-sm tabular-nums text-muted-foreground">
                    ({formatMoney(transaction.foreignAmount, currencySymbolFor(transaction.foreignCurrency))})
                  </span>
                )}
              </div>
              <div className="mt-1.5 text-sm text-muted-foreground">
                {formatDateTime(transaction.occurredAt)} · {formatTime24h(transaction.occurredAt)}
              </div>

              <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <AccountSide account={transaction.sourceAccount} />
                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <AccountSide account={transaction.destinationAccount} />
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 lg:border-l lg:border-border lg:pl-6">
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('transactionsCommon.category')}</div>
              <div>
                {transaction.categoryName ? (
                  <Badge variant="outline">{transaction.categoryName}</Badge>
                ) : (
                  <EmptyValue />
                )}
              </div>
            </div>

            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('transactionsCommon.tags')}</div>
              <div className="flex flex-wrap gap-1">
                {transaction.tags.length > 0 ? (
                  transaction.tags.map((tag) => (
                    <Badge key={tag.id} variant="secondary">
                      {tag.name}
                    </Badge>
                  ))
                ) : (
                  <EmptyValue />
                )}
              </div>
            </div>

            {transaction.notes && (
              <div className="space-y-1 sm:col-span-2">
                <div className="text-sm text-muted-foreground">{t('classificationCommon.notes')}</div>
                <p className="whitespace-pre-wrap text-sm">{transaction.notes}</p>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      <TransactionHistoryCard transactionId={transaction.id} />

      <Dialog open={confirmDelete} onOpenChange={setConfirmDelete}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('transactionsCommon.deleteConfirmTitle')}</DialogTitle>
            <DialogDescription>{t('transactionsCommon.deleteConfirmDescription')}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmDelete(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="destructive" onClick={handleConfirmDelete} disabled={deleteTransaction.isPending}>
              {t('common.delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
