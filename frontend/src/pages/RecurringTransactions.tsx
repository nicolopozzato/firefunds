import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { isToday, isTomorrow } from 'date-fns'
import { CalendarClock, Plus } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import EmptyState from '@/components/EmptyState'
import Stat from '@/components/Stat'
import LoadFailed from '@/components/LoadFailed'
import RecurringKindIcon from '@/components/RecurringKindIcon'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSection, MobileListSkeleton } from '@/components/MobileList'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import PendingOccurrences from '@/components/PendingOccurrences'
import { Skeleton } from '@/components/ui/skeleton'
import { usePendingOccurrences, useRecurringTransactions, useUpcomingOccurrences } from '@/hooks/use-recurring'
import { useAuth } from '@/contexts/AuthContext'
import { describeSchedule, recurringAmountClass, signedRecurringAmount } from '@/lib/recurring'
import { currencySymbolFor, formatDayHeading, formatMoney, formatShortDate, parseDate } from '@/lib/utils'
import type { Occurrence, RecurringTransaction } from '@/types/recurring'

const UPCOMING_DAYS = 30

/**
 * The recurring transactions: what waits to be confirmed, what they add up to
 * each month, what comes in the next 30 days, and every one of them.
 */
export default function RecurringTransactions() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user } = useAuth()
  const currency = currencySymbolFor(user?.currency ?? '')
  const { data: recurring, isLoading, error, refetch } = useRecurringTransactions()
  const { data: upcoming } = useUpcomingOccurrences(UPCOMING_DAYS)
  const { data: pending } = usePendingOccurrences()

  if (!recurring && error) return <LoadFailed error={error} onRetry={() => void refetch()} />

  const active = (recurring ?? []).filter((one) => !one.paused && one.nextOccurrence !== null)
  // In the user's currency only: others would need a rate, and are rare here.
  const ownCurrency = active.filter((one) => currencySymbolFor(one.currency) === currency)
  const monthly = (kind: RecurringTransaction['kind']) =>
    ownCurrency.filter((one) => one.kind === kind).reduce((sum, one) => sum + Number(one.monthlyAmount), 0)

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={t('recurring.title')}
        description={t('recurring.description')}
        actions={
          // Phones add from the bottom bar's "+".
          <Button onClick={() => navigate('/transactions/recurring/new')} className="hidden md:inline-flex">
            <Plus className="mr-2 h-4 w-4" />
            {t('recurring.add')}
          </Button>
        }
      />


      {isLoading ? (
        <div className="space-y-4">
          <Skeleton className="h-24 w-full rounded-xl" />
          <MobileListSkeleton />
        </div>
      ) : !recurring || recurring.length === 0 ? (
        <Card>
          <EmptyState
            icon={CalendarClock}
            title={t('recurring.emptyTitle')}
            description={t('recurring.emptyDescription')}
            action={{ label: t('recurring.add'), onClick: () => navigate('/transactions/recurring/new') }}
          />
        </Card>
      ) : (
        <>
          {pending && pending.length > 0 && <PendingOccurrences occurrences={pending} />}

          <Card>
            <CardContent className="grid grid-cols-2 gap-x-6 gap-y-4 pt-4 sm:pt-5 lg:grid-cols-4">
              <Stat size="lg" label={t('recurring.monthlyExpenses')} valueClassName="text-negative" value={formatMoney(monthly('withdrawal'), currency)} />
              <Stat size="lg" label={t('recurring.monthlyIncome')} valueClassName="text-positive" value={formatMoney(monthly('deposit'), currency)} />
              <Stat size="lg" label={t('recurring.monthlyTransfers')} value={formatMoney(monthly('transfer'), currency)} />
              <Stat size="lg" label={t('recurring.active')} value={active.length} />
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-4 md:gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start">
            <section className="space-y-2">
              <h2 className="section-title px-1">{t('recurring.all')}</h2>
              <MobileListCard>
                {recurring.map((one) => (
                  <RecurringRow key={one.id} recurring={one} />
                ))}
              </MobileListCard>
            </section>

            <section className="space-y-2">
              <h2 className="section-title px-1">{t('recurring.upcoming', { days: UPCOMING_DAYS })}</h2>
              <UpcomingList occurrences={upcoming ?? []} />
            </section>
          </div>
        </>
      )}
    </div>
  )
}

function RecurringRow({ recurring }: { recurring: RecurringTransaction }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const ended = recurring.nextOccurrence === null

  return (
    <MobileListRow
      onClick={() => navigate(`/transactions/recurring/${recurring.id}`)}
      leading={
        <MobileListIcon>
          <RecurringKindIcon kind={recurring.kind} />
        </MobileListIcon>
      }
      title={
        <span className="flex items-center gap-2">
          <span className="truncate">{recurring.description}</span>
          {recurring.paused ? (
            <Badge variant="secondary" className="shrink-0 font-normal">{t('recurring.paused')}</Badge>
          ) : ended ? (
            <Badge variant="secondary" className="shrink-0 font-normal">{t('recurring.ended')}</Badge>
          ) : recurring.mode === 'confirm' ? (
            <Badge variant="info" className="shrink-0 font-normal">{t('recurring.mode_confirm')}</Badge>
          ) : null}
        </span>
      }
      subtitle={describeSchedule(recurring, t)}
      value={formatMoney(signedRecurringAmount(recurring.kind, recurring.nextAmount), currencySymbolFor(recurring.currency))}
      valueClassName={recurringAmountClass(recurring.kind)}
      valueDetail={!recurring.paused && recurring.nextOccurrence ? formatShortDate(recurring.nextOccurrence) : undefined}
    />
  )
}

/** The next days, by day: what will be created (or asked) and how much. */
function UpcomingList({ occurrences }: { occurrences: Occurrence[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  if (occurrences.length === 0) {
    return (
      <MobileListCard>
        <p className="px-4 py-6 text-center text-sm text-muted-foreground">{t('recurring.nothingUpcoming')}</p>
      </MobileListCard>
    )
  }

  const days = [...new Set(occurrences.map((occurrence) => occurrence.day))]
  const dayLabel = (day: string) => {
    const date = parseDate(day)
    if (isToday(date)) return t('transactionsCommon.today')
    if (isTomorrow(date)) return t('recurring.tomorrow')
    return formatDayHeading(date)
  }

  return (
    <div className="space-y-4">
      {days.map((day) => (
        <MobileListSection key={day} title={dayLabel(day)}>
          {occurrences
            .filter((occurrence) => occurrence.day === day)
            .map((occurrence) => (
              <MobileListRow
                key={`${occurrence.recurring.id}-${day}`}
                onClick={() => navigate(`/transactions/recurring/${occurrence.recurring.id}`)}
                leading={
                  <MobileListIcon>
                    <RecurringKindIcon kind={occurrence.recurring.kind} />
                  </MobileListIcon>
                }
                title={occurrence.recurring.description}
                subtitle={`${occurrence.time} · ${t(occurrence.recurring.mode === 'confirm' ? 'recurring.toConfirm' : 'recurring.automatic')}`}
                value={formatMoney(signedRecurringAmount(occurrence.recurring.kind, occurrence.amount), currencySymbolFor(occurrence.recurring.currency))}
                valueClassName={recurringAmountClass(occurrence.recurring.kind)}
              />
            ))}
        </MobileListSection>
      ))}
    </div>
  )
}
