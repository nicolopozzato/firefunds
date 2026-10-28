import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowDownCircle, ArrowUpCircle, Plus, Repeat, X } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import FormActions from '@/components/FormActions'
import FormSkeleton from '@/components/FormSkeleton'
import Field from '@/components/Field'
import ChoiceCards, { type ChoiceCardOption } from '@/components/ChoiceCards'
import Combobox from '@/components/Combobox'
import TagsInput from '@/components/TagsInput'
import SegmentedControl from '@/components/ui/segmented-control'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useAllClassification } from '@/hooks/use-classification'
import { useRecurringTransaction, useSaveRecurring } from '@/hooks/use-recurring'
import { useTransaction } from '@/hooks/use-transactions'
import { useFormFill } from '@/hooks/use-form-fill'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import { describeSchedule, monthDayOptions } from '@/lib/recurring'
import { cn, currencySymbolFor, dateLocale, deviceTimezone, formatAccountBalance, nowAsDatetimeLocal } from '@/lib/utils'
import type { AccountType } from '@/types/account'
import type { AmountChange, RecurrenceMode, RecurrenceMonthDay, RecurrenceUnit, RecurringKind, WeekendShift } from '@/types/recurring'

const SIDES: Record<RecurringKind, { source: AccountType; destination: AccountType }> = {
  withdrawal: { source: 'asset', destination: 'expense' },
  deposit: { source: 'revenue', destination: 'asset' },
  transfer: { source: 'asset', destination: 'asset' },
}

/** The side that may be left empty, as in the transaction form. */
const OPTIONAL_SIDE: Partial<Record<RecurringKind, 'source' | 'destination'>> = { withdrawal: 'destination', deposit: 'source' }

const NEW_PREFIX = 'NEW:'
const isPending = (id: string) => id.startsWith(NEW_PREFIX)
const pendingName = (id: string) => id.slice(NEW_PREFIX.length)

type Ending = 'never' | 'date' | 'count'

/**
 * A recurring transaction: the transaction it creates (as in the transaction
 * form), when it comes back and what happens then. New ones can start from a
 * transaction (`?fromTransaction=`), which then counts as the first.
 */
