import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Plus, RotateCw, Trash2, X } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import ConfirmDialog from '@/components/ConfirmDialog'
import FormSection from '@/components/FormSection'
import FormActions from '@/components/FormActions'
import Field from '@/components/Field'
import Combobox from '@/components/Combobox'
import LoadFailed from '@/components/LoadFailed'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import SegmentedControl from '@/components/ui/segmented-control'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useFormFill } from '@/hooks/use-form-fill'
import { useDebouncedValue } from '@/hooks/use-search'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { sourcesApi, type RuleKind, type SourceNotification, type SourceRule } from '@/lib/sources-api'
import { cn, formatMoney, formatShortDate, formatTime24h } from '@/lib/utils'

/**
 * One app's notifications: the account its payments are on, and the rules
 * that read them, tried live on its real notifications before saving (the
 * generic reading works for many banks; a rule is for what it misses, or
 * to ignore what isn't a payment). Saved, "read again" turns the ones now
 * understood into drafts.
 */
export default function SourcePage() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data: source, error, refetch } = useQuery({ meta: { quietError: true }, queryKey: ['sources', id], queryFn: () => sourcesApi.get(id) })
  const { data: assets } = useAllAccounts('asset')
  const [accountId, setAccountId] = useState('')
  const [rules, setRules] = useState<SourceRule[]>([])
  const [preview, setPreview] = useState<SourceNotification[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  useFormFill(source ? source.id : null, () => {
    if (!source) return
    setAccountId(source.accountId ?? '')
    setRules(source.rules)
  })

  // The rules as they are being written, tried on the latest notifications.
  const typed = useDebouncedValue(JSON.stringify(rules), 400)
  useEffect(() => {
    if (!source) return
    const complete = (JSON.parse(typed) as SourceRule[]).filter((rule) => rule.pattern.trim() !== '')
    let current = true
    sourcesApi.preview(source.id, complete).then((result) => current && setPreview(result), () => {})
    return () => {
      current = false
    }
  }, [typed, source])

  if (!source && error) return <LoadFailed error={error} onRetry={() => void refetch()} />
  if (!source) {
    return (
      <div className="max-w-3xl space-y-6">
        <PageHeader title={t('sources.title')} />
        <Skeleton className="h-48 w-full rounded-xl" />
      </div>
    )
  }

  const notifications = preview ?? source.notifications
  const update = (index: number, patch: Partial<SourceRule>) => setRules((current) => current.map((rule, i) => (i === index ? { ...rule, ...patch } : rule)))

  const save = async (reread: boolean) => {
    setBusy(true)
    try {
      await sourcesApi.update(source.id, accountId || null, rules.filter((rule) => rule.pattern.trim() !== ''))
      if (reread) {
        const drafts = await sourcesApi.reread(source.id)
        toast.success(t('sources.reread', { count: drafts }))
        void dataChanged(queryClient)
      } else {
        toast.success(t('sources.saved'))
        void queryClient.invalidateQueries({ queryKey: ['sources'] })
      }
    } catch (saveError) {
      toast.error(handleApiError(saveError))
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    setBusy(true)
    try {
      await sourcesApi.remove(source.id)
      toast.success(t('sources.deleted'))
      void dataChanged(queryClient)
      navigate('/sources')
    } catch (deleteError) {
      toast.error(handleApiError(deleteError))
      setBusy(false)
    }
  }

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={source.appName}
        description={source.packageName}
        compactActions
        actions={<PageActions actions={[{ label: t('common.delete'), icon: Trash2, destructive: true, onClick: () => setConfirmDelete(true) }]} />}
      />

      <FormSection title={t('sources.account')}>
        <Field label={t('sources.accountLabel')} hint={t('sources.accountHint')}>
          <Combobox
            options={(assets?.items ?? []).map((item) => ({ value: item.id, label: item.name }))}
            value={accountId}
            onChange={setAccountId}
            placeholder={t('transactionForm.selectAccount')}
            clearable
          />
        </Field>
      </FormSection>

      <FormSection title={t('sources.rules')} optional contentClassName="space-y-3">
        <p className="text-sm text-muted-foreground">{t('sources.rulesHint')}</p>
        {rules.map((rule, index) => (
          <div key={index} className="flex flex-wrap items-center gap-2 rounded-lg border p-2 sm:flex-nowrap">
            <SegmentedControl
              label={t('sources.kind')}
              value={rule.kind}
              onChange={(kind: RuleKind) => update(index, { kind })}
              options={[
                { value: 'out', label: t('sources.kind_out') },
                { value: 'in', label: t('sources.kind_in') },
                { value: 'ignore', label: t('sources.kind_ignore') },
              ]}
            />
            <Input
              className="min-w-0 flex-1 font-mono text-xs"
              placeholder={t(rule.kind === 'ignore' ? 'sources.patternIgnore' : 'sources.pattern')}
              value={rule.pattern}
              onChange={(e) => update(index, { pattern: e.target.value })}
            />
            <Button variant="ghost" size="icon" aria-label={t('sources.removeRule')} title={t('sources.removeRule')} onClick={() => setRules((current) => current.filter((_, i) => i !== index))}>
              <X className="h-4 w-4" />
            </Button>
          </div>
        ))}
        <button type="button" onClick={() => setRules((current) => [...current, { kind: 'out', pattern: '' }])} className="flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80">
          <Plus className="h-4 w-4" />
          {t('sources.addRule')}
        </button>
      </FormSection>

      <FormSection title={t('sources.latestNotifications')}>
        {notifications.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('sources.noNotifications')}</p>
        ) : (
          <ul className="divide-y">
            {notifications.map((notification) => (
              <li key={notification.id} className="space-y-1.5 py-3 first:pt-0 last:pb-0">
                <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                  <span>
                    {formatShortDate(notification.postedAt.slice(0, 10))} · {formatTime24h(notification.postedAt)}
                  </span>
                  <ReadingBadge notification={notification} />
                </div>
                <p className="whitespace-pre-wrap font-mono text-xs">{[notification.title, notification.text].filter(Boolean).join('\n')}</p>
              </li>
            ))}
          </ul>
        )}
      </FormSection>

      <FormActions>
        <Button variant="outline" disabled={busy} onClick={() => void save(false)}>
          {t('common.save')}
        </Button>
        <Button disabled={busy} onClick={() => void save(true)}>
          <RotateCw className="mr-2 h-4 w-4" />
          {t('sources.saveAndReread')}
        </Button>
      </FormActions>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t('sources.deleteTitle', { name: source.appName })}
        description={t('sources.deleteDescription')}
        onConfirm={remove}
        pending={busy}
      />
    </div>
  )
}

/** What the rules make of a notification now, and what it already became. */
function ReadingBadge({ notification }: { notification: SourceNotification }) {
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
      <Badge variant={reading.kind === 'out' ? 'destructive' : 'success'} className={cn('min-w-0 truncate font-normal')}>
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
