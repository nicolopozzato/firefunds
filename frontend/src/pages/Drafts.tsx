import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowDownCircle, ArrowLeftRight, ArrowUpCircle, CheckCheck, CircleDashed, Inbox, Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/button'
import PageHeader from '@/components/PageHeader'
import EmptyState from '@/components/EmptyState'
import { MobileListCard, MobileListIcon, MobileListRow, MobileListSkeleton } from '@/components/MobileList'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { useAuth } from '@/contexts/AuthContext'
import { useAllClassification } from '@/hooks/use-classification'
import { useAcceptDraft, useDrafts, useRecentDrafts } from '@/hooks/use-drafts'
import { handleApiError } from '@/lib/error-handler'
import { sourcesApi } from '@/lib/sources-api'
import { currencySymbolFor, formatMoney, formatShortDate, formatTime24h } from '@/lib/utils'
import type { Draft } from '@/types/draft'
import type { CreateTransactionRequest } from '@/types/transaction'

export function DraftIcon({ draft }: { draft: Draft }) {
  if (draft.suggestion?.kind === 'transfer') return <ArrowLeftRight className="h-4 w-4 text-info" />
  if (draft.direction === 'in') return <ArrowDownCircle className="h-4 w-4 text-positive" />
  if (draft.direction === 'out') return <ArrowUpCircle className="h-4 w-4 text-negative" />
  return <CircleDashed className="h-4 w-4 text-muted-foreground" />
}

/**
 * The draft recorded as it most likely is, with nothing to choose: its own
 * account (else the default one) on one side, the suggested one on the
 * other. Null when something is missing or it may already be recorded.
 */
function sureTransaction(draft: Draft, defaultAccountId: string | null): CreateTransactionRequest | null {
  const suggestion = draft.suggestion
  const description = suggestion?.description ?? draft.description
  if (!suggestion?.account || !suggestion.kind || !draft.direction || draft.possibleDuplicate || !description) return null
  const incoming = draft.direction === 'in'
  const asset = (incoming ? draft.destinationAccount : draft.sourceAccount)?.id ?? defaultAccountId
  if (!asset) return null
  return {
    description,
    amount: draft.amount,
    occurredAt: draft.occurredAt,
    timezone: draft.timezone,
    sourceAccountId: incoming ? suggestion.account.id : asset,
    destinationAccountId: incoming ? asset : suggestion.account.id,
    categoryId: suggestion.categoryId,
    tagIds: [],
  }
}

/**
 * What came from outside (the bank's notifications, other programs) and
 * waits to become transactions, each with what it most likely is; those
 * with nothing to choose are recorded all at once. Notifications not
 * understood are pointed out with their source, and the latest dealt with
 * say what became of them.
 */
