import { useMemo, useRef, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowDownToLine, ArrowUpToLine } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import ChoiceCards from '@/components/ChoiceCards'
import Field from '@/components/Field'
import { TradeDescriptionField } from '@/components/DescriptionField'
import QuickDates from '@/components/QuickDates'
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
import { cn, currencySymbolFor, deviceTimezone, formatAccountBalance, formatMoney, formatQuantity, fromDatetimeLocal, nowAsDatetimeLocal, numberFormatter, toDatetimeLocal } from '@/lib/utils'
import type { InvestmentTransaction, InvestmentTransactionType } from '@/types/investment'
import FormActions from '@/components/FormActions'

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
    <div className="rounded-xl border bg-muted/40 p-4 sm:p-5">
      <div className="section-title mb-3">{t('investmentTransactionForm.summary')}</div>
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
            'figure-md',
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
  /** Set by "Save and new": after saving, the form starts again instead of leaving. */
  const addAnotherRef = useRef(false)
  const quantityRef = useRef<HTMLInputElement>(null)

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

  // Quoted in the instrument's currency, charged in the account's: with two currencies the amount isn't
  // quantity × price, so it isn't suggested: it is what the broker charged, which the rate follows from.
  const accountCurrency = investmentAccounts.find((account) => account.id === accountId)?.currencyCode ?? null
  const quoteCurrency = instruments.find((instrument) => instrument.id === instrumentId)?.currencyCode ?? null
  const isOtherCurrency = accountCurrency !== null && quoteCurrency !== null && accountCurrency !== quoteCurrency
  const suggestedAmount = isOtherCurrency ? null : suggestAmount(quantity, unitPrice)
  const effectiveAmount = isAmountEdited ? amount : (suggestedAmount ?? '')
  const amountDiffers =
    isAmountEdited && suggestedAmount !== null && effectiveAmount !== '' && Number(effectiveAmount) !== Number(suggestedAmount)
  const grossInQuote = Number(quantity) * Number(unitPrice)
  const appliedRate =
    isOtherCurrency && grossInQuote > 0 && Number(effectiveAmount) > 0 ? Number(effectiveAmount) / grossInQuote : null
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
        if (addAnotherRef.current) {
          startAnother()
        } else {
          navigate('/transactions/investments')
        }
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

  /**
   * A past trade picked under the description (the monthly buy of a plan):
   * its kind, account, instrument and fees; units, price and day are this one's.
   */
  const copyFrom = (past: InvestmentTransaction) => {
    setType(past.type)
    setAccountId(past.account.id)
    setInstrumentId(past.instrumentId)
    setFees(Number(past.fees) ? String(Number(past.fees)) : '')
    quantityRef.current?.focus()
  }

  /**
   * After "Save and new": the next trade of a run (several buys on the same
   * day, a plan's installments) keeps the kind, account and day, the cursor
   * on the units.
   */
  const startAnother = () => {
    toast.success(t('investmentTransactionForm.savedAddAnother'))
    setDescription('')
    setInstrumentId('')
    setQuantity('')
    setUnitPrice('')
    setAmount('')
    setIsAmountEdited(false)
    setFees('')
    setTaxes('')
    setNotes('')
    quantityRef.current?.focus()
  }

  const isReferenceDataLoading = isLoadingAccounts || isLoadingInstruments
  const isSaveDisabled = isSaving || !accountId || !instrumentId

  if ((isEditing || isCloning) && (!isFormPopulated || isReferenceDataLoading)) {
    return (
      <div className="max-w-5xl space-y-6">
        <PageHeader
          title={t(isEditing ? 'investmentTransactionForm.editTitle' : 'investmentTransactionForm.title')}
          description={t('investmentTransactionForm.description')}
        />
        <div className="space-y-6 lg:w-2/3 lg:pr-4">
          <div className="grid grid-cols-2 gap-2 sm:gap-3">
            {[0, 1].map((i) => (
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
        title={t(isEditing ? 'investmentTransactionForm.editTitle' : 'investmentTransactionForm.title')}
        description={t('investmentTransactionForm.description')}
      />

      {/* Desktop: the kind, the trade, its amounts and notes on the left, every field on the same two-column grid,
          the read-only summary beside them. Below that the left column dissolves, so the summary comes right
          after the amounts and the notes last. */}
      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="max-lg:contents lg:space-y-6">
            <ChoiceCards
              label={t('investmentTransactionForm.type')}
              value={type}
              onChange={setType}
              options={[
                { value: 'buy', label: t('investmentTransactions.type_buy'), icon: ArrowDownToLine, tone: 'negative' },
                { value: 'sell', label: t('investmentTransactions.type_sell'), icon: ArrowUpToLine, tone: 'positive' },
              ]}
            />

            <FormSection title={t('investmentTransactionForm.sectionTrade')} className="max-lg:order-1" contentClassName="grid gap-4 sm:grid-cols-2">
              <Field label={t('investmentTransactionForm.descriptionField')} htmlFor="description">
                <TradeDescriptionField id="description" value={description} onChange={setDescription} onPick={isEditing ? undefined : copyFrom} />
              </Field>
              <Field
                label={t('investmentTransactionForm.occurredAt')}
                htmlFor="occurredAt"
                labelAside={<QuickDates value={occurredAt} onChange={setOccurredAt} />}
              >
                <Input id="occurredAt" type="datetime-local" required value={occurredAt} onChange={(e) => setOccurredAt(e.target.value)} />
              </Field>
              <Field label={t('investmentTransactionForm.account')}>
                <Combobox
                  options={accountOptions}
                  value={accountId}
                  onChange={setAccountId}
                  placeholder={t('investmentTransactionForm.selectAccount')}
                />
              </Field>
              <Field label={t('investmentTransactionForm.instrument')}>
                <Combobox
                  options={instrumentOptions}
                  value={instrumentId}
                  onChange={setInstrumentId}
                  placeholder={t('investmentTransactionForm.selectInstrument')}
                />
              </Field>

              {/* Side by side on phones too: two short numbers. */}
              <div className="grid grid-cols-2 gap-4 sm:col-span-2">
                <Field label={t('investmentTransactionForm.quantity')} htmlFor="quantity">
                  <Input
                    ref={quantityRef}
                    id="quantity"
                    type="number"
                    inputMode="decimal"
                    className="tabular-nums"
                    step="0.000001"
                    min="0.000001"
                    required
                    placeholder={numberFormatter({ minimumFractionDigits: 6 }).format(0)}
                    value={quantity}
                    onChange={(e) => setQuantity(e.target.value)}
                  />
                </Field>
                <Field
                  label={
                    <>
                      {t('investmentTransactionForm.unitPrice')}
                      {quoteCurrency && <span className="ml-1 font-normal text-muted-foreground">({currencySymbolFor(quoteCurrency)})</span>}
                    </>
                  }
                  htmlFor="unitPrice"
                >
                  <Input
                    id="unitPrice"
                    type="number"
                    inputMode="decimal"
                    className="tabular-nums"
                    step="0.0001"
                    min="0.0001"
                    required
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(e.target.value)}
                  />
                </Field>
                {held !== null && (
                  <p className={cn('col-span-2 -mt-2 text-xs', sellsMoreThanHeld ? 'text-warning' : 'text-muted-foreground')}>
                    {t(sellsMoreThanHeld ? 'investmentTransactionForm.sellExceedsHolding' : 'investmentTransactionForm.heldToday', {
                      quantity: formatQuantity(Math.max(held, 0)),
                    })}
                  </p>
                )}
              </div>
            </FormSection>

            <FormSection title={t('investmentTransactionForm.sectionAmounts')} className="max-lg:order-2" contentClassName="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Field
                label={
                  <>
                    {t('investmentTransactionForm.amount')}
                    {accountCurrency && <span className="ml-1 font-normal text-muted-foreground">({currencySymbolFor(accountCurrency)})</span>}
                  </>
                }
                htmlFor="amount"
                className="col-span-2 sm:col-span-1"
              >
                <Input
                  id="amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  value={effectiveAmount}
                  className="tabular-nums"
                  onChange={(e) => {
                    setAmount(e.target.value)
                    setIsAmountEdited(true)
                  }}
                  onBlur={() => {
                    if (amount === '') setIsAmountEdited(false)
                  }}
                />
              </Field>
              <Field label={t('investmentTransactionForm.fees')} htmlFor="fees">
                <Input
                  id="fees"
                  type="number"
                  inputMode="decimal"
                  className="tabular-nums"
                  step="0.0001"
                  min="0"
                  value={fees}
                  onChange={(e) => setFees(e.target.value)}
                />
              </Field>
              <Field label={t('investmentTransactionForm.taxes')} htmlFor="taxes">
                <Input
                  id="taxes"
                  type="number"
                  inputMode="decimal"
                  className="tabular-nums"
                  step="0.0001"
                  min="0"
                  value={taxes}
                  onChange={(e) => setTaxes(e.target.value)}
                />
              </Field>
              <p className="col-span-2 text-xs text-muted-foreground sm:col-span-3">
                {isOtherCurrency
                  ? appliedRate !== null
                    ? t('investmentTransactionForm.appliedRate', {
                        quote: quoteCurrency,
                        rate: numberFormatter({ maximumFractionDigits: 4 }).format(appliedRate),
                        account: accountCurrency,
                      })
                    : t('investmentTransactionForm.amountOtherCurrency', { account: accountCurrency, quote: quoteCurrency })
                  : amountDiffers && suggestedAmount !== null
                    ? t('investmentTransactionForm.amountHint', { value: formatMoney(suggestedAmount) })
                    : t('investmentTransactionForm.amountFollows')}
              </p>
            </FormSection>

            <FormSection title={<label htmlFor="notes">{t('classificationCommon.notes')}</label>} optional className="max-lg:order-4">
              <Textarea id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </FormSection>
          </div>

          {/* Read only: what the account is charged (BUY) or credited (SELL). Level with the trade's section. */}
          <div className="max-lg:order-3 lg:sticky lg:top-6">
            <TradeSummary type={type} amount={effectiveAmount} fees={fees} taxes={taxes} total={total} />
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
