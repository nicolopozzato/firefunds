import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowUpCircle, ArrowDownCircle, Repeat } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import FormSkeleton from '@/components/FormSkeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import Combobox from '@/components/Combobox'
import TagsInput from '@/components/TagsInput'
import { useAllAccounts, useCreateAccount } from '@/hooks/use-accounts'
import { useCreateTransaction, useTransaction, useUpdateTransaction } from '@/hooks/use-transactions'
import { useAllClassification, useCreateClassification } from '@/hooks/use-classification'
import { useCurrencies } from '@/hooks/use-currencies'
import { useFormFill } from '@/hooks/use-form-fill'
import { handleApiError } from '@/lib/error-handler'
import { cn, formatAccountBalance } from '@/lib/utils'
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

function toDatetimeLocal(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * Converts a `datetime-local` input value (naive, always in the browser's own
 * timezone) into an ISO string with an explicit UTC offset, so the backend
 * resolves the same instant regardless of its own default timezone.
 */
function datetimeLocalToIso(value: string): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  const local = new Date(value)
  const offsetMinutes = -local.getTimezoneOffset()
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const abs = Math.abs(offsetMinutes)
  const offset = `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
  return `${value}:00${offset}`
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
  const { data: categoriesResponse, isLoading: isLoadingCategories } = useAllClassification('categories')
  const { data: tagsResponse, isLoading: isLoadingTags } = useAllClassification('tags')
  const categories = categoriesResponse?.items
  const tags = tagsResponse?.items
  const createCategory = useCreateClassification('categories')
  const createTag = useCreateClassification('tags')

  const requestedType = searchParams.get('type')
  const [transactionType, setTransactionType] = useState<TransactionType>(
    isTransactionType(requestedType) ? requestedType : 'withdrawal'
  )
  const config = TYPE_CONFIG[transactionType]

  const { data: sourceAccountsResponse, isLoading: isLoadingSourceAccounts } = useAllAccounts(config.sourceType)
  const { data: destinationAccountsResponse, isLoading: isLoadingDestinationAccounts } = useAllAccounts(config.destinationType)
  const sourceAccounts = sourceAccountsResponse?.items
  const destinationAccounts = destinationAccountsResponse?.items
  const createSourceAccount = useCreateAccount(config.sourceType)
  const createDestinationAccount = useCreateAccount(config.destinationType)

  const [description, setDescription] = useState('')
  // A new transaction can start from an account, e.g. the dashboard's "New" on an account's box.
  const [sourceAccountId, setSourceAccountId] = useState(() => searchParams.get('sourceAccountId') ?? '')
  const [destinationAccountId, setDestinationAccountId] = useState(() => searchParams.get('destinationAccountId') ?? '')
  const [occurredAt, setOccurredAt] = useState(() => toDatetimeLocal(new Date()))
  const [amount, setAmount] = useState('')
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
    setOccurredAt(isCloning ? toDatetimeLocal(new Date()) : toDatetimeLocal(new Date(existingTransaction.occurredAt)))
    const amountDecimals = currencies?.find((c) => c.code === existingTransaction.currency)?.decimalPlaces
    setAmount(trimAmountToCurrency(existingTransaction.amount, amountDecimals))
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
    setSourceAccountId('')
    setDestinationAccountId('')
  }

  const resetForeignAmount = (checked: boolean) => {
    setHasForeignAmount(checked)
    if (!checked) {
      setForeignAmount('')
      setForeignCurrency('')
    }
  }

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
      // When creating a new asset account on the fly, inherit the currency from
      // the other (already existing) asset account in this transaction — an
      // asset account cannot be created without a currency. Falls back to the
      // first enabled currency if both sides are being created at once.
      const inheritedCurrencyCode =
        (!isPendingId(sourceAccountId) && sourceAccounts?.find((a) => a.id === sourceAccountId)?.currencyCode) ||
        (!isPendingId(destinationAccountId) && destinationAccounts?.find((a) => a.id === destinationAccountId)?.currencyCode) ||
        currencies?.[0]?.code ||
        null

      let resolvedSourceId = sourceAccountId
      if (isPendingId(sourceAccountId)) {
        const created = await createSourceAccount.mutateAsync({
          type: config.sourceType,
          name: pendingName(sourceAccountId),
          currencyCode: config.sourceType === 'asset' ? inheritedCurrencyCode : undefined,
        })
        resolvedSourceId = created.id
      }

      let resolvedDestinationId = destinationAccountId
      if (isPendingId(destinationAccountId)) {
        const created = await createDestinationAccount.mutateAsync({
          type: config.destinationType,
          name: pendingName(destinationAccountId),
          currencyCode: config.destinationType === 'asset' ? inheritedCurrencyCode : undefined,
        })
        resolvedDestinationId = created.id
      }

      let resolvedCategoryId = categoryId
      if (isPendingId(categoryId)) {
        const created = await createCategory.mutateAsync({ name: pendingName(categoryId) })
        resolvedCategoryId = created.id
      }

      const resolvedTagIds: string[] = []
      for (const tagId of selectedTagIds) {
        if (isPendingId(tagId)) {
          const created = await createTag.mutateAsync({ name: pendingName(tagId) })
          resolvedTagIds.push(created.id)
        } else {
          resolvedTagIds.push(tagId)
        }
      }

      const payload = {
        description: description || null,
        sourceAccountId: resolvedSourceId || null,
        destinationAccountId: resolvedDestinationId || null,
        occurredAt: datetimeLocalToIso(occurredAt),
        amount,
        foreignAmount: hasForeignAmount ? foreignAmount || null : null,
        foreignCurrency: hasForeignAmount ? foreignCurrency || null : null,
        categoryId: resolvedCategoryId || null,
        tagIds: resolvedTagIds,
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

      {/* Phones: the form sits on the page, no card around it. */}
      <Card className="max-md:border-0 max-md:bg-transparent max-md:shadow-none">
        <CardContent className="pt-6 max-md:p-0">
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="description">{t('transactionForm.descriptionField')}</Label>
                <Input
                  id="description"
                  required
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                />
              </div>

              <div className="space-y-2">
                <Label>{sourceLabel}</Label>
                <Combobox
                  options={sourceOptions}
                  value={sourceAccountId}
                  onChange={setSourceAccountId}
                  onCreate={(name) => setSourceAccountId(toPendingId(name))}
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
                  onCreate={(name) => setDestinationAccountId(toPendingId(name))}
                  placeholder={t('transactionForm.selectAccount')}
                  clearable={OPTIONAL_SIDE[transactionType] === 'destination'}
                />
              </div>

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

              {/* Phones: the amount comes first, large. */}
              <div className="space-y-2 max-md:order-first">
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

              <div className="space-y-2 sm:col-span-2">
                <div className="flex items-center gap-2">
                  <input
                    id="hasForeignAmount"
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={hasForeignAmount}
                    onChange={(e) => resetForeignAmount(e.target.checked)}
                  />
                  <Label htmlFor="hasForeignAmount">{t('transactionForm.hasForeignAmount')}</Label>
                </div>
              </div>

              {hasForeignAmount && (
                <>
                  <div className="space-y-2">
                    <Label htmlFor="foreignCurrency">{t('transactionForm.foreignCurrency')}</Label>
                    <Select value={foreignCurrency} onValueChange={setForeignCurrency}>
                      <SelectTrigger id="foreignCurrency">
                        <SelectValue placeholder={t('accountsCommon.selectCurrency')} />
                      </SelectTrigger>
                      <SelectContent>
                        {currencies?.map((currency) => (
                          <SelectItem key={currency.code} value={currency.code}>
                            {currency.code} — {currency.name} ({currency.symbol})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="foreignAmount">{t('transactionForm.foreignAmount')}</Label>
                    <Input
                      id="foreignAmount"
                      type="number"
                      step="0.01"
                      value={foreignAmount}
                      onChange={(e) => setForeignAmount(e.target.value)}
                    />
                  </div>
                </>
              )}

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

              <div className="space-y-2 sm:col-span-2">
                <Label>{t('transactionForm.tags')}</Label>
                <TagsInput
                  options={tagOptions}
                  value={selectedTagIds}
                  onChange={setSelectedTagIds}
                  onCreate={(name) => setSelectedTagIds((current) => [...current, toPendingId(name)])}
                  placeholder={t('tagsInput.add')}
                />
              </div>

              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="notes">{t('classificationCommon.notes')}</Label>
                <textarea
                  id="notes"
                  rows={3}
                  className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </div>
            </div>

            <FormActions>
              <Button type="button" variant="outline" onClick={handleCancel}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={
                  isSaving ||
                  !description.trim() ||
                  (!sourceAccountId && OPTIONAL_SIDE[transactionType] !== 'source') ||
                  (!destinationAccountId && OPTIONAL_SIDE[transactionType] !== 'destination')
                }>
                {t('common.save')}
              </Button>
            </FormActions>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
