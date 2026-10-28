import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowDownLeft, ArrowUpRight, Pause, Pencil, Play, Repeat, Trash2 } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import TransactionAmount from '@/components/TransactionAmount'
import TransactionList from '@/components/TransactionList'
import PendingOccurrences from '@/components/PendingOccurrences'
import RecurringHistoryCard from '@/components/RecurringHistoryCard'
import EmptyState from '@/components/EmptyState'
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
  useRecurringTransaction,
  useRecurringTransactionHistory,
  useUpcomingOccurrences,
} from '@/hooks/use-recurring'
import { handleApiError } from '@/lib/error-handler'
import { describeSchedule, recurringAmountClass, signedRecurringAmount } from '@/lib/recurring'
import { currencySymbolFor, formatMoney, formatShortDate } from '@/lib/utils'
import type { RecurringKind } from '@/types/recurring'

const NEXT_SHOWN = 5

function KindIcon({ kind }: { kind: RecurringKind }) {
  if (kind === 'deposit') return <ArrowDownLeft />
  if (kind === 'withdrawal') return <ArrowUpRight />
  return <Repeat />
}

/**
 * A recurring transaction: what it creates and when, the next days it falls
 * on, what waits to be confirmed, and the transactions it already made.
 */
export default function RecurringTransactionDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id = '' } = useParams<{ id: string }>()
  const [page, setPage] = useState(1)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const { data: recurring, isLoading, error, refetch } = useRecurringTransaction(id)
  const { data: upcoming } = useUpcomingOccurrences(366)
  const { data: pending } = usePendingOccurrences()
  const { data: history, isLoading: isHistoryLoading } = useRecurringTransactionHistory(id, page)
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
  const tone = recurring.kind === 'withdrawal' ? 'negative' : recurring.kind === 'deposit' ? 'positive' : 'info'

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
                      currencySymbol={currencySymbolFor(recurring.currency)}
                      className={recurringAmountClass(recurring.kind)}
                    />
                  }
                  subtitle={
                    <>
                      {describeSchedule(recurring, t)} · {recurring.time}
                    </>
                  }
                />
                <DetailFlow from={<AccountSide account={recurring.sourceAccount} />} to={<AccountSide account={recurring.destinationAccount} />} />
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
                      .map((change) => `${t('recurring.changeFrom')} ${formatShortDate(change.from)}: ${formatMoney(change.amount, currencySymbolFor(recurring.currency))}`)
                      .join(' · ')}
                  </DetailField>
                )}
                <DetailField label={t('recurring.onTheDay')}>{t(`recurring.mode_${recurring.mode}`)}</DetailField>
                <DetailField label={t('recurring.perMonth')}>{formatMoney(recurring.monthlyAmount, currencySymbolFor(recurring.currency))}</DetailField>
                <DetailField label={t('transactionsCommon.category')}>
                  {recurring.categoryId && recurring.categoryName ? (
                    <Link to={`/categories/${recurring.categoryId}`}>
                      <Badge variant="outline" className="hover:bg-accent">{recurring.categoryName}</Badge>
                    </Link>
                  ) : (
                    <EmptyValue />
                  )}
                </DetailField>
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

      <RecurringHistoryCard recurringId={recurring.id} currencySymbol={currencySymbolFor(recurring.currency)} />

      <section className="space-y-2">
        <h2 className="section-title px-1">{t('recurring.created')}</h2>
        <TransactionList
          data={history}
          isLoading={isHistoryLoading}
          onPageChange={setPage}
          empty={<EmptyState title={t('recurring.noneCreated')} description={t('recurring.noneCreatedDescription')} />}
        />
      </section>

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
