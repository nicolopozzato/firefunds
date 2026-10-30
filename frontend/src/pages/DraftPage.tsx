import { useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Check, Link2, Scissors, Trash2, X } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import FormActions from '@/components/FormActions'
import Field from '@/components/Field'
import Combobox from '@/components/Combobox'
import DescriptionField from '@/components/DescriptionField'
import LoadFailed from '@/components/LoadFailed'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import SegmentedControl from '@/components/ui/segmented-control'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useAllClassification } from '@/hooks/use-classification'
import { useAcceptDraft, useDiscardDraft, useDraft, useLinkDraft, usePayeeHistory } from '@/hooks/use-drafts'
import { useFormFill } from '@/hooks/use-form-fill'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import { cn, currencySymbolFor, formatAccountBalance, formatMoney, formatShortDate, formatTime24h } from '@/lib/utils'
import type { AccountType } from '@/types/account'
import type { Draft } from '@/types/draft'
import type { AccountRef, CreateTransactionRequest, Transaction } from '@/types/transaction'

type Kind = 'withdrawal' | 'deposit' | 'transfer'

const SIDES: Record<Kind, { source: AccountType; destination: AccountType }> = {
  withdrawal: { source: 'asset', destination: 'expense' },
  deposit: { source: 'revenue', destination: 'asset' },
  transfer: { source: 'asset', destination: 'asset' },
}

const NEW_PREFIX = 'NEW:'
const isPending = (id: string) => id.startsWith(NEW_PREFIX)
const pendingName = (id: string) => id.slice(NEW_PREFIX.length)

interface Part {
  key: number
  kind: Kind
  amount: string
  description: string
  sourceId: string
  destinationId: string
  categoryId: string
}

/** The first part: the draft as it came, on the user's default account when it says none, completed with what it most likely is. */
function firstPart(draft: Draft, defaultAccountId: string | null): Part {
  const suggestion = draft.suggestion
  const kind: Kind = suggestion?.kind ?? (draft.direction === 'in' ? 'deposit' : 'withdrawal')
  const incoming = draft.direction === 'in' || (draft.direction === null && kind === 'deposit')
  const asset = (incoming ? draft.destinationAccount : draft.sourceAccount)?.id ?? defaultAccountId ?? ''
  const other = (incoming ? draft.sourceAccount : draft.destinationAccount)?.id ?? suggestion?.account?.id ?? ''
  return {
    key: 0,
    kind,
    amount: String(Number(draft.amount)),
    description: suggestion?.description ?? draft.description ?? '',
    sourceId: incoming ? other : asset,
    destinationId: incoming ? asset : other,
    categoryId: suggestion?.categoryId ?? '',
  }
}

/**
 * Reviewing a draft: what arrived (its original text), an identical
 * transaction it may be, and the transactions it becomes. Split it into as
 * many as needed, each with its amount (the user decides: nothing has to add
 * up, e.g. 25 of a 50 dinner was a friend's, recorded as taken out).
 */
