import { useMemo, useState, type FormEvent } from 'react'
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
import FormSkeleton from '@/components/FormSkeleton'
import Combobox from '@/components/Combobox'
import TagsInput from '@/components/TagsInput'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useCreateTransaction, useTransaction, useUpdateTransaction } from '@/hooks/use-transactions'
import { useAllClassification } from '@/hooks/use-classification'
import { useCurrencies, useCurrencyOptions } from '@/hooks/use-currencies'
import { useFormFill } from '@/hooks/use-form-fill'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import { cn, currencySymbolFor, deviceTimezone, formatAccountBalance, fromDatetimeLocal, nowAsDatetimeLocal, numberFormatter, toDatetimeLocal } from '@/lib/utils'
import type { AccountType } from '@/types/account'
import type { Transaction } from '@/types/transaction'
import FormActions from '@/components/FormActions'

type TransactionType = 'withdrawal' | 'deposit' | 'transfer'

const TYPE_CONFIG: Record<TransactionType, { sourceType: AccountType; destinationType: AccountType }> = {
  withdrawal: { sourceType: 'asset', destinationType: 'expense' },
  deposit: { sourceType: 'revenue', destinationType: 'asset' },
  transfer: { sourceType: 'asset', destinationType: 'asset' },
}

const TYPE_ICONS: Record<TransactionType, typeof ArrowUpCircle> = {
  withdrawal: ArrowUpCircle,
  deposit: ArrowDownCircle,
  transfer: Repeat,
}

