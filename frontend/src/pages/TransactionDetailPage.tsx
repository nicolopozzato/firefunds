import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowUpRight, ArrowDownLeft, Repeat, Pencil, Trash2, Wallet, ShoppingCart, Banknote, Copy } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import TransactionAmount from '@/components/TransactionAmount'
import { DetailField, DetailFields, DetailFlow, DetailHeadline, DetailLayout } from '@/components/TransactionDetail'
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
import type { Transaction } from '@/types/transaction'
import HistoryCard from '@/components/HistoryCard'
import { isEmptyValue } from '@/lib/audit-log'
import EmptyValue from '@/components/EmptyValue'
import { useAuth } from '@/contexts/AuthContext'
import LoadFailed from '@/components/LoadFailed'

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

/** Withdrawal, deposit or transfer, from its sides. */
function kindOf(transaction: Transaction): 'withdrawal' | 'deposit' | 'transfer' {
  if (isWithdrawal(transaction)) return 'withdrawal'
  if (isDeposit(transaction)) return 'deposit'
  return 'transfer'
}

/** Sized and coloured by DetailHeadline. */
function TransactionIcon({ transaction }: { transaction: Transaction }) {
  if (isDeposit(transaction)) return <ArrowDownLeft />
  if (isWithdrawal(transaction)) return <ArrowUpRight />
  return <Repeat />
}

const HISTORY_FIELD_KEYS = new Set([
  'description',
  'sourceAccount',
  'destinationAccount',
  'occurredAt',
  'amount',
  'currency',
  'foreignAmount',
  'foreignCurrency',
  'category',
  'tags',
  'notes',
])

/** The transaction's history, as every record's (HistoryCard). */
function TransactionHistoryCard({ transactionId }: { transactionId: string }) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useTransactionAuditLog(transactionId)

  const formatValue = (field: string, value: unknown): string | null => {
    // The missing side of a transaction is "—", as on the page.
    if ((field === 'sourceAccount' || field === 'destinationAccount') && value === null) return '—'
    if (isEmptyValue(value)) return null
    if (field === 'occurredAt') return `${formatDateTime(String(value))} · ${formatTime24h(String(value))}`
    if (field === 'amount' || field === 'foreignAmount') return formatMoney(String(value), null)
    if (Array.isArray(value)) return value.length > 0 ? value.join(', ') : null
    return String(value)
  }

  return (
    <HistoryCard
      entries={entries}
      isLoading={isLoading}
      createdLabel={t('transactionDetail.historyCreated')}
      fieldLabel={(field) => (HISTORY_FIELD_KEYS.has(field) ? t(`transactionDetail.historyField.${field}`) : field)}
      formatValue={formatValue}
    />
  )
}

export default function TransactionDetailPage() {
  const { user } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { id } = useParams<{ id: string }>()
  const { data: transaction, isLoading, error, refetch } = useTransaction(id ?? '')
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

  if (!transaction && error) {
    return <LoadFailed error={error} onRetry={() => void refetch()} />
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
        compactActions
        actions={
          <PageActions
            actions={[
              {
                label: t('common.edit'),
                icon: Pencil,
                primary: true,
                onClick: () => navigate(`/transactions/${transaction.id}/edit`),
              },
              {
                label: t('transactionsCommon.clone'),
                icon: Copy,
                onClick: () => navigate(`/transactions/new?cloneFrom=${transaction.id}`),
              },
              { label: t('common.delete'), icon: Trash2, destructive: true, onClick: () => setConfirmDelete(true) },
            ]}
          />
        }
      />

      <Card>
        <CardContent className="pt-6">
          <DetailLayout
            summary={
              <>
                <DetailHeadline
                  icon={<TransactionIcon transaction={transaction} />}
                  tone={isWithdrawal(transaction) ? 'negative' : isDeposit(transaction) ? 'positive' : 'info'}
                  amount={
                    <>
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
                    </>
                  }
                  subtitle={
                    <>
                      {t(`transactionsCommon.kindOne_${kindOf(transaction)}`)} ·{' '}
                      {formatDateTime(transaction.occurredAt)} · {formatTime24h(transaction.occurredAt)}
                      {/* Written in another timezone (e.g. abroad): the time is that place's. */}
                      {transaction.timezone !== user?.timezone && <> ({transaction.timezone.replace(/_/g, ' ')})</>}
                    </>
                  }
                />

                <DetailFlow
                  from={<AccountSide account={transaction.sourceAccount} />}
                  to={<AccountSide account={transaction.destinationAccount} />}
                />
              </>
            }
            fields={
              <DetailFields>
                <DetailField label={t('transactionsCommon.category')}>
                  {transaction.categoryName ? (
                    <Badge variant="outline">{transaction.categoryName}</Badge>
                  ) : (
                    <EmptyValue />
                  )}
                </DetailField>
                <DetailField label={t('transactionsCommon.tags')}>
                  {transaction.tags.length > 0 ? (
                    <span className="inline-flex flex-wrap gap-1 max-sm:justify-end">
                      {transaction.tags.map((tag) => (
                        <Badge key={tag.id} variant="secondary">
                          {tag.name}
                        </Badge>
                      ))}
                    </span>
                  ) : (
                    <EmptyValue />
                  )}
                </DetailField>
                {transaction.notes && (
                  <DetailField label={t('classificationCommon.notes')} wide>
                    <p className="whitespace-pre-wrap">{transaction.notes}</p>
                  </DetailField>
                )}
              </DetailFields>
            }
          />
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
