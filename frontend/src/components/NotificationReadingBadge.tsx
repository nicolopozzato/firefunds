import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/badge'
import type { SourceNotification } from '@/lib/sources-api'
import { formatMoney } from '@/lib/utils'

/** What the rules make of a notification now, and what it already became. */
export default function NotificationReadingBadge({ notification }: { notification: SourceNotification }) {
  const { t } = useTranslation()
  const reading = notification.reading
  const done = notification.draft ? <DraftBadge draft={notification.draft} /> : null
  if (!reading) {
    return (
      <span className="flex gap-1.5">
        {done}
        <Badge variant="warning" className="font-normal">{t('sources.notUnderstood')}</Badge>
      </span>
    )
  }
  if (reading.kind === 'ignore') {
    return <Badge variant="secondary" className="font-normal">{t('sources.ignored')}</Badge>
  }
  return (
    <span className="flex min-w-0 gap-1.5">
      {done}
      <Badge variant={reading.kind === 'out' ? 'destructive' : 'success'} className="min-w-0 truncate font-normal">
        {formatMoney(reading.kind === 'out' ? -Number(reading.amount) : Number(reading.amount))}
        {reading.merchant && ` · ${reading.merchant}`}
        {reading.rule === null ? ` · ${t('sources.generic')}` : ` · ${t('sources.byRule', { number: reading.rule + 1 })}`}
      </Badge>
    </span>
  )
}

/** What the draft a notification became is now, opening it or its transaction. */
function DraftBadge({ draft }: { draft: NonNullable<SourceNotification['draft']> }) {
  const { t } = useTranslation()
  const to = draft.status === 'pending' ? `/transactions/drafts/${draft.id}` : draft.transactionId ? `/transactions/${draft.transactionId}` : null
  const badge = (
    <Badge variant={draft.status === 'pending' ? 'info' : 'secondary'} className="shrink-0 font-normal">
      {t(`sources.draft_${draft.status}`)}
    </Badge>
  )

  return to ? <Link to={to} className="shrink-0 hover:opacity-80">{badge}</Link> : badge
}
