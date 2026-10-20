import { useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Pencil, Trash2, ArrowDownToLine, ArrowUpToLine, Copy, Layers, Wallet } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import TransactionAmount from '@/components/TransactionAmount'
import { DetailField, DetailFields, DetailFlow, DetailHeadline, DetailLayout } from '@/components/TransactionDetail'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
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
import type { InvestmentTransaction } from '@/types/investment'
import LoadFailed from '@/components/LoadFailed'
import HistoryCard from '@/components/HistoryCard'
import { isEmptyValue } from '@/lib/audit-log'

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

/** The trade's history, as every record's (HistoryCard). */
function InvestmentTransactionHistoryCard({ transactionId }: { transactionId: string }) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useInvestmentTransactionAuditLog(transactionId)

  const formatValue = (field: string, value: unknown): string | null => {
    if (isEmptyValue(value)) return null
    if (field === 'occurredAt') return `${formatDateTime(String(value))} · ${formatTime24h(String(value))}`
    if (field === 'unitPrice' || field === 'amount' || field === 'fees' || field === 'taxes')
      return formatMoney(String(value), null)
    if (field === 'type') return t(`investmentTransactions.type_${String(value)}`)
    return String(value)
  }

  return (
    <HistoryCard
      entries={entries}
      isLoading={isLoading}
      createdLabel={t('investmentTransactionDetail.historyCreated')}
      fieldLabel={(field) =>
        HISTORY_FIELD_KEYS.has(field) ? t(`investmentTransactionDetail.historyField.${field}`) : field
      }
      formatValue={formatValue}
    />
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
      <span className={transaction.instrumentTicker ? 'text-muted-foreground' : 'font-medium'}>
        {transaction.instrumentName}
      </span>
    </button>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={transaction.description ?? transaction.instrumentName}
        description={t('investmentTransactionDetail.title')}
        compactActions
        actions={
          <PageActions
            actions={[
              {
                label: t('common.edit'),
                icon: Pencil,
                primary: true,
                onClick: () => navigate(`${basePath}/${transaction.id}/edit`),
              },
              {
                label: t('transactionsCommon.clone'),
                icon: Copy,
                onClick: () => navigate(`${basePath}/new?cloneFrom=${transaction.id}`),
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
                  icon={isBuy ? <ArrowDownToLine /> : <ArrowUpToLine />}
                  tone={isBuy ? 'negative' : 'positive'}
                  amount={
                    <TransactionAmount
                      amount={signedTotal(transaction)}
                      currencySymbol={currencySymbol}
                      className={isBuy ? 'text-negative' : 'text-positive'}
                    />
                  }
                  subtitle={
                    <>
                      {t(`investmentTransactions.type_${transaction.type}`)} · {formatDateTime(transaction.occurredAt)}{' '}
                      · {formatTime24h(transaction.occurredAt)}
                      {/* Written in another timezone (e.g. abroad): the time is that place's. */}
                      {transaction.timezone !== user?.timezone && <> ({transaction.timezone.replace(/_/g, ' ')})</>}
                    </>
                  }
                />

                {/* Cash flows from the account into the instrument on a BUY, and back on a SELL. */}
                <DetailFlow from={isBuy ? accountLink : instrumentLink} to={isBuy ? instrumentLink : accountLink} />
              </>
            }
            fields={
              <DetailFields>
                <DetailField label={t('investmentTransactions.quantity')}>
                  <span className="tabular-nums">{formatQuantity(transaction.quantity)}</span>
                </DetailField>
                <DetailField label={t('investmentTransactions.unitPrice')}>
                  <span className="tabular-nums">{formatMoney(transaction.unitPrice, currencySymbol)}</span>
                </DetailField>
                {/* Quantity x unit price; with fees and taxes it adds up to the headline total, which isn't repeated here. */}
                <DetailField label={t('investmentTransactions.countervalue')}>
                  <span className="tabular-nums">{formatMoney(transaction.amount, currencySymbol)}</span>
                </DetailField>
                <DetailField label={t('investmentTransactions.fees')}>
                  <span className="tabular-nums">{formatMoney(transaction.fees, currencySymbol)}</span>
                </DetailField>
                <DetailField label={t('investmentTransactions.taxes')}>
                  <span className="tabular-nums">{formatMoney(transaction.taxes, currencySymbol)}</span>
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
