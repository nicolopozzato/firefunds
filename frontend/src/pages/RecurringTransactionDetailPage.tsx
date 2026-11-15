import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowDownLeft, ArrowUpRight, Pause, Pencil, Play, Repeat, SkipForward, Trash2, TrendingUp } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import TransactionAmount from '@/components/TransactionAmount'
import TransactionList from '@/components/TransactionList'
import PendingOccurrences from '@/components/PendingOccurrences'
import RecurringHistoryCard from '@/components/RecurringHistoryCard'
import EmptyState from '@/components/EmptyState'
import { MobileListCard, MobileListRow } from '@/components/MobileList'
import Pagination from '@/components/Pagination'
import EmptyValue from '@/components/EmptyValue'
import LoadFailed from '@/components/LoadFailed'
import ConfirmDialog from '@/components/ConfirmDialog'
import { AccountSide, DetailField, DetailFields, DetailFlow, DetailHeadline, DetailLayout } from '@/components/TransactionDetail'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useDeleteRecurring,
  usePauseRecurring,
  usePendingOccurrences,
  useRecurringPurchaseHistory,
  useRecurringTransaction,
  useRecurringTransactionHistory,
  useSkipOccurrence,
  useUpcomingOccurrences,
} from '@/hooks/use-recurring'
import { handleApiError } from '@/lib/error-handler'
import { describeSchedule, recurringAmountClass, signedRecurringAmount } from '@/lib/recurring'
import { currencySymbolFor, formatMoney, formatQuantity, formatShortDate } from '@/lib/utils'
import type { RecurringKind, RecurringTransaction } from '@/types/recurring'

const NEXT_SHOWN = 5

function KindIcon({ kind }: { kind: RecurringKind }) {
  if (kind === 'deposit') return <ArrowDownLeft />
  if (kind === 'withdrawal') return <ArrowUpRight />
  if (kind === 'purchase') return <TrendingUp />
  return <Repeat />
}

/**
 * A recurring transaction: what it creates and when, the next days it falls
 * on, what waits to be confirmed, and the transactions it already made. A
 * recurring purchase (PAC): what it buys, what the latest price says it
 * would, and the purchases made.
 */
