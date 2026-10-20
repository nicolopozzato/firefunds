import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowDownToLine, ArrowUpToLine } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import FormSkeleton from '@/components/FormSkeleton'
import Combobox from '@/components/Combobox'
import { useAccountOpenPositions, useAllAccounts } from '@/hooks/use-accounts'
import { useAllInvestmentInstruments } from '@/hooks/use-investments'
import { useFormFill } from '@/hooks/use-form-fill'
import {
  useCreateInvestmentTransaction,
  useInvestmentTransaction,
  useUpdateInvestmentTransaction,
} from '@/hooks/use-investment-transactions'
import { handleApiError } from '@/lib/error-handler'
import { cn, deviceTimezone, formatAccountBalance, formatMoney, formatQuantity, fromDatetimeLocal, nowAsDatetimeLocal, numberFormatter, toDatetimeLocal } from '@/lib/utils'
import type { InvestmentTransactionType } from '@/types/investment'
import FormActions from '@/components/FormActions'

const TYPE_STYLES: Record<InvestmentTransactionType, { active: string; icon: string }> = {
  buy: { active: 'border-negative bg-negative/10', icon: 'text-negative' },
  sell: { active: 'border-positive bg-positive/10', icon: 'text-positive' },
}

const TYPE_ICONS: Record<InvestmentTransactionType, typeof ArrowDownToLine> = {
  buy: ArrowDownToLine,
  sell: ArrowUpToLine,
}

function isInvestmentTransactionType(value: string | null): value is InvestmentTransactionType {
  return value === 'buy' || value === 'sell'
}

/** Quantity × unit price rounded to cents, what a broker usually charges; null while either is missing. */
function suggestAmount(quantity: string, unitPrice: string): string | null {
  const q = Number(quantity)
  const p = Number(unitPrice)
  if (!quantity || !unitPrice || Number.isNaN(q) || Number.isNaN(p)) return null
  return (Math.round(q * p * 100) / 100).toFixed(2)
}

/** Same rule as the backend (InvestmentTransaction::isPlausibleAmount): within 0.5% of quantity × price, or a cent. */
function isPlausibleAmount(quantity: string, unitPrice: string, amount: string): boolean {
  const expected = Number(quantity) * Number(unitPrice)
  const difference = Math.abs(Number(amount) - expected)
  return difference <= Math.max(0.01, expected * 0.005) + 1e-9
}

/** BUY total = amount + fees + taxes; SELL total = amount - fees - taxes. */
function computeTotal(type: InvestmentTransactionType, amount: string, fees: string, taxes: string): number | null {
  const a = Number(amount)
  const costs = Number(fees || '0') + Number(taxes || '0')
  if (!amount || Number.isNaN(a) || Number.isNaN(costs)) return null
  return type === 'buy' ? a + costs : a - costs
}

/** The amounts adding up to the total, read only and set apart from the fields. */
function TradeSummary({
  type,
  amount,
  fees,
  taxes,
  total,
}: {
  type: InvestmentTransactionType
  amount: string
  fees: string
  taxes: string
  total: number | null
}) {
  const { t } = useTranslation()
  const sign = type === 'buy' ? '+' : '−'
  const line = (label: string, value: string, prefix = '') => (
    <div className="flex justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">
        {prefix}
        {formatMoney(Number(value || '0'))}
      </dd>
    </div>
  )

  return (
    <div className="rounded-xl border bg-muted/40 p-4">
      <div className="mb-3 text-sm font-semibold">{t('investmentTransactionForm.summary')}</div>
      <dl className="space-y-1.5 text-sm">
        {line(t('investmentTransactionForm.amount'), amount)}
        {line(t('investmentTransactionForm.fees'), fees, `${sign} `)}
        {line(t('investmentTransactionForm.taxes'), taxes, `${sign} `)}
      </dl>
      <div className="mt-3 flex items-baseline justify-between gap-4 border-t pt-3">
        <span className="text-sm text-muted-foreground">
          {t(type === 'buy' ? 'investmentTransactionForm.totalDebited' : 'investmentTransactionForm.totalCredited')}
        </span>
        <span
          className={cn(
            'text-xl font-semibold tabular-nums',
            total === null ? 'text-muted-foreground' : type === 'buy' ? 'text-negative' : 'text-positive'
          )}
        >
          {total !== null ? formatMoney(type === 'buy' ? -total : total) : '—'}
        </span>
      </div>
    </div>
  )
}

