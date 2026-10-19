import { useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { History, Pencil, Trash2, ArrowDownToLine, ArrowUpToLine, ArrowRight, Copy, Layers, Wallet } from 'lucide-react'
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
import {
  useDeleteInvestmentTransaction,
  useInvestmentTransaction,
  useInvestmentTransactionAuditLog,
} from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import { currencySymbolFor, formatDateTime, formatMoney, formatQuantity, formatTime24h } from '@/lib/utils'
import { useAuth } from '@/contexts/AuthContext'
import type {
  InvestmentTransaction,
  InvestmentTransactionAuditLogChange,
  InvestmentTransactionAuditLogEntry,
} from '@/types/investment'
import LoadFailed from '@/components/LoadFailed'

const basePath = '/transactions/investments'

function signedTotal(transaction: InvestmentTransaction): number {
  return transaction.type === 'buy' ? -Number(transaction.totalAmount) : Number(transaction.totalAmount)
}

const HISTORY_FIELD_KEYS = new Set([
  'description',
  'account',
  'instrument',
  'type',
  'quantity',
  'unitPrice',
  'amount',
  'fees',
  'taxes',
  'occurredAt',
  'notes',
])

function formatHistoryValue(field: string, value: unknown, t: (key: string) => string): string {
  if (value === null || value === undefined || value === '') {
    return t('investmentTransactionDetail.historyEmptyValue')
  }
  if (field === 'occurredAt' && typeof value === 'string') {
    return `${formatDateTime(value)} · ${formatTime24h(value)}`
  }
  if (field === 'unitPrice' || field === 'amount' || field === 'fees' || field === 'taxes') {
    return formatMoney(String(value), null)
  }
  if (field === 'type' && typeof value === 'string') {
    return t(`investmentTransactions.type_${value}`)
  }
  return String(value)
}

function historyFieldLabel(field: string, t: (key: string) => string): string {
  return HISTORY_FIELD_KEYS.has(field) ? t(`investmentTransactionDetail.historyField.${field}`) : field
}

function HistoryValue({ children }: { children: string }) {
  return <code className="rounded bg-muted px-1 py-0.5 text-xs">{children}</code>
}

function InvestmentTransactionHistoryEntry({ entry }: { entry: InvestmentTransactionAuditLogEntry }) {
  const { t } = useTranslation()
  const timestamp = `${formatDateTime(entry.createdAt)} · ${formatTime24h(entry.createdAt)}`

  return (
    <li className="space-y-1.5 py-3 first:pt-0 last:pb-0">
      <div className="text-xs text-muted-foreground">{timestamp}</div>
      {entry.action === 'created' ? (
        <div className="text-sm">{t('investmentTransactionDetail.historyCreated')}</div>
      ) : (
        <ul className="space-y-1">
          {(entry.changes ?? []).map((change: InvestmentTransactionAuditLogChange, index: number) => (
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

function InvestmentTransactionHistoryCard({ transactionId }: { transactionId: string }) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useInvestmentTransactionAuditLog(transactionId)

  return (
    <Card>
      <CardContent className="pt-6">
        <div className="mb-3 flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <History className="h-4 w-4" />
          {t('investmentTransactionDetail.history')}
        </div>
        {isLoading ? (
          <Skeleton className="h-5 w-1/2" />
        ) : entries && entries.length > 0 ? (
          <ul className="divide-y divide-border">
            {entries.map((entry) => (
              <InvestmentTransactionHistoryEntry key={entry.id} entry={entry} />
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{t('investmentTransactionDetail.historyEmpty')}</p>
        )}
      </CardContent>
    </Card>
  )
}

export default function InvestmentTransactionDetailPage() {
  const { user } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { id } = useParams<{ id: string }>()
  const { data: transaction, isLoading, error, refetch } = useInvestmentTransaction(id ?? '')
  const deleteTransaction = useDeleteInvestmentTransaction()
  const [confirmDelete, setConfirmDelete] = useState(false)

  const handleConfirmDelete = async () => {
    if (!transaction) return
    try {
      await deleteTransaction.mutateAsync(transaction.id)
      // Back to wherever the user came from (e.g. an account's page); a URL opened directly has no in-app history.
      if (location.key === 'default') {
        navigate(basePath)
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
        <PageHeader title={t('investmentTransactionDetail.title')} />
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

  const isBuy = transaction.type === 'buy'
  const currencySymbol = currencySymbolFor(transaction.currency)

  const accountLink = (
    <button
      type="button"
      onClick={() => navigate(`/accounts/${transaction.account.type}/${transaction.account.id}`)}
      className="flex items-center gap-1.5 font-medium underline-offset-4 hover:underline"
    >
      <Wallet className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      {transaction.account.name}
    </button>
  )

  const instrumentLink = (
    <button
      type="button"
      onClick={() => navigate(`/investments/instruments/${transaction.instrumentId}`)}
      className="flex items-center gap-1.5 text-left underline-offset-4 hover:underline"
    >
      <Layers className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      {transaction.instrumentTicker && <span className="font-medium">{transaction.instrumentTicker}</span>}
      <span className={transaction.instrumentTicker ? 'text-muted-foreground' : 'font-medium'}>{transaction.instrumentName}</span>
    </button>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={transaction.description ?? transaction.instrumentName}
        description={t('investmentTransactionDetail.title')}
        actions={
          <>
            <Button variant="outline" onClick={() => navigate(`${basePath}/${transaction.id}/edit`)}>
              <Pencil className="mr-2 h-4 w-4" />
              {t('common.edit')}
            </Button>
            <Button variant="outline" onClick={() => navigate(`${basePath}/new?cloneFrom=${transaction.id}`)}>
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
            {isBuy ? (
              <ArrowDownToLine className="h-8 w-8 shrink-0 text-negative" />
            ) : (
              <ArrowUpToLine className="h-8 w-8 shrink-0 text-positive" />
            )}
            <div>
              <TransactionAmount
                amount={signedTotal(transaction)}
                currencySymbol={currencySymbol}
                className={isBuy ? 'text-negative' : 'text-positive'}
              />
              <div className="mt-1.5 text-sm text-muted-foreground">
                {formatDateTime(transaction.occurredAt)} · {formatTime24h(transaction.occurredAt)}
                {/* Written in another timezone (e.g. abroad): the time is that place's. */}
                {transaction.timezone !== user?.timezone && (
                  <span className="text-muted-foreground"> ({transaction.timezone.replace(/_/g, ' ')})</span>
                )}
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">{t(`investmentTransactions.type_${transaction.type}`)}</Badge>
                {/* Cash flows from the account into the instrument on a BUY, and back on a SELL. */}
                {isBuy ? accountLink : instrumentLink}
                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                {isBuy ? instrumentLink : accountLink}
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 lg:border-l lg:border-border lg:pl-6">
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentTransactions.quantity')}</div>
              <div className="tabular-nums">{formatQuantity(transaction.quantity)}</div>
            </div>
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentTransactions.unitPrice')}</div>
              <div className="tabular-nums">{formatMoney(transaction.unitPrice, currencySymbol)}</div>
            </div>
            {/* Quantity x unit price; with fees and taxes it adds up to the headline total, which isn't repeated here. */}
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentTransactions.countervalue')}</div>
              <div className="tabular-nums">{formatMoney(transaction.amount, currencySymbol)}</div>
            </div>
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentTransactions.fees')}</div>
              <div className="tabular-nums">{formatMoney(transaction.fees, currencySymbol)}</div>
            </div>
            <div className="space-y-1">
              <div className="text-sm text-muted-foreground">{t('investmentTransactions.taxes')}</div>
              <div className="tabular-nums">{formatMoney(transaction.taxes, currencySymbol)}</div>
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

      <InvestmentTransactionHistoryCard transactionId={transaction.id} />

      <Dialog open={confirmDelete} onOpenChange={setConfirmDelete}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('investmentTransactions.deleteConfirmTitle')}</DialogTitle>
            <DialogDescription>{t('investmentTransactions.deleteConfirmDescription')}</DialogDescription>
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