export default function RecurringTransactionDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id = '' } = useParams<{ id: string }>()
  const [page, setPage] = useState(1)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [confirmSkip, setConfirmSkip] = useState(false)
  const { data: recurring, isLoading, error, refetch } = useRecurringTransaction(id)
  const isPurchase = recurring?.kind === 'purchase'
  const { data: upcoming } = useUpcomingOccurrences(366)
  const { data: pending } = usePendingOccurrences()
  const { data: history, isLoading: isHistoryLoading } = useRecurringTransactionHistory(isPurchase ? '' : id, page)
  const { data: purchases, isLoading: isPurchasesLoading } = useRecurringPurchaseHistory(id, page, isPurchase)
  const skip = useSkipOccurrence()
  const pause = usePauseRecurring()
  const remove = useDeleteRecurring()

  if (!recurring && error) return <LoadFailed error={error} onRetry={() => void refetch()} />

  if (isLoading || !recurring) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('recurring.title')} />
        <Skeleton className="h-48 w-full rounded-xl" />
      </div>
    )
  }

  const next = (upcoming ?? []).filter((occurrence) => occurrence.recurring.id === recurring.id).slice(0, NEXT_SHOWN)
  const waiting = (pending ?? []).filter((occurrence) => occurrence.recurring.id === recurring.id)
  const tone = recurring.kind === 'withdrawal' ? 'negative' : recurring.kind === 'deposit' ? 'positive' : recurring.kind === 'purchase' ? 'invest' : 'info'
  // The occurrence a skip lets go: the first waiting, else the next to come.
  const toSkip = waiting[0]?.day ?? (!recurring.paused ? recurring.nextOccurrence : null)
  const symbol = currencySymbolFor(recurring.currency)

  const handleSkip = async () => {
    try {
      await skip.mutateAsync(recurring.id)
      setConfirmSkip(false)
      toast.success(t('recurring.skipped'))
    } catch (skipError) {
      toast.error(handleApiError(skipError))
    }
  }

  const togglePause = async () => {
    try {
      await pause.mutateAsync({ id: recurring.id, paused: !recurring.paused })
      toast.success(t(recurring.paused ? 'recurring.resumed' : 'recurring.pausedToast'))
    } catch (pauseError) {
      toast.error(handleApiError(pauseError))
    }
  }

  const handleDelete = async () => {
    try {
      await remove.mutateAsync(recurring.id)
      navigate('/transactions/recurring')
    } catch (deleteError) {
      toast.error(handleApiError(deleteError))
    }
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={recurring.description}
        compactActions
        actions={
          <PageActions
            actions={[
              { label: t('common.edit'), icon: Pencil, primary: true, onClick: () => navigate(`/transactions/recurring/${recurring.id}/edit`) },
              recurring.paused
                ? { label: t('recurring.resume'), icon: Play, onClick: () => void togglePause() }
                : { label: t('recurring.pause'), icon: Pause, onClick: () => void togglePause() },
              ...(toSkip ? [{ label: t('recurring.skipNext'), icon: SkipForward, onClick: () => setConfirmSkip(true) }] : []),
              { label: t('common.delete'), icon: Trash2, destructive: true, onClick: () => setConfirmDelete(true) },
            ]}
          />
        }
      />

      {waiting.length > 0 && <PendingOccurrences occurrences={waiting} />}

      <Card>
        <CardContent className="pt-4 sm:pt-5">
          <DetailLayout
            summary={
              <>
                <DetailHeadline
                  icon={<KindIcon kind={recurring.kind} />}
                  tone={tone}
                  amount={
                    <TransactionAmount
                      amount={signedRecurringAmount(recurring.kind, recurring.nextAmount)}
                      currencySymbol={symbol}
                      className={recurringAmountClass(recurring.kind)}
                    />
                  }
                  subtitle={
                    <>
                      {describeSchedule(recurring, t)} · {recurring.time}
                    </>
                  }
                />
                <DetailFlow
                  from={<AccountSide account={recurring.sourceAccount} />}
                  to={
                    recurring.instrument ? (
                      <Link to={`/investments/instruments/${recurring.instrument.id}`} className="flex items-center gap-1.5 font-medium text-link">
                        <TrendingUp className="h-4 w-4 text-invest" />
                        {recurring.instrument.name}
                      </Link>
                    ) : (
                      <AccountSide account={recurring.destinationAccount} />
                    )
                  }
                />
              </>
            }
            fields={
              <DetailFields>
                <DetailField label={t('recurring.next')}>
                  {recurring.paused ? (
                    <Badge variant="secondary">{t('recurring.paused')}</Badge>
                  ) : recurring.nextOccurrence ? (
                    formatShortDate(recurring.nextOccurrence)
                  ) : (
                    <Badge variant="secondary">{t('recurring.ended')}</Badge>
                  )}
                </DetailField>
                {recurring.amountChanges.length > 0 && (
                  <DetailField label={t('recurring.amountChanges')} wide>
                    {recurring.amountChanges
                      .map((change) => `${t('recurring.changeFrom')} ${formatShortDate(change.from)}: ${formatMoney(change.amount, symbol)}`)
                      .join(' · ')}
                  </DetailField>
                )}
                {isPurchase && <PlanFields recurring={recurring} />}
                <DetailField label={t('recurring.onTheDay')}>{t(`recurring.mode_${recurring.mode}`)}</DetailField>
                {recurring.estimateCount !== null && (
                  <DetailField label={t('recurring.estimatedAmount')}>
                    {recurring.estimatedAmount !== null
                      ? t('recurring.estimatedFrom', { count: recurring.estimateCount })
                      : t('recurring.estimatedNotYet', { amount: formatMoney(recurring.amount, symbol) })}
                  </DetailField>
                )}
                <DetailField label={t('recurring.perMonth')}>{formatMoney(recurring.monthlyAmount, symbol)}</DetailField>
                {!isPurchase && (
                  <DetailField label={t('transactionsCommon.category')}>
                    {recurring.categoryId && recurring.categoryName ? (
                      <Link to={`/categories/${recurring.categoryId}`}>
                        <Badge variant="outline" className="hover:bg-accent">{recurring.categoryName}</Badge>
                      </Link>
                    ) : (
                      <EmptyValue />
                    )}
                  </DetailField>
                )}
                {recurring.tags.length > 0 && (
                  <DetailField label={t('transactionsCommon.tags')}>
                    <span className="inline-flex flex-wrap gap-1 max-sm:justify-end">
                      {recurring.tags.map((tag) => (
                        <Link key={tag.id} to={`/tags/${tag.id}`}>
                          <Badge variant="secondary" className="hover:bg-accent">{tag.name}</Badge>
                        </Link>
                      ))}
                    </span>
                  </DetailField>
                )}
                {!recurring.paused && next.length > 1 && (
                  <DetailField label={t('recurring.nextDays')} wide>
                    {next.map((occurrence) => formatShortDate(occurrence.day)).join(' · ')}
                  </DetailField>
                )}
                {recurring.notes && (
                  <DetailField label={t('classificationCommon.notes')} wide>
                    <p className="whitespace-pre-wrap">{recurring.notes}</p>
                  </DetailField>
                )}
              </DetailFields>
            }
          />
        </CardContent>
      </Card>

      <RecurringHistoryCard recurringId={recurring.id} currencySymbol={symbol} />

      <section className="space-y-2">
        <h2 className="section-title px-1">{t(isPurchase ? 'recurring.purchasesMade' : 'recurring.created')}</h2>
        {isPurchase ? (
          <PurchaseList data={purchases} isLoading={isPurchasesLoading} onPageChange={setPage} />
        ) : (
          <TransactionList
            data={history}
            isLoading={isHistoryLoading}
            onPageChange={setPage}
            empty={<EmptyState title={t('recurring.noneCreated')} description={t('recurring.noneCreatedDescription')} />}
          />
        )}
      </section>

      <ConfirmDialog
        open={confirmSkip}
        onOpenChange={setConfirmSkip}
        title={t('recurring.skipNextTitle')}
        description={toSkip ? t('recurring.skipNextDescription', { day: formatShortDate(toSkip) }) : ''}
        confirmLabel={t('recurring.skip')}
        onConfirm={() => void handleSkip()}
        pending={skip.isPending}
      />

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t('recurring.deleteTitle')}
        description={t('recurring.deleteDescription')}
        onConfirm={() => void handleDelete()}
        pending={remove.isPending}
      />
    </div>
  )
}