export default function InvestmentTransactionFormPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id?: string }>()
  const isEditing = !!id
  const [searchParams] = useSearchParams()
  const cloneFromId = searchParams.get('cloneFrom')
  const isCloning = !!cloneFromId
  const createTransaction = useCreateInvestmentTransaction()
  const updateTransaction = useUpdateInvestmentTransaction()
  const { data: existingTransaction } = useInvestmentTransaction(id ?? cloneFromId ?? '')

  const { data: accountsResponse, isLoading: isLoadingAccounts } = useAllAccounts('asset')
  const investmentAccounts = (accountsResponse?.items ?? []).filter((account) => account.assetKind === 'investment')
  const { data: instrumentsResponse, isLoading: isLoadingInstruments } = useAllInvestmentInstruments()
  const instruments = useMemo(() => instrumentsResponse?.items ?? [], [instrumentsResponse])

  const requestedType = searchParams.get('type')
  const [type, setType] = useState<InvestmentTransactionType>(
    isInvestmentTransactionType(requestedType) ? requestedType : 'buy'
  )
  const [description, setDescription] = useState('')
  const [accountId, setAccountId] = useState(() => searchParams.get('accountId') ?? '')
  const [instrumentId, setInstrumentId] = useState('')
  const [occurredAt, setOccurredAt] = useState(nowAsDatetimeLocal)
  const [quantity, setQuantity] = useState('')
  const [unitPrice, setUnitPrice] = useState('')
  // The amount follows quantity × price until the user types their own (what the broker actually charged).
  const [amount, setAmount] = useState('')
  const [isAmountEdited, setIsAmountEdited] = useState(false)
  const [fees, setFees] = useState('')
  const [taxes, setTaxes] = useState('')
  const [notes, setNotes] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isFormPopulated, setIsFormPopulated] = useState(false)

  // Once per transaction, not on every refetch: see useFormFill.
  useFormFill(existingTransaction ? `${existingTransaction.id}:${isCloning}` : null, () => {
    if (!existingTransaction) return

    setType(existingTransaction.type)
    setDescription(existingTransaction.description ?? '')
    setAccountId(existingTransaction.account.id)
    setInstrumentId(existingTransaction.instrumentId)
    // A clone (e.g. the next installment of a recurring buy) happens now, not on the original's date.
    setOccurredAt(isCloning ? nowAsDatetimeLocal() : toDatetimeLocal(existingTransaction.occurredAt))
    setQuantity(existingTransaction.quantity)
    setUnitPrice(existingTransaction.unitPrice)
    const storedAmount = String(Number(existingTransaction.amount))
    setAmount(storedAmount)
    setIsAmountEdited(
      Number(storedAmount) !== Number(suggestAmount(existingTransaction.quantity, existingTransaction.unitPrice))
    )
    setFees(existingTransaction.fees)
    setTaxes(existingTransaction.taxes)
    setNotes(existingTransaction.notes ?? '')
    setIsFormPopulated(true)
  })

  const isSaving = createTransaction.isPending || updateTransaction.isPending || isSubmitting

  const accountOptions = useMemo(
    () => investmentAccounts.map((account) => ({ value: account.id, label: formatAccountBalance(account) })),
    [investmentAccounts]
  )

  const instrumentOptions = useMemo(
    () =>
      instruments.map((instrument) => ({
        value: instrument.id,
        label: instrument.ticker ? `${instrument.name} (${instrument.ticker})` : instrument.name,
      })),
    [instruments]
  )

  const suggestedAmount = suggestAmount(quantity, unitPrice)
  const effectiveAmount = isAmountEdited ? amount : (suggestedAmount ?? '')
  const amountDiffers =
    isAmountEdited && suggestedAmount !== null && effectiveAmount !== '' && Number(effectiveAmount) !== Number(suggestedAmount)
  const isAmountPlausible = !amountDiffers || isPlausibleAmount(quantity, unitPrice, effectiveAmount)
  const total = computeTotal(type, effectiveAmount, fees, taxes)

  // Selling more than is held is allowed (the position just goes negative,
  // and shows it), but most likely a typo: say how many are held today.
  const { data: positions } = useAccountOpenPositions(type === 'sell' ? accountId : '')
  const held = (() => {
    if (type !== 'sell' || !accountId || !instrumentId || positions === undefined) return null
    let units = Number(positions.find((p) => p.instrumentId === instrumentId)?.quantity ?? 0)
    // The trade being edited is already in that figure: take it back out.
    if (isEditing && existingTransaction && existingTransaction.account.id === accountId && existingTransaction.instrumentId === instrumentId) {
      units += (existingTransaction.type === 'sell' ? 1 : -1) * Number(existingTransaction.quantity)
    }
    return units
  })()
  const sellsMoreThanHeld = held !== null && quantity !== '' && Number(quantity) > held + 1e-9

  const handleCancel = () => {
    if (isEditing) {
      navigate(-1)
    } else {
      navigate('/transactions/investments')
    }
  }

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setIsSubmitting(true)

    try {
      const payload = {
        description: description || null,
        accountId,
        instrumentId,
        type,
        quantity,
        unitPrice,
        amount: effectiveAmount || undefined,
        fees: fees || '0',
        taxes: taxes || '0',
        occurredAt: fromDatetimeLocal(occurredAt),
        // An edit keeps where it happened; a new one happens where this device is.
        timezone: isEditing ? undefined : deviceTimezone(),
        notes: notes || null,
      }

      let saved
      if (isEditing && id) {
        saved = await updateTransaction.mutateAsync({ id, data: payload })
        navigate(-1)
      } else {
        saved = await createTransaction.mutateAsync(payload)
        navigate('/transactions/investments')
      }

      if (saved.pricesIncomplete) {
        toast.warning(t('investmentTransactionForm.pricesIncompleteWarning', { instrument: saved.instrumentName }))
      }
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  const isReferenceDataLoading = isLoadingAccounts || isLoadingInstruments

  if ((isEditing || isCloning) && (!isFormPopulated || isReferenceDataLoading)) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={t(isEditing ? 'investmentTransactionForm.editTitle' : 'investmentTransactionForm.title')}
          description={t('investmentTransactionForm.description')}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          {[0, 1].map((i) => (
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
        title={t(isEditing ? 'investmentTransactionForm.editTitle' : 'investmentTransactionForm.title')}
        description={t('investmentTransactionForm.description')}
      />

      {/* Phones: two compact buttons side by side, no explanation. */}
      <div className="grid grid-cols-2 gap-2 md:gap-3">
        {(['buy', 'sell'] as InvestmentTransactionType[]).map((option) => {
          const Icon = TYPE_ICONS[option]
          const isActive = type === option
          return (
            <button
              key={option}
              type="button"
              onClick={() => setType(option)}
              className={cn(
                'flex items-center justify-center gap-2 rounded-xl border-2 bg-card p-3 text-left transition-colors hover:bg-accent/50 md:justify-start md:gap-3 md:p-4',
                isActive ? TYPE_STYLES[option].active : 'border-border'
              )}
            >
              <Icon className={cn('h-6 w-6 shrink-0 md:h-8 md:w-8', TYPE_STYLES[option].icon)} />
              <div>
                <div className="font-semibold">{t(`investmentTransactions.type_${option}`)}</div>
                <div className="hidden text-xs text-muted-foreground md:block">
                  {t(`investmentTransactionForm.type${option === 'buy' ? 'Buy' : 'Sell'}Description`)}
                </div>
              </div>
            </button>
          )
        })}
      </div>

      {/* What, when and how much first, the read-only summary after the amounts (beside them on desktop), notes last. */}
      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Below desktop the left column dissolves, so the summary can come right after the amounts. */}
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="max-lg:contents lg:space-y-6">
            <FormSection title={t('investmentTransactionForm.sectionTrade')} className="max-lg:order-1" contentClassName="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="description">{t('investmentTransactionForm.descriptionField')}</Label>
                  <Input id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="occurredAt">{t('investmentTransactionForm.occurredAt')}</Label>
                  <Input
                    id="occurredAt"
                    type="datetime-local"
                    required
                    value={occurredAt}
                    onChange={(e) => setOccurredAt(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label>{t('investmentTransactionForm.account')}</Label>
                  <Combobox
                    options={accountOptions}
                    value={accountId}
                    onChange={setAccountId}
                    placeholder={t('investmentTransactionForm.selectAccount')}
                  />
                </div>
                <div className="space-y-2">
                  <Label>{t('investmentTransactionForm.instrument')}</Label>
                  <Combobox
                    options={instrumentOptions}
                    value={instrumentId}
                    onChange={setInstrumentId}
                    placeholder={t('investmentTransactionForm.selectInstrument')}
                  />
                </div>

                {/* Side by side on phones too: two short numbers. */}
                <div className="grid grid-cols-2 gap-4 sm:col-span-2">
                  <div className="min-w-0 space-y-2">
                    <Label htmlFor="quantity">{t('investmentTransactionForm.quantity')}</Label>
                    <Input
                      id="quantity"
                      type="number"
                      step="0.000001"
                      min="0.000001"
                      required
                      placeholder={numberFormatter({ minimumFractionDigits: 6 }).format(0)}
                      value={quantity}
                      onChange={(e) => setQuantity(e.target.value)}
                    />
                  </div>
                  <div className="min-w-0 space-y-2">
                    <Label htmlFor="unitPrice">{t('investmentTransactionForm.unitPrice')}</Label>
                    <Input
                      id="unitPrice"
                      type="number"
                      step="0.0001"
                      min="0.0001"
                      required
                      value={unitPrice}
                      onChange={(e) => setUnitPrice(e.target.value)}
                    />
                  </div>
                  {held !== null && (
                    <p className={cn('col-span-2 text-xs', sellsMoreThanHeld ? 'text-warning' : 'text-muted-foreground')}>
                      {t(sellsMoreThanHeld ? 'investmentTransactionForm.sellExceedsHolding' : 'investmentTransactionForm.heldToday', {
                        quantity: formatQuantity(Math.max(held, 0)),
                      })}
                    </p>
                  )}
                </div>
            </FormSection>

            <FormSection title={t('investmentTransactionForm.sectionAmounts')} className="max-lg:order-2" contentClassName="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div className="col-span-2 min-w-0 space-y-2 sm:col-span-1">
                  <Label htmlFor="amount">{t('investmentTransactionForm.amount')}</Label>
                  <Input
                    id="amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    required
                    value={effectiveAmount}
                    aria-invalid={!isAmountPlausible}
                    className={cn('tabular-nums', !isAmountPlausible && 'border-destructive')}
                    onChange={(e) => {
                      setAmount(e.target.value)
                      setIsAmountEdited(true)
                    }}
                    onBlur={() => {
                      if (amount === '') setIsAmountEdited(false)
                    }}
                  />
                </div>
                <div className="min-w-0 space-y-2">
                  <Label htmlFor="fees">{t('investmentTransactionForm.fees')}</Label>
                  <Input
                    id="fees"
                    type="number"
                    step="0.0001"
                    min="0"
                    value={fees}
                    onChange={(e) => setFees(e.target.value)}
                  />
                </div>
                <div className="min-w-0 space-y-2">
                  <Label htmlFor="taxes">{t('investmentTransactionForm.taxes')}</Label>
                  <Input
                    id="taxes"
                    type="number"
                    step="0.0001"
                    min="0"
                    value={taxes}
                    onChange={(e) => setTaxes(e.target.value)}
                  />
                </div>
                <p
                  className={cn(
                    'col-span-2 text-xs sm:col-span-3',
                    isAmountPlausible ? 'text-muted-foreground' : 'text-destructive'
                  )}
                >
                  {amountDiffers && suggestedAmount !== null
                    ? t(isAmountPlausible ? 'investmentTransactionForm.amountHint' : 'investmentTransactionForm.amountImplausible', {
                        value: formatMoney(suggestedAmount),
                      })
                    : t('investmentTransactionForm.amountFollows')}
                </p>
            </FormSection>

            <FormSection
              title={<label htmlFor="notes">{t('classificationCommon.notes')}</label>}
              optional
              className="max-lg:order-4"
            >
              <textarea
                id="notes"
                rows={2}
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </FormSection>
          </div>

          {/* Read only: what the account is charged (BUY) or credited (SELL). */}
          <div className="max-lg:order-3 lg:sticky lg:top-6">
            <TradeSummary type={type} amount={effectiveAmount} fees={fees} taxes={taxes} total={total} />
          </div>
        </div>

        <FormActions>
          <Button type="button" variant="outline" onClick={handleCancel}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={isSaving || !accountId || !instrumentId || !isAmountPlausible}>
            {t('common.save')}
          </Button>
        </FormActions>
      </form>
    </div>
  )
}