const TYPE_STYLES: Record<TransactionType, { active: string; icon: string }> = {
  withdrawal: { active: 'border-negative bg-negative/10', icon: 'text-negative' },
  deposit: { active: 'border-positive bg-positive/10', icon: 'text-positive' },
  transfer: { active: 'border-info bg-info/10', icon: 'text-info' },
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

  const typeLabels: Record<TransactionType, string> = {
    withdrawal: t('navigation.withdrawalTransactions'),
    deposit: t('navigation.depositTransactions'),
    transfer: t('navigation.transferTransactions'),
  }

  /** Phones: the type buttons are a third of the screen wide. */
  const typeShortLabels: Record<TransactionType, string> = {
    withdrawal: t('quickAdd.withdrawal'),
    deposit: t('quickAdd.deposit'),
    transfer: t('quickAdd.transfer'),
  }

  const typeDescriptions: Record<TransactionType, string> = {
    withdrawal: t('transactionForm.typeWithdrawalDescription'),
    deposit: t('transactionForm.typeDepositDescription'),
    transfer: t('transactionForm.typeTransferDescription'),
  }

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
        navigate('/transactions')
      }
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  const isReferenceDataLoading =
    isLoadingSourceAccounts || isLoadingDestinationAccounts || isLoadingCategories || isLoadingTags

  if ((isEditing || isCloning) && (!isFormPopulated || isReferenceDataLoading)) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t(isEditing ? 'transactionForm.editTitle' : 'transactionForm.title')}
          description={t('transactionForm.description')}
        />
        <div className="grid gap-3 sm:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[76px] rounded-xl" />
          ))}
        </div>
        <FormSkeleton fieldCount={4} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t(isEditing ? 'transactionForm.editTitle' : 'transactionForm.title')}
        description={t('transactionForm.description')}
      />

      {/* Phones: three compact buttons in a row, short labels, no explanation. */}
      <div className="grid grid-cols-3 gap-2 md:gap-3">
        {(Object.keys(TYPE_CONFIG) as TransactionType[]).map((type) => {
          const Icon = TYPE_ICONS[type]
          const isActive = transactionType === type
          return (
            <button
              key={type}
              type="button"
              onClick={() => handleTypeChange(type)}
              className={cn(
                'flex flex-col items-center gap-1 rounded-xl border-2 bg-card p-2 text-center transition-colors hover:bg-accent/50 md:flex-row md:gap-3 md:p-4 md:text-left',
                isActive ? TYPE_STYLES[type].active : 'border-border'
              )}
            >
              <Icon className={cn('h-6 w-6 shrink-0 md:h-8 md:w-8', TYPE_STYLES[type].icon)} />
              <div>
                <div className="text-sm font-semibold md:text-base">
                  <span className="md:hidden">{typeShortLabels[type]}</span>
                  <span className="hidden md:inline">{typeLabels[type]}</span>
                </div>
                <div className="hidden text-xs text-muted-foreground md:block">{typeDescriptions[type]}</div>
              </div>
            </button>
          )
        })}
      </div>

      {/* Desktop: what, when, how much and its categories on the left, the optional extras on the right.
          Below that, one column in that order. */}
      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="space-y-6">
            <FormSection title={t('transactionForm.sectionTransaction')} contentClassName="grid gap-4 sm:grid-cols-2">
              {/* Phones: the amount comes first, large. */}
              <div className="space-y-2">
                <Label htmlFor="amount">{t('transactionForm.amount')}</Label>
                <Input
                  data-large
                  className="max-md:h-14 max-md:text-2xl max-md:font-semibold max-md:tabular-nums"
                  id="amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              </div>

              {isCurrencyTransfer && (
                <div className="space-y-2">
                  <Label htmlFor="destinationAmount">
                    {t('transactionForm.destinationAmount')}
                    <span className="ml-1 font-normal text-muted-foreground">({currencySymbolFor(destinationCurrency ?? '')})</span>
                  </Label>
                  <Input
                    id="destinationAmount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    required
                    value={destinationAmount}
                    onChange={(e) => setDestinationAmount(e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    {transferRate !== null
                      ? t('transactionForm.transferRate', {
                          from: sourceCurrency,
                          rate: numberFormatter({ maximumFractionDigits: 6 }).format(transferRate),
                          to: destinationCurrency,
                        })
                      : t('transactionForm.destinationAmountHint', { from: sourceCurrency, to: destinationCurrency })}
                  </p>
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="occurredAt">{t('transactionForm.occurredAt')}</Label>
                <Input
                  id="occurredAt"
                  type="datetime-local"
                  required
                  value={occurredAt}
                  onChange={(e) => setOccurredAt(e.target.value)}
                />
              </div>

              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="description">{t('transactionForm.descriptionField')}</Label>
                <Input id="description" required value={description} onChange={(e) => setDescription(e.target.value)} />
              </div>

              <div className="space-y-2">
                <Label>{sourceLabel}</Label>
                <Combobox
                  options={sourceOptions}
                  value={sourceAccountId}
                  onChange={setSourceAccountId}
                  // An asset account needs a kind, a currency and an opening balance: made on the Accounts page.
                  onCreate={config.sourceType === 'asset' ? undefined : (name) => setSourceAccountId(toPendingId(name))}
                  placeholder={t('transactionForm.selectAccount')}
                  clearable={OPTIONAL_SIDE[transactionType] === 'source'}
                />
              </div>

              <div className="space-y-2">
                <Label>{destinationLabel}</Label>
                <Combobox
                  options={destinationOptions}
                  value={destinationAccountId}
                  onChange={setDestinationAccountId}
                  onCreate={config.destinationType === 'asset' ? undefined : (name) => setDestinationAccountId(toPendingId(name))}
                  placeholder={t('transactionForm.selectAccount')}
                  clearable={OPTIONAL_SIDE[transactionType] === 'destination'}
                />
              </div>
            </FormSection>

            <FormSection title={t('transactionForm.sectionClassification')} optional contentClassName="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>{t('transactionForm.category')}</Label>
                <Combobox
                  options={categoryOptions}
                  value={categoryId}
                  onChange={setCategoryId}
                  onCreate={(name) => setCategoryId(toPendingId(name))}
                  placeholder={t('transactionForm.selectCategory')}
                />
              </div>

              <div className="space-y-2">
                <Label>{t('transactionForm.tags')}</Label>
                <TagsInput
                  options={tagOptions}
                  value={selectedTagIds}
                  onChange={setSelectedTagIds}
                  onCreate={(name) => setSelectedTagIds((current) => [...current, toPendingId(name)])}
                  placeholder={t('tagsInput.add')}
                />
              </div>
            </FormSection>
          </div>

          <div className="space-y-6">
            {transactionType !== 'transfer' && (
              <FormSection title={t('transactionForm.foreignCurrency')} optional contentClassName="space-y-4">
                <div className="flex items-start gap-2">
                  <input
                    id="hasForeignAmount"
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 rounded border-input"
                    checked={hasForeignAmount}
                    onChange={(e) => resetForeignAmount(e.target.checked)}
                  />
                  <Label htmlFor="hasForeignAmount" className="font-normal">
                    {t(transactionType === 'deposit' ? 'transactionForm.hasForeignAmountDeposit' : 'transactionForm.hasForeignAmount')}
                  </Label>
                </div>
  
                {hasForeignAmount && (
                  <div className="grid grid-cols-2 gap-4 lg:grid-cols-1">
                    <div className="min-w-0 space-y-2">
                      <Label htmlFor="foreignCurrency">{t('transactionForm.foreignCurrency')}</Label>
                      <Combobox
                        options={currencyOptions}
                        value={foreignCurrency}
                        onChange={setForeignCurrency}
                        placeholder={t('accountsCommon.selectCurrency')}
                      />
                    </div>
  
                    <div className="min-w-0 space-y-2">
                      <Label htmlFor="foreignAmount">{t('transactionForm.foreignAmount')}</Label>
                      <Input
                        id="foreignAmount"
                        type="number"
                        step="0.01"
                        value={foreignAmount}
                        onChange={(e) => setForeignAmount(e.target.value)}
                      />
                    </div>
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
              <textarea
                id="notes"
                rows={3}
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </FormSection>
          </div>
        </div>

        <FormActions>
          <Button type="button" variant="outline" onClick={handleCancel}>
            {t('common.cancel')}
          </Button>
          <Button
            type="submit"
            disabled={
              isSaving ||
              !description.trim() ||
              (!sourceAccountId && OPTIONAL_SIDE[transactionType] !== 'source') ||
              (!destinationAccountId && OPTIONAL_SIDE[transactionType] !== 'destination')
            }
          >
            {t('common.save')}
          </Button>
        </FormActions>
      </form>
    </div>
  )
}