export default function RecurringTransactionFormPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id?: string }>()
  const isEditing = !!id
  const [searchParams] = useSearchParams()
  const fromTransactionId = isEditing ? null : searchParams.get('fromTransaction')
  const { user } = useAuth()
  const save = useSaveRecurring()
  const { data: existing } = useRecurringTransaction(id ?? '')
  const { data: fromTransaction } = useTransaction(fromTransactionId ?? '')
  const { data: categoriesResponse } = useAllClassification('categories')
  const { data: tagsResponse } = useAllClassification('tags')

  const [kind, setKind] = useState<RecurringKind>('withdrawal')
  const sides = SIDES[kind]
  const { data: sourceAccounts } = useAllAccounts(sides.source)
  const { data: destinationAccounts } = useAllAccounts(sides.destination)

  const [description, setDescription] = useState('')
  const [amount, setAmount] = useState('')
  const [amountChanges, setAmountChanges] = useState<AmountChange[]>([])
  const [destinationAmount, setDestinationAmount] = useState('')
  const [sourceId, setSourceId] = useState(() => (isEditing ? '' : user?.defaultAccountId ?? ''))
  const [destinationId, setDestinationId] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [tagIds, setTagIds] = useState<string[]>([])
  const [notes, setNotes] = useState('')
  const [startDate, setStartDate] = useState(() => nowAsDatetimeLocal().slice(0, 10))
  const [time, setTime] = useState('12:00')
  const [unit, setUnit] = useState<RecurrenceUnit>('month')
  const [every, setEvery] = useState('1')
  const [monthDay, setMonthDay] = useState<RecurrenceMonthDay>('day')
  const [weekendShift, setWeekendShift] = useState<WeekendShift>('none')
  const [someMonths, setSomeMonths] = useState(false)
  const [months, setMonths] = useState<number[]>([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])
  const [ending, setEnding] = useState<Ending>('never')
  const [endDate, setEndDate] = useState('')
  const [count, setCount] = useState('')
  const [mode, setMode] = useState<RecurrenceMode>('auto')
  const [reminderDays, setReminderDays] = useState('3')
  const [filled, setFilled] = useState(false)

  useFormFill(existing ? existing.id : null, () => {
    if (!existing) return
    setKind(existing.kind)
    setDescription(existing.description)
    setAmount(String(Number(existing.amount)))
    setAmountChanges(existing.amountChanges.map((change) => ({ from: change.from, amount: String(Number(change.amount)) })))
    setDestinationAmount(existing.destinationAmount ? String(Number(existing.destinationAmount)) : '')
    setSourceId(existing.sourceAccount?.id ?? '')
    setDestinationId(existing.destinationAccount?.id ?? '')
    setCategoryId(existing.categoryId ?? '')
    setTagIds(existing.tags.map((tag) => tag.id))
    setNotes(existing.notes ?? '')
    setStartDate(existing.startDate)
    setTime(existing.time)
    setUnit(existing.unit)
    setEvery(String(existing.interval))
    setMonthDay(existing.monthDay)
    setWeekendShift(existing.weekendShift)
    setSomeMonths(existing.months !== null)
    if (existing.months) setMonths(existing.months)
    setEnding(existing.endDate ? 'date' : existing.count ? 'count' : 'never')
    setEndDate(existing.endDate ?? '')
    setCount(existing.count ? String(existing.count) : '')
    setMode(existing.mode)
    setReminderDays(String(existing.reminderDays))
    setFilled(true)
  })

  // Made from a transaction: everything it has, starting on its day at its time.
  useFormFill(fromTransaction ? `from:${fromTransaction.id}` : null, () => {
    if (!fromTransaction) return
    const source = fromTransaction.sourceAccount
    const destination = fromTransaction.destinationAccount
    setKind(source?.type === 'asset' && destination?.type === 'asset' ? 'transfer' : source?.type === 'asset' ? 'withdrawal' : 'deposit')
    setDescription(fromTransaction.description ?? '')
    setAmount(String(Number(fromTransaction.amount)))
    setSourceId(source?.id ?? '')
    setDestinationId(destination?.id ?? '')
    setCategoryId(fromTransaction.categoryId ?? '')
    setTagIds(fromTransaction.tags.map((tag) => tag.id))
    setStartDate(fromTransaction.occurredAt.slice(0, 10))
    setTime(fromTransaction.occurredAt.slice(11, 16))
  })

  const changeKind = (next: RecurringKind) => {
    setKind(next)
    const asset = user?.defaultAccountId ?? ''
    setSourceId(next === 'deposit' ? '' : asset)
    setDestinationId(next === 'deposit' ? asset : '')
  }

  const accountOptions = (accounts: typeof sourceAccounts, type: AccountType, selected: string) => {
    const options = (accounts?.items ?? []).map((account) => ({ value: account.id, label: type === 'asset' ? formatAccountBalance(account) : account.name }))
    if (isPending(selected)) options.push({ value: selected, label: t('transactionForm.pendingNew', { name: pendingName(selected) }) })
    return options
  }

  const sourceCurrency = sourceAccounts?.items.find((account) => account.id === sourceId)?.currencyCode ?? null
  const destinationCurrency = destinationAccounts?.items.find((account) => account.id === destinationId)?.currencyCode ?? null
  const isCurrencyTransfer = kind === 'transfer' && !!sourceCurrency && !!destinationCurrency && sourceCurrency !== destinationCurrency
  const assetCurrency = sides.source === 'asset' ? sourceCurrency : destinationCurrency
  const dayOptions = useMemo(() => monthDayOptions(startDate || nowAsDatetimeLocal().slice(0, 10), t), [startDate, t])
  // A start date that no longer offers the chosen day (e.g. "last Tuesday" moved to the 3rd): back to its day.
  const effectiveMonthDay = dayOptions.some((option) => option.value === monthDay) ? monthDay : 'day'
  const monthNames = useMemo(
    () => Array.from({ length: 12 }, (_, i) => new Intl.DateTimeFormat(dateLocale(), { month: 'short' }).format(new Date(2026, i, 1))),
    []
  )

  const schedule = {
    startDate,
    unit,
    interval: Math.max(1, Number(every) || 1),
    monthDay: effectiveMonthDay,
    weekendShift: unit === 'day' ? ('none' as const) : weekendShift,
    months: someMonths && months.length < 12 ? [...months].sort((a, b) => a - b) : null,
    endDate: ending === 'date' && endDate ? endDate : null,
    count: ending === 'count' && Number(count) > 0 ? Number(count) : null,
  }

  const kindOptions: ChoiceCardOption<RecurringKind>[] = [
    { value: 'withdrawal', label: t('quickAdd.withdrawal'), icon: ArrowUpCircle, tone: 'negative' },
    { value: 'deposit', label: t('quickAdd.deposit'), icon: ArrowDownCircle, tone: 'positive' },
    { value: 'transfer', label: t('quickAdd.transfer'), icon: Repeat, tone: 'info' },
  ]

  const canSave =
    !save.isPending &&
    description.trim() !== '' &&
    Number(amount) > 0 &&
    !!startDate &&
    (!!sourceId || OPTIONAL_SIDE[kind] === 'source') &&
    (!!destinationId || OPTIONAL_SIDE[kind] === 'destination') &&
    (!someMonths || months.length > 0) &&
    amountChanges.every((change) => !!change.from && Number(change.amount) > 0)

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const side = (value: string) => (isPending(value) ? { id: null, name: pendingName(value) } : { id: value || null, name: null })
    const source = side(sourceId)
    const destination = side(destinationId)
    const category = side(categoryId)
    try {
      const saved = await save.mutateAsync({
        id,
        data: {
          description: description.trim(),
          amount,
          amountChanges,
          destinationAmount: isCurrencyTransfer ? destinationAmount || null : null,
          sourceAccountId: source.id,
          newSourceAccountName: source.name,
          destinationAccountId: destination.id,
          newDestinationAccountName: destination.name,
          categoryId: category.id,
          newCategoryName: category.name,
          tagIds: tagIds.filter((tagId) => !isPending(tagId)),
          newTagNames: tagIds.filter(isPending).map(pendingName),
          notes: notes || null,
          ...schedule,
          time,
          timezone: isEditing ? undefined : deviceTimezone(),
          mode,
          reminderDays: Math.min(30, Math.max(0, Number(reminderDays) || 0)),
          fromTransactionId,
        },
      })
      toast.success(t('recurring.saved'))
      navigate(`/transactions/recurring/${saved.id}`, { replace: true })
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  if (isEditing && !filled) {
    return (
      <div className="max-w-5xl space-y-6">
        <PageHeader title={t('recurring.editTitle')} />
        <FormSkeleton fieldCount={6} />
      </div>
    )
  }

  return (
    <div className="max-w-5xl space-y-6">
      <PageHeader title={t(isEditing ? 'recurring.editTitle' : 'recurring.newTitle')} description={t('recurring.formDescription')} />

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
          <div className="space-y-6">
            <ChoiceCards label={t('transactionForm.type')} options={kindOptions} value={kind} onChange={changeKind} />

            <FormSection title={t('transactionForm.sectionTransaction')} contentClassName="grid gap-4 sm:grid-cols-2">
              <Field label={t('transactionForm.amount')} htmlFor="amount">
                <div className="relative">
                  <Input
                    id="amount"
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min="0.01"
                    required
                    className="h-12 pr-10 text-2xl font-semibold tabular-nums"
                    placeholder="0,00"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                  />
                  <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-base text-muted-foreground">
                    {currencySymbolFor(assetCurrency ?? user?.currency ?? '')}
                  </span>
                </div>
              </Field>
              {isCurrencyTransfer ? (
                <Field label={`${t('transactionForm.destinationAmount')} (${currencySymbolFor(destinationCurrency ?? '')})`} htmlFor="destinationAmount">
                  <Input id="destinationAmount" type="number" inputMode="decimal" step="0.01" min="0.01" required className="sm:h-12 tabular-nums" value={destinationAmount} onChange={(e) => setDestinationAmount(e.target.value)} />
                </Field>
              ) : (
                <div className="max-sm:hidden" />
              )}
              <Field label={t('transactionForm.descriptionField')} htmlFor="description" className="sm:col-span-2">
                <Input id="description" required value={description} onChange={(e) => setDescription(e.target.value)} />
              </Field>
              <Field label={t(kind === 'deposit' ? 'transactionForm.sourceRevenue' : 'transactionForm.sourceAsset')}>
                <Combobox
                  options={accountOptions(sourceAccounts, sides.source, sourceId)}
                  value={sourceId}
                  onChange={setSourceId}
                  onCreate={sides.source === 'asset' ? undefined : (name) => setSourceId(`${NEW_PREFIX}${name}`)}
                  placeholder={t(sides.source === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
                  clearable={OPTIONAL_SIDE[kind] === 'source'}
                />
              </Field>
              <Field label={t(kind === 'withdrawal' ? 'transactionForm.destinationExpense' : 'transactionForm.destinationAsset')}>
                <Combobox
                  options={accountOptions(destinationAccounts, sides.destination, destinationId)}
                  value={destinationId}
                  onChange={setDestinationId}
                  onCreate={sides.destination === 'asset' ? undefined : (name) => setDestinationId(`${NEW_PREFIX}${name}`)}
                  placeholder={t(sides.destination === 'asset' ? 'transactionForm.selectAccount' : 'transactionForm.selectCounterparty')}
                  clearable={OPTIONAL_SIDE[kind] === 'destination'}
                />
              </Field>

              <div className="space-y-2 sm:col-span-2">
                {amountChanges.length > 0 && <div className="text-sm font-medium">{t('recurring.amountChanges')}</div>}
                {amountChanges.map((change, index) => {
                  const update = (patch: Partial<AmountChange>) =>
                    setAmountChanges((current) => current.map((one, i) => (i === index ? { ...one, ...patch } : one)))
                  return (
                    <div key={index} className="flex items-center gap-2">
                      <span className="shrink-0 text-sm text-muted-foreground">{t('recurring.changeFrom')}</span>
                      <Input
                        type="date"
                        aria-label={t('recurring.changeDay')}
                        className="w-40"
                        min={nowAsDatetimeLocal().slice(0, 10)}
                        required
                        value={change.from}
                        onChange={(e) => update({ from: e.target.value })}
                      />
                      <div className="relative w-32">
                        <Input
                          type="number"
                          inputMode="decimal"
                          step="0.01"
                          min="0.01"
                          required
                          aria-label={t('recurring.changeAmount')}
                          className="pr-8 tabular-nums"
                          value={change.amount}
                          onChange={(e) => update({ amount: e.target.value })}
                        />
                        <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                          {currencySymbolFor(assetCurrency ?? user?.currency ?? '')}
                        </span>
                      </div>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('recurring.removeChange')}
                        onClick={() => setAmountChanges((current) => current.filter((_, i) => i !== index))}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    </div>
                  )
                })}
                <button
                  type="button"
                  onClick={() => setAmountChanges((current) => [...current, { from: '', amount: amount }])}
                  className="flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80"
                >
                  <Plus className="h-4 w-4" />
                  {t('recurring.addChange')}
                </button>
              </div>
            </FormSection>

            <FormSection title={t('recurring.sectionSchedule')} contentClassName="grid gap-4 sm:grid-cols-2">
              <Field label={t('recurring.every')}>
                <div className="flex gap-2">
                  <Input
                    type="number"
                    inputMode="numeric"
                    min="1"
                    max="366"
                    aria-label={t('recurring.interval')}
                    className="w-20 tabular-nums"
                    value={every}
                    onChange={(e) => setEvery(e.target.value)}
                  />
                  <Select value={unit} onValueChange={(value) => setUnit(value as RecurrenceUnit)}>
                    <SelectTrigger className="flex-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(['day', 'week', 'month', 'year'] as const).map((value) => (
                        <SelectItem key={value} value={value}>
                          {t(`recurring.unit_${value}`, { count: Math.max(1, Number(every) || 1) })}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </Field>
              <Field label={t('recurring.startDate')} htmlFor="startDate">
                <Input id="startDate" type="date" required value={startDate} onChange={(e) => setStartDate(e.target.value)} />
              </Field>

              {(unit === 'month' || unit === 'year') && (
                <Field label={t('recurring.onWhichDay')}>
                  <Select value={effectiveMonthDay} onValueChange={(value) => setMonthDay(value as RecurrenceMonthDay)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {dayOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
              )}
              <Field label={t('recurring.time')} htmlFor="time" hint={t('recurring.timeHint')}>
                <Input id="time" type="time" required value={time} onChange={(e) => setTime(e.target.value)} />
              </Field>

              {unit !== 'day' && (
                <Field label={t('recurring.weekend')} className="sm:col-span-2">
                  <SegmentedControl
                    label={t('recurring.weekend')}
                    value={weekendShift}
                    onChange={setWeekendShift}
                    options={[
                      { value: 'none', label: t('recurring.weekendOption_none') },
                      { value: 'before', label: t('recurring.weekendOption_before') },
                      { value: 'after', label: t('recurring.weekendOption_after') },
                    ]}
                  />
                </Field>
              )}

              <div className="space-y-3 sm:col-span-2">
                <div className="flex items-center gap-2.5">
                  <Checkbox id="someMonths" checked={someMonths} onChange={(e) => setSomeMonths(e.target.checked)} />
                  <Label htmlFor="someMonths" className="font-normal">
                    {t('recurring.someMonths')}
                  </Label>
                </div>
                {someMonths && (
                  <div className="grid grid-cols-6 gap-1.5 sm:grid-cols-12" role="group" aria-label={t('recurring.someMonths')}>
                    {monthNames.map((name, index) => {
                      const month = index + 1
                      const on = months.includes(month)
                      return (
                        <button
                          key={month}
                          type="button"
                          aria-pressed={on}
                          onClick={() => setMonths(on ? months.filter((m) => m !== month) : [...months, month])}
                          className={cn(
                            'h-9 rounded-md border text-xs font-medium capitalize transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            on ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent'
                          )}
                        >
                          {name.replace('.', '')}
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>

              <Field label={t('recurring.ends')} className="sm:col-span-2">
                <div className="flex flex-wrap items-center gap-3">
                  <SegmentedControl
                    label={t('recurring.ends')}
                    value={ending}
                    onChange={setEnding}
                    options={[
                      { value: 'never', label: t('recurring.end_never') },
                      { value: 'date', label: t('recurring.end_date') },
                      { value: 'count', label: t('recurring.end_count') },
                    ]}
                  />
                  {ending === 'date' && (
                    <Input type="date" aria-label={t('recurring.endDate')} className="w-44" min={startDate} required value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                  )}
                  {ending === 'count' && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                      <Input type="number" inputMode="numeric" min="1" aria-label={t('recurring.count')} className="w-24 tabular-nums" required value={count} onChange={(e) => setCount(e.target.value)} />
                      {t('recurring.timesFromStart')}
                    </div>
                  )}
                </div>
              </Field>

              {startDate && <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm sm:col-span-2">{describeSchedule(schedule, t)}</p>}
            </FormSection>

            <FormSection title={t('transactionForm.sectionClassification')} optional contentClassName="grid gap-4 sm:grid-cols-2">
              <Field label={t('transactionForm.category')}>
                <Combobox
                  options={[
                    ...(categoriesResponse?.items ?? []).map((category) => ({ value: category.id, label: category.name })),
                    ...(isPending(categoryId) ? [{ value: categoryId, label: t('transactionForm.pendingNew', { name: pendingName(categoryId) }) }] : []),
                  ]}
                  value={categoryId}
                  onChange={setCategoryId}
                  onCreate={(name) => setCategoryId(`${NEW_PREFIX}${name}`)}
                  placeholder={t('transactionForm.selectCategory')}
                />
              </Field>
              <Field label={t('transactionForm.tags')}>
                <TagsInput
                  options={[
                    ...(tagsResponse?.items ?? []),
                    ...tagIds.filter(isPending).map((tagId) => ({ id: tagId, name: t('transactionForm.pendingNew', { name: pendingName(tagId) }) })),
                  ]}
                  value={tagIds}
                  onChange={setTagIds}
                  onCreate={(name) => setTagIds((current) => [...current, `${NEW_PREFIX}${name}`])}
                  placeholder={t('tagsInput.add')}
                />
              </Field>
            </FormSection>
          </div>

          <div className="space-y-6">
            <FormSection title={t('recurring.onTheDay')}>
              <div role="radiogroup" aria-label={t('recurring.onTheDay')} className="space-y-2">
                {(['auto', 'confirm'] as const).map((value) => (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={mode === value}
                    onClick={() => setMode(value)}
                    className={cn(
                      'flex w-full items-start gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                      mode === value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent/50'
                    )}
                  >
                    <span className={cn('mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border', mode === value ? 'border-primary' : 'border-muted-foreground/40')}>
                      {mode === value && <span className="h-2 w-2 rounded-full bg-primary" />}
                    </span>
                    <span>
                      <span className="block text-sm font-medium">{t(`recurring.mode_${value}`)}</span>
                      <span className="block text-xs text-muted-foreground">{t(`recurring.modeDescription_${value}`)}</span>
                    </span>
                  </button>
                ))}
              </div>
              {mode === 'confirm' && (
                <Field label={t('recurring.reminderDays')} htmlFor="reminderDays" hint={t('recurring.reminderDaysHint')} className="mt-4">
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Input
                      id="reminderDays"
                      type="number"
                      inputMode="numeric"
                      min="0"
                      max="30"
                      className="w-20 tabular-nums"
                      value={reminderDays}
                      onChange={(e) => setReminderDays(e.target.value)}
                    />
                    {t('recurring.daysAfter')}
                  </div>
                </Field>
              )}
            </FormSection>

            <FormSection title={<label htmlFor="notes">{t('classificationCommon.notes')}</label>} optional>
              <Textarea id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </FormSection>
          </div>
        </div>

        <FormActions>
          <Button type="button" variant="outline" onClick={() => navigate(-1)}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={!canSave}>
            {t('common.save')}
          </Button>
        </FormActions>
      </form>
    </div>
  )
}
