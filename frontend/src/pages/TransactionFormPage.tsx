import { useMemo, useRef, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowUpCircle, ArrowDownCircle, Repeat } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import Field from '@/components/Field'
import ChoiceCards, { type ChoiceCardOption } from '@/components/ChoiceCards'
import FormSkeleton from '@/components/FormSkeleton'
import Combobox from '@/components/Combobox'
import DescriptionField from '@/components/DescriptionField'
import QuickDates from '@/components/QuickDates'
import TagsInput from '@/components/TagsInput'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useCreateTransaction, useTransaction, useUpdateTransaction } from '@/hooks/use-transactions'
import { useAllClassification } from '@/hooks/use-classification'
import { useCurrencies, useCurrencyOptions } from '@/hooks/use-currencies'
import { useFormFill } from '@/hooks/use-form-fill'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import { currencySymbolFor, deviceTimezone, formatAccountBalance, fromDatetimeLocal, nowAsDatetimeLocal, numberFormatter, toDatetimeLocal } from '@/lib/utils'
import type { AccountType } from '@/types/account'
import type { Transaction } from '@/types/transaction'
import FormActions from '@/components/FormActions'

type TransactionType = 'withdrawal' | 'deposit' | 'transfer'

const TYPE_CONFIG: Record<TransactionType, { sourceType: AccountType; destinationType: AccountType }> = {
  withdrawal: { sourceType: 'asset', destinationType: 'expense' },
  deposit: { sourceType: 'revenue', destinationType: 'asset' },
  transfer: { sourceType: 'asset', destinationType: 'asset' },
}

/** Where the default account goes: what pays a withdrawal or a transfer, what receives a deposit. */
function defaultAccountSides(type: TransactionType, accountId: string | null): { source: string; destination: string } {
  if (!accountId) return { source: '', destination: '' }
  return type === 'deposit' ? { source: '', destination: accountId } : { source: accountId, destination: '' }
}

/**
 * The side that may be left empty, saved as no account: a withdrawal's
 * destination (money handed out, e.g. a gift) or a deposit's source.
 */
const OPTIONAL_SIDE: Partial<Record<TransactionType, 'source' | 'destination'>> = {
  withdrawal: 'destination',
  deposit: 'source',
}

const NEW_PREFIX = 'NEW:'
const toPendingId = (name: string) => `${NEW_PREFIX}${name}`
const isPendingId = (id: string) => id.startsWith(NEW_PREFIX)
const pendingName = (id: string) => id.slice(NEW_PREFIX.length)

function isTransactionType(value: string | null): value is TransactionType {
  return value === 'withdrawal' || value === 'deposit' || value === 'transfer'
}



/** Trims a raw decimal(19,4) amount from the backend down to the currency's own decimal places, e.g. "9.0000" -> "9". */
function trimAmountToCurrency(amount: string, decimalPlaces: number | undefined): string {
  if (decimalPlaces === undefined) return amount
  const value = Number(amount)
  if (Number.isNaN(value)) return amount
  return value.toFixed(decimalPlaces)
}

function typeFromAccountTypes(
  sourceType: NonNullable<Transaction['sourceAccount']>['type'] | null,
  destinationType: NonNullable<Transaction['destinationAccount']>['type'] | null
): TransactionType {
  if (sourceType === null) return 'deposit'
  if (destinationType === null) return 'withdrawal'
  const match = (Object.entries(TYPE_CONFIG) as [TransactionType, typeof TYPE_CONFIG[TransactionType]][]).find(
    ([, config]) => config.sourceType === sourceType && config.destinationType === destinationType
  )
  return match?.[0] ?? 'withdrawal'
}