export default function DraftPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id = '' } = useParams<{ id: string }>()
  const { user } = useAuth()
  const { data: draft, isLoading, error, refetch } = useDraft(id)
  const accept = useAcceptDraft()
  const link = useLinkDraft()
  const discard = useDiscardDraft()
  const [parts, setParts] = useState<Part[]>([])
  const [occurredAt, setOccurredAt] = useState('')

  useFormFill(draft ? draft.id : null, () => {
    if (!draft) return
    setParts([firstPart(draft, user?.defaultAccountId ?? null)])
    setOccurredAt(draft.occurredAt.slice(0, 16))
  })

  const { data: assets } = useAllAccounts('asset')
  const { data: expenses } = useAllAccounts('expense')
  const { data: revenues } = useAllAccounts('revenue')
  const { data: categories } = useAllClassification('categories')
  const byType = useMemo(() => ({ asset: assets?.items ?? [], expense: expenses?.items ?? [], revenue: revenues?.items ?? [] }), [assets, expenses, revenues])

  if (!draft && error) return <LoadFailed error={error} onRetry={() => void refetch()} />
  if (isLoading || !draft || parts.length === 0) {
    return (
      <div className="max-w-3xl space-y-6">
        <PageHeader title={t('drafts.reviewTitle')} />
        <Skeleton className="h-40 w-full rounded-xl" />
      </div>
    )
  }

  const symbol = currencySymbolFor(draft.currency)
  const assigned = parts.reduce((sum, part) => sum + (Number(part.amount) || 0), 0)
  const update = (key: number, patch: Partial<Part>) => setParts((current) => current.map((part) => (part.key === key ? { ...part, ...patch } : part)))
  const closed = draft.status !== 'pending'
  const arrivedSection = (
    <FormSection title={t('drafts.arrived')} aside={<span className={cn('figure-md', draft.direction === 'out' ? 'text-negative' : draft.direction === 'in' ? 'text-positive' : '')}>{formatMoney(draft.direction === 'out' ? -Number(draft.amount) : Number(draft.amount), symbol)}</span>}>
      <div className="space-y-2 text-sm">
        <div className="text-muted-foreground">
          {formatShortDate(draft.occurredAt.slice(0, 10))} · {formatTime24h(draft.occurredAt)} · {draft.sourceName ?? t(`drafts.source_${draft.source}`, { defaultValue: draft.source })}
        </div>
        {draft.rawText && <p className="whitespace-pre-wrap rounded-lg bg-muted/50 px-3 py-2 font-mono text-xs">{draft.rawText}</p>}
        {draft.notes && <p className="whitespace-pre-wrap">{draft.notes}</p>}
      </div>
    </FormSection>
  )

  // Dealt with: what arrived and what became of it, nothing left to change.
  if (closed) {
    return (
      <div className="max-w-3xl space-y-6">
        <PageHeader title={t('drafts.reviewTitle')} />
        {arrivedSection}
        <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 px-4 py-3">
          <p className="min-w-0 flex-1 text-sm">{t(`drafts.closed_${draft.status}`)}</p>
          {draft.transactionIds.map((transactionId, index) => (
            <Button key={transactionId} variant="outline" onClick={() => navigate(`/transactions/${transactionId}`)}>
              {draft.transactionIds.length > 1 ? t('drafts.openTransactionNumber', { number: index + 1 }) : t('drafts.openTransaction')}
            </Button>
          ))}
        </div>
      </div>
    )
  }

  const split = () =>
    setParts((current) => {
      const last = current[current.length - 1]
      const rest = Math.max(0, Number(draft.amount) - assigned)
      return [...current, { ...last, key: Math.max(...current.map((p) => p.key)) + 1, amount: rest > 0 ? String(Math.round(rest * 100) / 100) : '', description: '', categoryId: '' }]
    })

  const toRequest = (part: Part): CreateTransactionRequest => {
    const side = (value: string) => (isPending(value) ? { id: null, name: pendingName(value) } : { id: value || null, name: null })
    const source = side(part.sourceId)
    const destination = side(part.destinationId)
    const category = side(part.categoryId)
    return {
      description: part.description.trim() || null,
      amount: part.amount,
      occurredAt: `${occurredAt}:00`,
      timezone: draft.timezone,
      sourceAccountId: source.id,
      newSourceAccountName: source.name,
      destinationAccountId: destination.id,
      newDestinationAccountName: destination.name,
      categoryId: category.id,
      newCategoryName: category.name,
      tagIds: [],
    }
  }

  const done = (message: string) => {
    toast.success(message)
    navigate('/transactions/drafts')
  }

  const run = async (action: () => Promise<unknown>, message: string) => {
    try {
      await action()
      done(message)
    } catch (actionError) {
      toast.error(handleApiError(actionError))
    }
  }

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader title={t('drafts.reviewTitle')} description={t('drafts.reviewDescription')} />

      {arrivedSection}

      {draft.possibleDuplicate && (
        <DuplicateCard
          transaction={draft.possibleDuplicate}
          busy={link.isPending}
          onLink={() => void run(() => link.mutateAsync({ id: draft.id, transactionId: draft.possibleDuplicate!.id }), t('drafts.linked'))}
        />
      )}

      {draft.suggestion?.pairedDraftId && parts[0].kind === 'transfer' && (
        <p className="rounded-xl border border-info/40 bg-info/5 px-4 py-3 text-sm">{t('drafts.pairedTransfer', { account: draft.suggestion.account?.name })}</p>
      )}

      <FormSection
        title={t(parts.length > 1 ? 'drafts.partsTitle' : 'drafts.partTitle')}
        aside={
          <span className="text-sm font-normal text-muted-foreground tabular-nums">
            {t('drafts.assigned', { assigned: formatMoney(assigned, symbol), total: formatMoney(draft.amount, symbol) })}
          </span>
        }
        contentClassName="space-y-6"
      >
        <div className="sm:max-w-[15rem]">
          <Field label={t('transactionForm.occurredAt')} htmlFor="occurredAt">
            <Input id="occurredAt" type="datetime-local" value={occurredAt} onChange={(e) => setOccurredAt(e.target.value)} />
          </Field>
        </div>
        {parts.map((part, index) => (
          <PartEditor
            key={part.key}
            part={part}
            index={parts.length > 1 ? index + 1 : null}
            accounts={byType}
            categories={(categories?.items ?? []).map((category) => ({ value: category.id, label: category.name }))}
            symbol={symbol}
            similar={index === 0 ? (draft.suggestion?.similar ?? []) : []}
            onChange={(patch) => update(part.key, patch)}
            onRemove={parts.length > 1 ? () => setParts((current) => current.filter((p) => p.key !== part.key)) : undefined}
          />
        ))}
        <button type="button" onClick={split} className="flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80">
          <Scissors className="h-4 w-4" />
          {t('drafts.split')}
        </button>
      </FormSection>

      <FormActions>
        <Button type="button" variant="outline" className="text-destructive hover:text-destructive" disabled={discard.isPending} onClick={() => void run(() => discard.mutateAsync(draft.id), t('drafts.discarded'))}>
          <Trash2 className="mr-2 h-4 w-4" />
          {t('drafts.discard')}
        </Button>
        <Button
          type="button"
          disabled={accept.isPending || parts.some((part) => !(Number(part.amount) > 0) || !part.description.trim() || !occurredAt)}
          onClick={() => void run(() => accept.mutateAsync({ id: draft.id, parts: parts.map(toRequest) }), t('drafts.accepted', { count: parts.length }))}
        >
          <Check className="mr-2 h-4 w-4" />
          {t(parts.length > 1 ? 'drafts.acceptMany' : 'drafts.accept', { count: parts.length })}
        </Button>
      </FormActions>
    </div>
  )
}

