import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowDownCircle, ArrowUpCircle, CircleDashed, Inbox, Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/button'
import PageHeader from '@/components/PageHeader'
import EmptyState from '@/components/EmptyState'
import { TransactionSectionTabs } from '@/components/layout/SectionTabs'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { useDrafts } from '@/hooks/use-drafts'
import { currencySymbolFor, formatMoney, formatShortDate, formatTime24h } from '@/lib/utils'
import type { Draft } from '@/types/draft'

export function DraftIcon({ draft }: { draft: Draft }) {
  if (draft.direction === 'in') return <ArrowDownCircle className="h-4 w-4 text-positive" />
  if (draft.direction === 'out') return <ArrowUpCircle className="h-4 w-4 text-negative" />
  return <CircleDashed className="h-4 w-4 text-muted-foreground" />
}

/** What came from outside (the bank's notifications, other programs) and waits to become transactions. */
export default function Drafts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data: drafts, isLoading } = useDrafts()

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={t('drafts.title')}
        description={t('drafts.description')}
        actions={
          <Button variant="outline" onClick={() => navigate('/sources')}>
            <Smartphone className="mr-2 h-4 w-4" />
            {t('sources.title')}
          </Button>
        }
      />
      <TransactionSectionTabs />

      {isLoading ? (
        <MobileListSkeleton />
      ) : !drafts || drafts.length === 0 ? (
        <Card>
          <EmptyState icon={Inbox} title={t('drafts.emptyTitle')} description={t('drafts.emptyDescription')} />
        </Card>
      ) : (
        <MobileListCard>
          {drafts.map((draft) => (
            <MobileListRow
              key={draft.id}
              onClick={() => navigate(`/transactions/drafts/${draft.id}`)}
              leading={
                <MobileListIcon>
                  <DraftIcon draft={draft} />
                </MobileListIcon>
              }
              title={
                <span className="flex items-center gap-2">
                  <span className="truncate">{draft.description ?? draft.sourceName ?? t('drafts.untitled')}</span>
                  {draft.possibleDuplicate && <Badge variant="warning" className="shrink-0 font-normal">{t('drafts.maybeDuplicate')}</Badge>}
                </span>
              }
              subtitle={[
                `${formatShortDate(draft.occurredAt.slice(0, 10))} · ${formatTime24h(draft.occurredAt)}`,
                draft.sourceName ?? t(`drafts.source_${draft.source}`, { defaultValue: draft.source }),
              ].join(' · ')}
              value={formatMoney(draft.direction === 'out' ? -Number(draft.amount) : Number(draft.amount), currencySymbolFor(draft.currency))}
              valueClassName={draft.direction === 'out' ? 'text-negative' : draft.direction === 'in' ? 'text-positive' : undefined}
            />
          ))}
        </MobileListCard>
      )}
    </div>
  )
}