export default function TransactionFormPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id?: string }>()
  const isEditing = !!id
  const [searchParams] = useSearchParams()
  const cloneFromId = searchParams.get('cloneFrom')
  const isCloning = !!cloneFromId
  const createTransaction = useCreateTransaction()
  const updateTransaction = useUpdateTransaction()
  const { data: existingTransaction } = useTransaction(id ?? cloneFromId ?? '')
  const { data: currencies } = useCurrencies()
  const currencyOptions = useCurrencyOptions()
  const { data: categoriesResponse, isLoading: isLoadingCategories } = useAllClassification('categories')
  const { data: tagsResponse, isLoading: isLoadingTags } = useAllClassification('tags')
  const categories = categoriesResponse?.items
  const tags = tagsResponse?.items

  const requestedType = searchParams.get('type')
  const [transactionType, setTransactionType] = useState<TransactionType>(
    isTransactionType(requestedType) ? requestedType : 'withdrawal'
  )
  const config = TYPE_CONFIG[transactionType]

  const { data: sourceAccountsResponse, isLoading: isLoadingSourceAccounts } = useAllAccounts(config.sourceType)
  const { data: destinationAccountsResponse, isLoading: isLoadingDestinationAccounts } = useAllAccounts(config.destinationType)
  const sourceAccounts = sourceAccountsResponse?.items
  const destinationAccounts = destinationAccountsResponse?.items

  const [description, setDescription] = useState('')
  // A new transaction can start from an account, e.g. the dashboard's "New" on an account's box;
  // else from the user's default account (Preferences), on the asset side.
  const { user } = useAuth()
  const defaultSides = (type: TransactionType) =>
    isEditing || isCloning ? { source: '', destination: '' } : defaultAccountSides(type, user?.defaultAccountId ?? null)
  const fromLink = searchParams.has('sourceAccountId') || searchParams.has('destinationAccountId')
  const [sourceAccountId, setSourceAccountId] = useState(() =>
    fromLink ? searchParams.get('sourceAccountId') ?? '' : defaultSides(transactionType).source
  )
  const [destinationAccountId, setDestinationAccountId] = useState(() =>
    fromLink ? searchParams.get('destinationAccountId') ?? '' : defaultSides(transactionType).destination
  )
  const [occurredAt, setOccurredAt] = useState(nowAsDatetimeLocal)
  const [amount, setAmount] = useState('')
  // A transfer between two currencies also says what arrived.
  const [destinationAmount, setDestinationAmount] = useState('')
  const [hasForeignAmount, setHasForeignAmount] = useState(false)
  const [foreignAmount, setForeignAmount] = useState('')
  const [foreignCurrency, setForeignCurrency] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [selectedTagIds, setSelectedTagIds] = useState<string[]>([])
  const [notes, setNotes] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isFormPopulated, setIsFormPopulated] = useState(false)
  /** Set by "Save and add another": after saving, the form starts again instead of leaving. */
  const addAnotherRef = useRef(false)
  const amountRef = useRef<HTMLInputElement>(null)

  // Once per transaction (and once more when the currencies arrive, to trim the
  // amounts to their decimals), not on every refetch: see useFormFill.
  const fillKey = existingTransaction ? `${existingTransaction.id}:${isCloning}:${currencies ? 'currencies' : ''}` : null
  useFormFill(fillKey, () => {
    if (!existingTransaction) return

    setTransactionType(
      typeFromAccountTypes(existingTransaction.sourceAccount?.type ?? null, existingTransaction.destinationAccount?.type ?? null)
    )
    setDescription(existingTransaction.description ?? '')
    setSourceAccountId(existingTransaction.sourceAccount?.id ?? '')
    setDestinationAccountId(existingTransaction.destinationAccount?.id ?? '')
    setOccurredAt(isCloning ? nowAsDatetimeLocal() : toDatetimeLocal(existingTransaction.occurredAt))
    const amountDecimals = currencies?.find((c) => c.code === existingTransaction.currency)?.decimalPlaces
    setAmount(trimAmountToCurrency(existingTransaction.amount, amountDecimals))
    setDestinationAmount(existingTransaction.destinationAmount ? trimAmountToCurrency(existingTransaction.destinationAmount, currencies?.find((c) => c.code === existingTransaction.destinationCurrency)?.decimalPlaces) : '')
    setHasForeignAmount(existingTransaction.foreignAmount !== null)
    const foreignDecimals = currencies?.find((c) => c.code === existingTransaction.foreignCurrency)?.decimalPlaces
    setForeignAmount(
      existingTransaction.foreignAmount ? trimAmountToCurrency(existingTransaction.foreignAmount, foreignDecimals) : ''
    )
    setForeignCurrency(existingTransaction.foreignCurrency ?? '')
    setCategoryId(existingTransaction.categoryId ?? '')
    setSelectedTagIds(existingTransaction.tags.map((tag) => tag.id))
    setNotes(existingTransaction.notes ?? '')
    setIsFormPopulated(true)
  })

  const isSaving = createTransaction.isPending || updateTransaction.isPending || isSubmitting

  const typeOptions: ChoiceCardOption<TransactionType>[] = [
    { value: 'withdrawal', label: t('quickAdd.withdrawal'), icon: ArrowUpCircle, tone: 'negative' },
    { value: 'deposit', label: t('quickAdd.deposit'), icon: ArrowDownCircle, tone: 'positive' },
    { value: 'transfer', label: t('quickAdd.transfer'), icon: Repeat, tone: 'info' },
  ]

  const handleCancel = () => {
    if (isEditing) {
      navigate(-1)
    } else {
      navigate('/transactions')
    }
  }

  const handleTypeChange = (value: TransactionType) => {
    setTransactionType(value)
    const sides = defaultSides(value)
    setSourceAccountId(sides.source)
    setDestinationAccountId(sides.destination)
  }

  const resetForeignAmount = (checked: boolean) => {
    setHasForeignAmount(checked)
    if (!checked) {
      setForeignAmount('')
      setForeignCurrency('')
    }
  }

  // A transfer between two asset accounts in different currencies: the amount that arrived is asked for,
  // and the rate between them follows from the two.
  const sourceCurrency = sourceAccounts?.find((account) => account.id === sourceAccountId)?.currencyCode ?? null
  const destinationCurrency = destinationAccounts?.find((account) => account.id === destinationAccountId)?.currencyCode ?? null
  const isCurrencyTransfer =
    transactionType === 'transfer' && sourceCurrency !== null && destinationCurrency !== null && sourceCurrency !== destinationCurrency
  const transferRate = isCurrencyTransfer && Number(amount) > 0 && Number(destinationAmount) > 0 ? Number(destinationAmount) / Number(amount) : null

  // What the bank charged per unit of the original currency, when both amounts are there.
  const accountCurrency = (config.sourceType === 'asset'
    ? sourceAccounts?.find((account) => account.id === sourceAccountId)
    : destinationAccounts?.find((account) => account.id === destinationAccountId)
  )?.currencyCode
  const appliedRate =
    hasForeignAmount && foreignCurrency && accountCurrency && Number(amount) > 0 && Number(foreignAmount) > 0
      ? Number(amount) / Number(foreignAmount)
      : null

  const sourceLabel = useMemo(() => {
    if (transactionType === 'deposit') return t('transactionForm.sourceRevenue')
    return t('transactionForm.sourceAsset')
  }, [transactionType, t])

  const destinationLabel = useMemo(() => {
    if (transactionType === 'withdrawal') return t('transactionForm.destinationExpense')
    return t('transactionForm.destinationAsset')
  }, [transactionType, t])

  const sourceOptions = useMemo(() => {
    const existing = (sourceAccounts ?? []).map((account) => ({
      value: account.id,
      label: config.sourceType === 'asset' ? formatAccountBalance(account) : account.name,
    }))
    if (isPendingId(sourceAccountId)) {
      existing.push({ value: sourceAccountId, label: t('transactionForm.pendingNew', { name: pendingName(sourceAccountId) }) })
    }
    return existing
  }, [sourceAccounts, config.sourceType, sourceAccountId, t])

  const destinationOptions = useMemo(() => {
    const existing = (destinationAccounts ?? []).map((account) => ({
      value: account.id,
      label: config.destinationType === 'asset' ? formatAccountBalance(account) : account.name,
    }))
    if (isPendingId(destinationAccountId)) {
      existing.push({ value: destinationAccountId, label: t('transactionForm.pendingNew', { name: pendingName(destinationAccountId) }) })
    }
    return existing
  }, [destinationAccounts, config.destinationType, destinationAccountId, t])

  const categoryOptions = useMemo(() => {
    const existing = (categories ?? []).map((category) => ({ value: category.id, label: category.name }))
    if (isPendingId(categoryId)) {
      existing.push({ value: categoryId, label: t('transactionForm.pendingNew', { name: pendingName(categoryId) }) })
    }
    return existing
  }, [categories, categoryId, t])

  const tagOptions = useMemo(() => {
    const existing = tags ?? []
    const pendingSelected = selectedTagIds
      .filter(isPendingId)
      .map((id) => ({ id, name: t('transactionForm.pendingNew', { name: pendingName(id) }) }))
    return [...existing, ...pendingSelected]
  }, [tags, selectedTagIds, t])

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setIsSubmitting(true)

    try {
      // What was written instead of picked goes by name: the server finds it
      // (same name, any case) or creates it, in the same save as the
      // transaction, so a refused save creates nothing and a retry no duplicate.
      const byIdOrName = (value: string) =>
        isPendingId(value) ? { id: null, name: pendingName(value) } : { id: value || null, name: null }
      const source = byIdOrName(sourceAccountId)
      const destination = byIdOrName(destinationAccountId)
      const category = byIdOrName(categoryId)

      const payload = {
        description: description || null,
        sourceAccountId: source.id,
        newSourceAccountName: source.name,
        destinationAccountId: destination.id,
        newDestinationAccountName: destination.name,
        occurredAt: fromDatetimeLocal(occurredAt),
        // An edit keeps where it happened; a new one happens where this device is.
        timezone: isEditing ? undefined : deviceTimezone(),
        amount,
        destinationAmount: isCurrencyTransfer ? destinationAmount || null : null,
        foreignAmount: hasForeignAmount && transactionType !== 'transfer' ? foreignAmount || null : null,
        foreignCurrency: hasForeignAmount && transactionType !== 'transfer' ? foreignCurrency || null : null,
        categoryId: category.id,
        newCategoryName: category.name,
        tagIds: selectedTagIds.filter((tagId) => !isPendingId(tagId)),
        newTagNames: selectedTagIds.filter(isPendingId).map(pendingName),
        notes: notes || null,
      }

      if (isEditing && id) {
        await updateTransaction.mutateAsync({ id, data: payload })
        navigate(-1)
      } else {
        await createTransaction.mutateAsync(payload)
        if (addAnotherRef.current) {
          startAnother()
        } else {
          navigate('/transactions')
        }
      }
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  /**
   * A past transaction picked under the description: its kind, accounts,
   * category and tags; the amount and the day stay the ones being entered.
   */
  const copyFrom = (past: Transaction) => {
    const source = past.sourceAccount
    const destination = past.destinationAccount
    const kind: TransactionType =
      source?.type === 'asset' && destination?.type === 'asset' ? 'transfer' : source?.type === 'asset' ? 'withdrawal' : 'deposit'
    setTransactionType(kind)
    setSourceAccountId(source?.id ?? '')
    setDestinationAccountId(destination?.id ?? '')
    setCategoryId(past.categoryId ?? '')
    setSelectedTagIds(past.tags.map((tag) => tag.id))
    amountRef.current?.focus()
  }

  /**
   * After "Save and add another": an empty form for the next one, keeping
   * what a run of entries shares (the kind, the day, the user's own account),
   * the cursor back on the amount.
   */
  const startAnother = () => {
    toast.success(t('transactionForm.savedAddAnother'))
    setAmount('')
    setDestinationAmount('')
    setDescription('')
    setNotes('')
    setCategoryId('')
    setSelectedTagIds([])
    resetForeignAmount(false)
    if (config.sourceType !== 'asset') setSourceAccountId('')
    if (config.destinationType !== 'asset' || transactionType === 'transfer') setDestinationAccountId('')
    amountRef.current?.focus()
  }

  const isReferenceDataLoading =
    isLoadingSourceAccounts || isLoadingDestinationAccounts || isLoadingCategories || isLoadingTags

  const isSaveDisabled =
    isSaving ||
    !description.trim() ||
    (!sourceAccountId && OPTIONAL_SIDE[transactionType] !== 'source') ||
    (!destinationAccountId && OPTIONAL_SIDE[transactionType] !== 'destination')

  const amountSymbol = currencySymbolFor(accountCurrency ?? user?.currency ?? '')

  if ((isEditing || isCloning) && (!isFormPopulated || isReferenceDataLoading)) {
    return (
      <div className="max-w-5xl space-y-6">
        <PageHeader
          title={t(isEditing ? 'transactionForm.editTitle' : 'transactionForm.title')}
          description={t('transactionForm.description')}
        />
        <div className="space-y-6 lg:w-2/3 lg:pr-4">
          <div className="grid grid-cols-3 gap-2 sm:gap-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-[62px] rounded-xl" />
            ))}
          </div>
          <FormSkeleton fieldCount={4} />
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-5xl space-y-6">
      <PageHeader
        title={t(isEditing ? 'transactionForm.editTitle' : 'transactionForm.title')}
        description={t('transactionForm.description')}
      />

      {/* Desktop: the kind, what, how much, between which accounts and its categories on the left, the
          optional extras on the right, every field on the same two-column grid. Below that, one column. */}
      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="space-y-6">
            <ChoiceCards label={t('transactionForm.type')} options={typeOptions} value={transactionType} onChange={handleTypeChange} />

            <FormSection title={t('transactionForm.sectionTransaction')} contentClassName="grid gap-4 sm:grid-cols-2">
              {/* The amount first, large, in the account's currency. */}
              <Field label={t('transactionForm.amount')} htmlFor="amount">
                <div className="relative">
                  <Input
                    ref={amountRef}
                    data-large
                    className="h-12 pr-10 text-2xl font-semibold tabular-nums"
                    id="amount"
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min="0.01"
                    required
                    placeholder="0,00"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                  />
                  <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-base text-muted-foreground">
                    {amountSymbol}
                  </span>
                </div>
              </Field>

              <Field
                label={t('transactionForm.occurredAt')}
                htmlFor="occurredAt"
                labelAside={<QuickDates value={occurredAt} onChange={setOccurredAt} />}
              >
                <Input id="occurredAt" type="datetime-local" className="sm:h-12" required value={occurredAt} onChange={(e) => setOccurredAt(e.target.value)} />
              </Field>

              {isCurrencyTransfer && (
                <Field
                  label={
                    <>
                      {t('transactionForm.destinationAmount')}
                      <span className="ml-1 font-normal text-muted-foreground">({currencySymbolFor(destinationCurrency ?? '')})</span>
                    </>
                  }
                  htmlFor="destinationAmount"
                  hint={
                    transferRate !== null
                      ? t('transactionForm.transferRate', {
                          from: sourceCurrency,
                          rate: numberFormatter({ maximumFractionDigits: 6 }).format(transferRate),
                          to: destinationCurrency,
                        })
                      : t('transactionForm.destinationAmountHint', { from: sourceCurrency, to: destinationCurrency })
                  }
                >
                  <Input
                    id="destinationAmount"
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min="0.01"
                    required
                    className="tabular-nums"
                    value={destinationAmount}
                    onChange={(e) => setDestinationAmount(e.target.value)}
                  />
                </Field>
              )}

              <Field label={t('transactionForm.descriptionField')} htmlFor="description" className="sm:col-span-2">
                <DescriptionField id="description" required value={description} onChange={setDescription} onPick={isEditing ? undefined : copyFrom} />
              </Field>

              <Field label={sourceLabel}>
                <Combobox
                  options={sourceOptions}
                  value={sourceAccountId}
                  onChange={setSourceAccountId}
                  // An asset account needs a kind, a currency and an opening balance: made on the Accounts page.
                  onCreate={config.sourceType === 'asset' ? undefined : (name) => setSourceAccountId(toPendingId(name))}
                  placeholder={t(config.sourceType === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
                  clearable={OPTIONAL_SIDE[transactionType] === 'source'}
                />
              </Field>

              <Field label={destinationLabel}>
                <Combobox
                  options={destinationOptions}
                  value={destinationAccountId}
                  onChange={setDestinationAccountId}
                  onCreate={config.destinationType === 'asset' ? undefined : (name) => setDestinationAccountId(toPendingId(name))}
                  placeholder={t(config.destinationType === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
                  clearable={OPTIONAL_SIDE[transactionType] === 'destination'}
                />
              </Field>

            </FormSection>

            <FormSection title={t('transactionForm.sectionClassification')} optional contentClassName="grid gap-4 sm:grid-cols-2">
              <Field label={t('transactionForm.category')}>
                <Combobox
                  options={categoryOptions}
                  value={categoryId}
                  onChange={setCategoryId}
                  onCreate={(name) => setCategoryId(toPendingId(name))}
                  placeholder={t('transactionForm.selectCategory')}
                />
              </Field>

              <Field label={t('transactionForm.tags')}>
                <TagsInput
                  options={tagOptions}
                  value={selectedTagIds}
                  onChange={setSelectedTagIds}
                  onCreate={(name) => setSelectedTagIds((current) => [...current, toPendingId(name)])}
                  placeholder={t('tagsInput.add')}
                />
              </Field>
            </FormSection>
          </div>

          <div className="space-y-6">
            {transactionType !== 'transfer' && (
              <FormSection title={t('transactionForm.foreignCurrency')} optional contentClassName="space-y-4">
                <div className="flex items-start gap-2.5">
                  <Checkbox
                    id="hasForeignAmount"
                    className="mt-0.5"
                    checked={hasForeignAmount}
                    onChange={(e) => resetForeignAmount(e.target.checked)}
                  />
                  <Label htmlFor="hasForeignAmount" className="font-normal leading-snug">
                    {t(transactionType === 'deposit' ? 'transactionForm.hasForeignAmountDeposit' : 'transactionForm.hasForeignAmount')}
                  </Label>
                </div>

                {hasForeignAmount && (
                  <div className="grid grid-cols-2 gap-4 lg:grid-cols-1">
                    <Field label={t('transactionForm.foreignCurrency')} htmlFor="foreignCurrency">
                      <Combobox
                        options={currencyOptions}
                        value={foreignCurrency}
                        onChange={setForeignCurrency}
                        placeholder={t('accountsCommon.selectCurrency')}
                      />
                    </Field>

                    <Field label={t('transactionForm.foreignAmount')} htmlFor="foreignAmount">
                      <Input
                        id="foreignAmount"
                        type="number"
                        inputMode="decimal"
                        step="0.01"
                        className="tabular-nums"
                        value={foreignAmount}
                        onChange={(e) => setForeignAmount(e.target.value)}
                      />
                    </Field>
                  </div>
                )}

                {hasForeignAmount && (
                  <p className="text-xs text-muted-foreground">
                    {appliedRate !== null
                      ? t('transactionForm.appliedRate', {
                          foreign: foreignCurrency,
                          rate: numberFormatter({ maximumFractionDigits: 4 }).format(appliedRate),
                          currency: accountCurrency,
                        })
                      : t('transactionForm.foreignHint')}
                  </p>
                )}
              </FormSection>
            )}

            <FormSection title={<label htmlFor="notes">{t('classificationCommon.notes')}</label>} optional>
              <Textarea id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </FormSection>
          </div>
        </div>

        <FormActions>
          <Button type="button" variant="outline" onClick={handleCancel}>
            {t('common.cancel')}
          </Button>
          {!isEditing && (
            <Button type="submit" variant="outline" disabled={isSaveDisabled} onClick={() => (addAnotherRef.current = true)}>
              {t('transactionForm.saveAndAddAnother')}
            </Button>
          )}
          <Button type="submit" disabled={isSaveDisabled} onClick={() => (addAnotherRef.current = false)}>
            {t('common.save')}
          </Button>
        </FormActions>
      </form>
    </div>
  )
}
