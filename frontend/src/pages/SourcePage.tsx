import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCw, Trash2 } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import ConfirmDialog from '@/components/ConfirmDialog'
import FormSection from '@/components/FormSection'
import FormActions from '@/components/FormActions'
import Field from '@/components/Field'
import Combobox from '@/components/Combobox'
import LoadFailed from '@/components/LoadFailed'
import NotificationReadingBadge from '@/components/NotificationReadingBadge'
import SourceRulesField from '@/components/SourceRulesField'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useFormFill } from '@/hooks/use-form-fill'
import { useDebouncedValue } from '@/hooks/use-search'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { ruleRows, sourcesApi, type RuleRow, type SourceReading, type SourceRule } from '@/lib/sources-api'
import { formatShortDate, formatTime24h } from '@/lib/utils'

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
  const [rules, setRules] = useState<RuleRow[]>([])
  // The reading of the rules as they were when it was asked for: its stats belong to that order.
  const [preview, setPreview] = useState<{ of: string; reading: SourceReading } | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  useFormFill(source ? source.id : null, () => {
    if (!source) return
    setAccountId(source.accountId ?? '')
    setRules(ruleRows(source.rules))
  })

  // The rules as they are now (order included), tried on the source's notifications.
  const plain: SourceRule[] = rules.map(({ kind, pattern }) => ({ kind, pattern }))
  const rulesJson = JSON.stringify(plain)
  const typed = useDebouncedValue(rulesJson, 300)
  useEffect(() => {
    if (!source) return
    let current = true
    sourcesApi.preview(source.id, JSON.parse(typed) as SourceRule[]).then((reading) => current && setPreview({ of: typed, reading }), () => {})
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

  const notifications = preview?.reading.notifications ?? source.notifications
  const stats = preview ? (preview.of === rulesJson ? preview.reading.ruleStats : undefined) : JSON.stringify(source.rules) === rulesJson ? source.ruleStats : undefined

  const save = async (reread: boolean) => {
    setBusy(true)
    try {
      await sourcesApi.update(source.id, accountId || null, plain)
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
        <SourceRulesField sourceId={source.id} rules={rules} onChange={setRules} stats={stats} />
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
                  <NotificationReadingBadge notification={notification} />
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