function DuplicateCard({ transaction, busy, onLink }: { transaction: Transaction; busy: boolean; onLink: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-warning/40 bg-warning/5 px-4 py-3">
      <div className="min-w-0 flex-1 text-sm">
        <div className="font-medium">{t('drafts.duplicateTitle')}</div>
        <div className="text-muted-foreground">
          {transaction.description} · {formatShortDate(transaction.occurredAt.slice(0, 10))} · {formatMoney(transaction.amount, currencySymbolFor(transaction.currency))}
        </div>
      </div>
      <Button variant="outline" disabled={busy} onClick={onLink}>
        <Link2 className="mr-2 h-4 w-4" />
        {t('drafts.isThisOne')}
      </Button>
    </div>
  )
}

interface PartEditorProps {
  part: Part
  /** Its number when there are several. */
  index: number | null
  accounts: Record<'asset' | 'expense' | 'revenue', { id: string; name: string; currencyCode: string | null; currentBalance: string; currencySymbol: string | null }[]>
  categories: { value: string; label: string }[]
  symbol: string
  /** Payees with a similar name, offered under the payee field while it is empty. */
  similar: AccountRef[]
  onChange: (patch: Partial<Part>) => void
  onRemove?: () => void
}

/** One transaction the draft becomes: its kind, amount, what, between which accounts, its category. */
function PartEditor({ part, index, accounts, categories, symbol, similar, onChange, onRemove }: PartEditorProps) {
  const { t } = useTranslation()
  const sides = SIDES[part.kind]
  const options = (type: AccountType, selected: string) => {
    const list = accounts[type as 'asset' | 'expense' | 'revenue'].map((account) => ({
      value: account.id,
      label: type === 'asset' ? formatAccountBalance(account as Parameters<typeof formatAccountBalance>[0]) : account.name,
    }))
    if (isPending(selected)) list.push({ value: selected, label: t('transactionForm.pendingNew', { name: pendingName(selected) }) })
    return list
  }

  const changeKind = (kind: Kind) => {
    const asset = SIDES[part.kind].source === 'asset' ? part.sourceId : part.destinationId
    onChange({ kind, sourceId: kind === 'deposit' ? '' : asset, destinationId: kind === 'deposit' ? asset : '' })
  }

  // What was written before with the payee, whatever account paid: a description (with its category) or a category to pick.
  const payeeId = part.kind === 'withdrawal' ? part.destinationId : part.kind === 'deposit' ? part.sourceId : ''
  const { data: payee } = usePayeeHistory(payeeId && !isPending(payeeId) ? payeeId : null)
  const typed = part.description.trim().toLowerCase()
  const recentDescriptions = (payee?.descriptions ?? []).filter((recent) => recent.description.toLowerCase() !== typed)
  const recentCategories = (payee?.categories ?? []).filter((id) => id !== part.categoryId).flatMap((id) => categories.filter((category) => category.value === id))

  // A past transaction picked under the description: its kind, accounts and category.
  const copyFrom = (past: Transaction) => {
    const kind: Kind = past.sourceAccount?.type === 'asset' && past.destinationAccount?.type === 'asset' ? 'transfer' : past.sourceAccount?.type === 'asset' ? 'withdrawal' : 'deposit'
    onChange({ kind, sourceId: past.sourceAccount?.id ?? '', destinationId: past.destinationAccount?.id ?? '', categoryId: past.categoryId ?? '' })
  }

  return (
    <div className={cn('grid gap-4 sm:grid-cols-2', index !== null && 'rounded-xl border p-3 sm:p-4')}>
      <div className="flex items-center justify-between gap-3 sm:col-span-2">
        <SegmentedControl
          label={t('transactionForm.type')}
          value={part.kind}
          onChange={changeKind}
          options={[
            { value: 'withdrawal', label: t('quickAdd.withdrawal') },
            { value: 'deposit', label: t('quickAdd.deposit') },
            { value: 'transfer', label: t('quickAdd.transfer') },
          ]}
        />
        {onRemove && (
          <Button variant="ghost" size="icon" aria-label={t('drafts.removePart')} title={t('drafts.removePart')} onClick={onRemove}>
            <X className="h-4 w-4" />
          </Button>
        )}
      </div>
      <Field label={t('transactionForm.amount')}>
        <div className="relative">
          <Input type="number" inputMode="decimal" step="0.01" min="0.01" className="pr-8 tabular-nums" value={part.amount} onChange={(e) => onChange({ amount: e.target.value })} />
          <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">{symbol}</span>
        </div>
      </Field>
      <Field label={t('transactionForm.descriptionField')}>
        <DescriptionField id={`description-${part.key}`} required value={part.description} onChange={(description) => onChange({ description })} onPick={copyFrom} />
        <Choices
          label={t('drafts.recentWithPayee')}
          options={recentDescriptions.map((recent) => ({
            key: recent.description,
            label: recent.description,
            onPick: () => onChange({ description: recent.description, ...(recent.categoryId ? { categoryId: recent.categoryId } : {}) }),
          }))}
        />
      </Field>
      <Field label={t(part.kind === 'deposit' ? 'transactionForm.sourceRevenue' : 'transactionForm.sourceAsset')}>
        <Combobox
          options={options(sides.source, part.sourceId)}
          value={part.sourceId}
          onChange={(sourceId) => onChange({ sourceId })}
          onCreate={sides.source === 'asset' ? undefined : (name) => onChange({ sourceId: `${NEW_PREFIX}${name}` })}
          placeholder={t(sides.source === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
          clearable={part.kind === 'deposit'}
        />
        {part.kind === 'deposit' && !part.sourceId && <SimilarPayees similar={similar} type="revenue" onPick={(sourceId) => onChange({ sourceId })} />}
      </Field>
      <Field label={t(part.kind === 'withdrawal' ? 'transactionForm.destinationExpense' : 'transactionForm.destinationAsset')}>
        <Combobox
          options={options(sides.destination, part.destinationId)}
          value={part.destinationId}
          onChange={(destinationId) => onChange({ destinationId })}
          onCreate={sides.destination === 'asset' ? undefined : (name) => onChange({ destinationId: `${NEW_PREFIX}${name}` })}
          placeholder={t(sides.destination === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
          clearable={part.kind === 'withdrawal'}
        />
        {part.kind === 'withdrawal' && !part.destinationId && <SimilarPayees similar={similar} type="expense" onPick={(destinationId) => onChange({ destinationId })} />}
      </Field>
      <Field label={t('transactionForm.category')} className="sm:col-span-2" optional>
        <Combobox
          options={[...categories, ...(isPending(part.categoryId) ? [{ value: part.categoryId, label: t('transactionForm.pendingNew', { name: pendingName(part.categoryId) }) }] : [])]}
          value={part.categoryId}
          onChange={(categoryId) => onChange({ categoryId })}
          onCreate={(name) => onChange({ categoryId: `${NEW_PREFIX}${name}` })}
          placeholder={t('transactionForm.selectCategory')}
        />
        <Choices
          label={t('drafts.recentWithPayee')}
          options={recentCategories.map((category) => ({ key: category.value, label: category.label, onPick: () => onChange({ categoryId: category.value }) }))}
        />
      </Field>
    </div>
  )
}


/** "Similar: Esselunga · Esselunga Milano": a tap picks one. */
function SimilarPayees({ similar, type, onPick }: { similar: AccountRef[]; type: AccountType; onPick: (id: string) => void }) {
  const { t } = useTranslation()

  return (
    <Choices
      label={t('drafts.similar')}
      options={similar.filter((account) => account.type === type).map((account) => ({ key: account.id, label: account.name, onPick: () => onPick(account.id) }))}
    />
  )
}

/** A few values under a field, a tap picks one; nothing when there are none. */
function Choices({ label, options }: { label: string; options: { key: string; label: string; onPick: () => void }[] }) {
  if (options.length === 0) return null

  return (
    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
      <span className="text-muted-foreground">{label}</span>
      {options.map((option) => (
        <button key={option.key} type="button" onClick={option.onPick} className="max-w-full truncate rounded-full border px-2.5 py-1 hover:bg-muted">
          {option.label}
        </button>
      ))}
    </div>
  )
}