/** A recurring purchase: how it buys, the fee, and what the latest price says the next one buys. */
function PlanFields({ recurring }: { recurring: RecurringTransaction }) {
  const { t } = useTranslation()
  const estimate = recurring.estimate
  const symbol = currencySymbolFor(recurring.currency)

  return (
    <>
      <DetailField label={t('recurring.planMode')}>
        {t(`recurring.planMode_${recurring.planMode}`)}
        {recurring.planMode === 'units' && recurring.quantity && ` · ${formatQuantity(Number(recurring.quantity))}`}
      </DetailField>
      <DetailField label={t('investmentTransactionForm.fees')}>{formatMoney(recurring.fees ?? '0', symbol)}</DetailField>
      <DetailField label={t('recurring.estimate')} wide>
        {estimate?.quantity && estimate.unitPrice && estimate.priceDate ? (
          t('recurring.estimateText', {
            quantity: formatQuantity(Number(estimate.quantity)),
            price: formatMoney(estimate.unitPrice, currencySymbolFor(recurring.instrument?.currency ?? recurring.currency)),
            amount: formatMoney(estimate.amount ?? '0', symbol),
            date: formatShortDate(estimate.priceDate),
          })
        ) : (
          <span className="text-muted-foreground">{t('recurring.estimateNoPrice')}</span>
        )}
      </DetailField>
    </>
  )
}

/** The purchases a plan made, newest first: each opens its trade. */
function PurchaseList({
  data,
  isLoading,
  onPageChange,
}: {
  data: ReturnType<typeof useRecurringPurchaseHistory>['data']
  isLoading: boolean
  onPageChange: (page: number) => void
}) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  if (isLoading && !data) return <Skeleton className="h-32 w-full rounded-xl" />
  if (!data || data.items.length === 0) {
    return (
      <Card>
        <EmptyState title={t('recurring.noPurchases')} description={t('recurring.noPurchasesDescription')} />
      </Card>
    )
  }

  return (
    <div className="space-y-2">
      <MobileListCard>
        {data.items.map((trade) => (
          <MobileListRow
            key={trade.id}
            onClick={() => navigate(`/transactions/investments/${trade.id}`)}
            title={formatShortDate(trade.occurredAt.slice(0, 10))}
            subtitle={`${formatQuantity(Number(trade.quantity))} × ${formatMoney(trade.unitPrice, currencySymbolFor(trade.unitPriceCurrency))}`}
            value={formatMoney(trade.totalAmount, currencySymbolFor(trade.currency))}
            valueClassName="text-invest"
          />
        ))}
      </MobileListCard>
      {data.total > data.pageSize && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={onPageChange} />}
    </div>
  )
}