export default function Drafts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { user } = useAuth()
  const { data: drafts, isLoading } = useDrafts()
  const { data: recent } = useRecentDrafts()
  const { data: sources } = useQuery({ queryKey: ['sources'], queryFn: () => sourcesApi.list() })
  const { data: categories } = useAllClassification('categories')
  const accept = useAcceptDraft()
  const [recording, setRecording] = useState(false)

  const categoryName = (id: string | null) => (id ? categories?.items.find((category) => category.id === id)?.name : undefined)
  const sure = (drafts ?? []).map((draft) => [draft, sureTransaction(draft, user?.defaultAccountId ?? null)] as const).filter(([, request]) => request !== null)
  // Both legs of a transfer are one transaction: the second closes with the first.
  const toRecord = sure.filter(([draft]) => !sure.some(([other]) => other.id === draft.suggestion?.pairedDraftId && other.id < draft.id))
  const unread = (sources ?? []).filter((source) => (source.unread ?? 0) > 0)

  const recordSure = async () => {
    setRecording(true)
    let recorded = 0
    try {
      for (const [draft, request] of toRecord) {
        await accept.mutateAsync({ id: draft.id, parts: [request!] })
        recorded++
      }
      toast.success(t('drafts.accepted', { count: recorded }))
    } catch (recordError) {
      toast.error(handleApiError(recordError))
    } finally {
      setRecording(false)
    }
  }

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

      {unread.map((source) => (
        <button
          key={source.id}
          type="button"
          onClick={() => navigate(`/sources/${source.id}`)}
          className="flex w-full items-center gap-3 rounded-xl border border-warning/40 bg-warning/5 px-4 py-3 text-left text-sm hover:bg-warning/10"
        >
          <Smartphone className="h-4 w-4 shrink-0 text-warning" />
          <span className="min-w-0 flex-1">{t('drafts.notUnderstood', { count: source.unread, app: source.appName })}</span>
          <span className="shrink-0 font-medium text-primary">{t('drafts.teach')}</span>
        </button>
      ))}

      {isLoading ? (
        <MobileListSkeleton />
      ) : !drafts || drafts.length === 0 ? (
        <Card>
          <EmptyState icon={Inbox} title={t('drafts.emptyTitle')} description={t('drafts.emptyDescription')} />
        </Card>
      ) : (
        <>
          {toRecord.length > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">{t('drafts.sureHint', { count: toRecord.length })}</p>
              <Button disabled={recording} onClick={() => void recordSure()}>
                <CheckCheck className="mr-2 h-4 w-4" />
                {t('drafts.recordSure', { count: toRecord.length })}
              </Button>
            </div>
          )}
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
                    <span className="truncate">{draft.suggestion?.description ?? draft.description ?? draft.sourceName ?? t('drafts.untitled')}</span>
                    {draft.possibleDuplicate && <Badge variant="warning" className="shrink-0 font-normal">{t('drafts.maybeDuplicate')}</Badge>}
                  </span>
                }
                subtitle={[
                  `${formatShortDate(draft.occurredAt.slice(0, 10))} · ${formatTime24h(draft.occurredAt)}`,
                  draft.sourceName ?? t(`drafts.source_${draft.source}`, { defaultValue: draft.source }),
                ].join(' · ')}
                extra={
                  draft.suggestion?.account ? (
                    <span className="text-xs text-muted-foreground">
                      {draft.suggestion.kind === 'transfer' ? t('drafts.suggestedTransfer', { account: draft.suggestion.account.name }) : `→ ${draft.suggestion.account.name}`}
                      {categoryName(draft.suggestion.categoryId) && ` · ${categoryName(draft.suggestion.categoryId)}`}
                    </span>
                  ) : undefined
                }
                value={formatMoney(draft.direction === 'out' ? -Number(draft.amount) : Number(draft.amount), currencySymbolFor(draft.currency))}
                valueClassName={draft.direction === 'out' ? 'text-negative' : draft.direction === 'in' ? 'text-positive' : undefined}
              />
            ))}
          </MobileListCard>
        </>
      )}

      {recent && recent.length > 0 && (
        <section className="space-y-2">
          <h2 className="section-title">{t('drafts.recentTitle')}</h2>
          <MobileListCard>
            {recent.map((draft) => (
              <MobileListRow
                key={draft.id}
                onClick={() => navigate(draft.transactionIds[0] ? `/transactions/${draft.transactionIds[0]}` : `/transactions/drafts/${draft.id}`)}
                leading={
                  <MobileListIcon>
                    <DraftIcon draft={draft} />
                  </MobileListIcon>
                }
                title={
                  <span className="flex items-center gap-2">
                    <span className="truncate">{draft.suggestion?.description ?? draft.description ?? draft.sourceName ?? t('drafts.untitled')}</span>
                    <Badge variant="secondary" className="shrink-0 font-normal">{t(`sources.draft_${draft.status}`)}</Badge>
                  </span>
                }
                subtitle={`${formatShortDate(draft.occurredAt.slice(0, 10))} · ${formatTime24h(draft.occurredAt)}`}
                value={formatMoney(draft.direction === 'out' ? -Number(draft.amount) : Number(draft.amount), currencySymbolFor(draft.currency))}
                valueClassName="text-muted-foreground"
              />
            ))}
          </MobileListCard>
        </section>
      )}
    </div>
  )
}
