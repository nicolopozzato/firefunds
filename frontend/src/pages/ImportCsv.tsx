import { useMemo, useState, type DragEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRight, FileUp, Scissors, Upload, X } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormSection from '@/components/FormSection'
import FormActions from '@/components/FormActions'
import Field from '@/components/Field'
import Combobox from '@/components/Combobox'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useAllClassification } from '@/hooks/use-classification'
import { useAuth } from '@/contexts/AuthContext'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { DATE_FORMATS, decode, detectDelimiter, guessSettings, headerKey, parse, toRows, type CsvEncoding, type CsvRow, type CsvSettings } from '@/lib/csv'
import { importApi, type ImportPreset, type ImportRowCheck } from '@/lib/import-api'
import { cn, currencySymbolFor, deviceTimezone, formatMoney, formatShortDate } from '@/lib/utils'
import type { CreateTransactionRequest } from '@/types/transaction'

type Step = 'file' | 'columns' | 'preview'
type Kind = 'withdrawal' | 'deposit' | 'transfer'

const NEW_PREFIX = 'NEW:'
const NONE = '__none__'

/**
 * A transaction a file's line becomes: the line itself (`part` 0), or one
 * more when the line is split. The amount is the user's to change (50 paid,
 * 25 recorded); the file's stays in `row`.
 */
interface PreviewRow {
  key: string
  part: number
  row: CsvRow & { date: string; amount: string }
  check: ImportRowCheck
  include: boolean
  /** Positive: the direction is the file's. */
  amount: string
  description: string
  otherId: string
  categoryId: string
}

const abs = (amount: string) => amount.replace(/^-/, '')

/**
 * Importing a bank's CSV file: read in the browser (encoding, separator,
 * columns: a bank recognised from its header uses its preset), checked by the
 * server (duplicates, recurring transactions, the shop and category of
 * before), corrected in the preview, then imported all at once.
 */
export default function ImportCsv() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [step, setStep] = useState<Step>('file')
  const [fileName, setFileName] = useState('')
  const [buffer, setBuffer] = useState<ArrayBuffer | null>(null)
  const [table, setTable] = useState<string[][]>([])
  const [settings, setSettings] = useState<CsvSettings | null>(null)
  const [accountId, setAccountId] = useState('')
  const [preset, setPreset] = useState<ImportPreset | null>(null)
  const [savePreset, setSavePreset] = useState(true)
  const [presetName, setPresetName] = useState('')
  const [rows, setRows] = useState<PreviewRow[]>([])
  const [busy, setBusy] = useState(false)

  const { data: presets } = useQuery({ queryKey: ['import-presets'], queryFn: () => importApi.presets() })
  const { data: assets } = useAllAccounts('asset')
  const { data: expenses } = useAllAccounts('expense')
  const { data: revenues } = useAllAccounts('revenue')
  const { data: categories } = useAllClassification('categories')
  const account = assets?.items.find((item) => item.id === accountId)
  const symbol = currencySymbolFor(account?.currencyCode ?? user?.currency ?? '')

  /** A file, or the same file in another encoding: the table, and the preset whose header it has, else a guess. */
  const read = (data: ArrayBuffer, encoding?: CsvEncoding) => {
    let used: CsvEncoding = encoding ?? 'utf-8'
    let text = decode(data, used)
    if (!encoding && text.includes('�')) {
      used = 'windows-1252'
      text = decode(data, used)
    }
    const delimiter = settings && encoding ? settings.delimiter : detectDelimiter(text)
    const parsed = parse(text, delimiter)
    setTable(parsed)
    const known = (presets ?? []).find((candidate) => headerKey(parsed[candidate.settings.skipRows] ?? []) === headerKey(candidate.headers))
    if (known) {
      setPreset(known)
      setPresetName(known.name)
      setSettings({ ...known.settings, encoding: used })
      if (known.accountId) setAccountId(known.accountId)
    } else {
      setPreset(null)
      setSettings(guessSettings(parsed, used, delimiter))
    }
  }

  const open = async (file: File | undefined) => {
    if (!file) return
    const data = await file.arrayBuffer()
    setFileName(file.name)
    setBuffer(data)
    setPresetName((current) => current || file.name.replace(/\.[^.]+$/, ''))
    read(data)
    setStep('columns')
  }

  const parsed = useMemo(() => (settings ? toRows(table, settings) : []), [table, settings])
  const valid = parsed.filter((row): row is CsvRow & { date: string; amount: string } => row.date !== null && row.amount !== null && Number(row.amount) !== 0)
  const header = table[settings?.skipRows ?? 0] ?? []
  const columns = header.map((name, i) => ({ value: String(i), label: `${String.fromCharCode(65 + (i % 26))} · ${name.trim() || '—'}` }))

  const check = async () => {
    if (!settings || !accountId) return
    setBusy(true)
    try {
      if (savePreset && presetName.trim()) {
        const saved = await importApi.savePreset({ name: presetName.trim(), accountId, settings, headers: header }, preset?.id)
        setPreset(saved)
        void queryClient.invalidateQueries({ queryKey: ['import-presets'] })
      }
      const checks = await importApi.preview(
        accountId,
        valid.map((row) => ({ date: row.date, amount: row.amount, description: row.description, counterparty: row.counterparty }))
      )
      setRows(
        valid.map((row, i) => {
          const result = checks[i]
          const other = result.recurring?.accountId || result.suggestion?.account?.id || (row.counterparty ? `${NEW_PREFIX}${row.counterparty}` : '')
          return {
            key: String(row.line),
            part: 0,
            row,
            check: result,
            include: result.duplicate === null,
            amount: abs(row.amount),
            description: row.description || row.counterparty,
            otherId: other,
            categoryId: result.recurring?.categoryId ?? result.suggestion?.categoryId ?? '',
          }
        })
      )
      setStep('preview')
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const assetIds = new Set((assets?.items ?? []).map((item) => item.id))
  const kindOf = (row: PreviewRow): Kind => (assetIds.has(row.otherId) ? 'transfer' : row.row.amount.startsWith('-') ? 'withdrawal' : 'deposit')

  const toTransaction = (row: PreviewRow): CreateTransactionRequest => {
    const out = row.row.amount.startsWith('-')
    const other = row.otherId.startsWith(NEW_PREFIX) ? { id: null, name: row.otherId.slice(NEW_PREFIX.length) } : { id: row.otherId || null, name: null }
    const category = row.categoryId.startsWith(NEW_PREFIX) ? { id: null, name: row.categoryId.slice(NEW_PREFIX.length) } : { id: row.categoryId || null, name: null }
    return {
      description: row.description.trim() || null,
      amount: row.amount,
      occurredAt: `${row.row.date}T12:00:00`,
      timezone: deviceTimezone(),
      sourceAccountId: out ? accountId : other.id,
      newSourceAccountName: out ? null : other.name,
      destinationAccountId: out ? other.id : accountId,
      newDestinationAccountName: out ? other.name : null,
      categoryId: category.id,
      newCategoryName: category.name,
      tagIds: [],
    }
  }

  const included = rows.filter((row) => row.include)
  const commit = async () => {
    setBusy(true)
    try {
      const count = await importApi.commit(
        included.map((row) => ({
          transaction: toTransaction(row),
          ...(row.part === 0 && row.check.recurring && row.otherId === row.check.recurring.accountId
            ? { recurringId: row.check.recurring.id, recurringDay: row.check.recurring.day }
            : {}),
        }))
      )
      void dataChanged(queryClient)
      toast.success(t('csvImport.imported', { count }))
      navigate('/transactions')
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const update = (index: number, patch: Partial<PreviewRow>) => setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  /** What a line's parts add up to, to show against the file's amount. */
  const assignedTo = (line: number) => rows.filter((row) => row.row.line === line).reduce((sum, row) => sum + (Number(row.amount) || 0), 0)

  /** One more transaction from the same line, after its last part, offered what is left. */
  const split = (line: number) =>
    setRows((current) => {
      const parts = current.filter((row) => row.row.line === line)
      const first = parts[0]
      const rest = Math.max(0, Math.round((Number(abs(first.row.amount)) - parts.reduce((sum, row) => sum + (Number(row.amount) || 0), 0)) * 100) / 100)
      const part: PreviewRow = {
        ...first,
        key: `${line}-${parts.length}`,
        part: Math.max(...parts.map((row) => row.part)) + 1,
        check: { index: first.check.index, duplicate: null, recurring: null, suggestion: null },
        include: true,
        amount: rest > 0 ? String(rest) : '',
        description: '',
        otherId: '',
        categoryId: '',
      }
      const at = current.indexOf(parts[parts.length - 1]) + 1
      return [...current.slice(0, at), part, ...current.slice(at)]
    })
  const set = (patch: Partial<CsvSettings>) => settings && setSettings({ ...settings, ...patch })

  const otherOptions = (out: boolean, selected: string) => {
    const counterparts = (out ? expenses : revenues)?.items ?? []
    const options = [
      ...counterparts.map((item) => ({ value: item.id, label: item.name })),
      ...(assets?.items ?? []).filter((item) => item.id !== accountId).map((item) => ({ value: item.id, label: item.name, sublabel: t('csvImport.transfer') })),
    ]
    if (selected.startsWith(NEW_PREFIX)) options.push({ value: selected, label: t('transactionForm.pendingNew', { name: selected.slice(NEW_PREFIX.length) }) })
    return options
  }
  const categoryOptions = (selected: string) => [
    ...(categories?.items ?? []).map((category) => ({ value: category.id, label: category.name })),
    ...(selected.startsWith(NEW_PREFIX) ? [{ value: selected, label: t('transactionForm.pendingNew', { name: selected.slice(NEW_PREFIX.length) }) }] : []),
  ]

  return (
    <div className={cn('space-y-6', step === 'preview' ? 'max-w-6xl' : 'max-w-3xl')}>
      <PageHeader title={t('csvImport.title')} description={t('csvImport.description')} />
      <Steps step={step} />

      {step === 'file' && <DropZone onFile={(file) => void open(file)} />}

      {step === 'columns' && settings && (
        <>
          <FormSection
            title={fileName}
            aside={preset && <Badge variant="info" className="font-normal">{t('csvImport.recognised', { name: preset.name })}</Badge>}
            contentClassName="grid gap-4 sm:grid-cols-2"
          >
            <Field label={t('csvImport.account')} className="sm:col-span-2">
              <Combobox
                options={(assets?.items ?? []).map((item) => ({ value: item.id, label: item.name }))}
                value={accountId}
                onChange={setAccountId}
                placeholder={t('transactionForm.selectAccount')}
              />
            </Field>
            <Field label={t('csvImport.encoding')} hint={t('csvImport.encodingHint')}>
              <SelectField
                value={settings.encoding}
                onChange={(encoding) => buffer && read(buffer, encoding as CsvEncoding)}
                options={[
                  { value: 'utf-8', label: 'UTF-8' },
                  { value: 'windows-1252', label: 'Windows (Latin-1)' },
                ]}
              />
            </Field>
            <Field label={t('csvImport.skipRows')} hint={t('csvImport.skipRowsHint')}>
              <Input
                type="number"
                min="0"
                max="50"
                value={settings.skipRows}
                // Another header: the columns are guessed again from it.
                onChange={(e) => setSettings(guessSettings(table, settings.encoding, settings.delimiter, Math.max(0, Number(e.target.value) || 0)))}
              />
            </Field>
            <Field label={t('csvImport.dateColumn')}>
              <SelectField value={String(settings.dateColumn)} onChange={(v) => set({ dateColumn: Number(v) })} options={columns} />
            </Field>
            <Field label={t('csvImport.dateFormat')}>
              <SelectField value={settings.dateFormat} onChange={(v) => set({ dateFormat: v as CsvSettings['dateFormat'] })} options={DATE_FORMATS.map((format) => ({ value: format, label: format }))} />
            </Field>
            <Field label={t('csvImport.amountMode')}>
              <SelectField
                value={settings.amountMode}
                onChange={(v) => set({ amountMode: v as CsvSettings['amountMode'] })}
                options={[
                  { value: 'single', label: t('csvImport.amountSingle') },
                  { value: 'split', label: t('csvImport.amountSplit') },
                ]}
              />
            </Field>
            <Field label={t('csvImport.decimal')}>
              <SelectField value={settings.decimal} onChange={(v) => set({ decimal: v as CsvSettings['decimal'] })} options={[{ value: ',', label: '1.234,56' }, { value: '.', label: '1,234.56' }]} />
            </Field>
            {settings.amountMode === 'single' ? (
              <Field label={t('csvImport.amountColumn')}>
                <SelectField value={String(settings.amountColumn)} onChange={(v) => set({ amountColumn: Number(v) })} options={columns} />
              </Field>
            ) : (
              <>
                <Field label={t('csvImport.debitColumn')}>
                  <SelectField value={String(settings.debitColumn)} onChange={(v) => set({ debitColumn: Number(v) })} options={columns} />
                </Field>
                <Field label={t('csvImport.creditColumn')}>
                  <SelectField value={String(settings.creditColumn)} onChange={(v) => set({ creditColumn: Number(v) })} options={columns} />
                </Field>
              </>
            )}
            <Field label={t('csvImport.descriptionColumns')} hint={t('csvImport.descriptionColumnsHint')} className="sm:col-span-2">
              <div className="flex flex-wrap gap-1.5">
                {columns.map((column) => {
                  const on = settings.descriptionColumns.includes(Number(column.value))
                  return (
                    <button
                      key={column.value}
                      type="button"
                      aria-pressed={on}
                      onClick={() => set({ descriptionColumns: on ? settings.descriptionColumns.filter((c) => c !== Number(column.value)) : [...settings.descriptionColumns, Number(column.value)] })}
                      className={cn('rounded-md border px-2.5 py-1 text-xs', on ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent')}
                    >
                      {column.label}
                    </button>
                  )
                })}
              </div>
            </Field>
            <Field label={t('csvImport.counterpartyColumn')} optional>
              <SelectField
                value={settings.counterpartyColumn === null ? NONE : String(settings.counterpartyColumn)}
                onChange={(v) => set({ counterpartyColumn: v === NONE ? null : Number(v) })}
                options={[{ value: NONE, label: t('csvImport.none') }, ...columns]}
              />
            </Field>
            <div className="flex items-end pb-2">
              <div className="flex items-center gap-2.5">
                <Checkbox id="invert" checked={settings.invert} onChange={(e) => set({ invert: e.target.checked })} />
                <Label htmlFor="invert" className="font-normal">{t('csvImport.invert')}</Label>
              </div>
            </div>
          </FormSection>

          <FormSection title={t('csvImport.sample')} aside={<span className="text-sm font-normal text-muted-foreground">{t('csvImport.readCount', { valid: valid.length, total: parsed.length })}</span>}>
            <div className="divide-y text-sm">
              {parsed.slice(0, 6).map((row) => (
                <div key={row.line} className="flex items-center gap-3 py-2">
                  <span className={cn('w-24 shrink-0 tabular-nums', row.date === null && 'text-destructive')}>{row.date ? formatShortDate(row.date) : t('csvImport.noDate')}</span>
                  <span className="min-w-0 flex-1 truncate">{row.description || <span className="text-muted-foreground">—</span>}</span>
                  <span className={cn('shrink-0 tabular-nums', row.amount === null ? 'text-destructive' : row.amount.startsWith('-') ? 'text-negative' : 'text-positive')}>
                    {row.amount !== null ? formatMoney(row.amount, symbol) : t('csvImport.noAmount')}
                  </span>
                </div>
              ))}
            </div>
            <div className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
              <div className="flex items-center gap-2.5">
                <Checkbox id="savePreset" checked={savePreset} onChange={(e) => setSavePreset(e.target.checked)} />
                <Label htmlFor="savePreset" className="font-normal">{t(preset ? 'csvImport.updatePreset' : 'csvImport.savePreset')}</Label>
              </div>
              {savePreset && <Input aria-label={t('csvImport.presetName')} value={presetName} maxLength={100} onChange={(e) => setPresetName(e.target.value)} />}
            </div>
          </FormSection>

          <FormActions>
            <Button variant="outline" onClick={() => setStep('file')}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t('csvImport.otherFile')}
            </Button>
            <Button disabled={!accountId || valid.length === 0 || busy} onClick={() => void check()}>
              {t('csvImport.toPreview', { count: valid.length })}
              <ArrowRight className="ml-2 h-4 w-4" />
            </Button>
          </FormActions>
        </>
      )}

      {step === 'preview' && (
        <>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted-foreground">
            <span>{t('csvImport.selected', { count: included.length, total: rows.length })}</span>
            <button type="button" className="text-primary hover:text-primary/80" onClick={() => setRows((r) => r.map((row) => ({ ...row, include: true })))}>{t('csvImport.all')}</button>
            <button type="button" className="text-primary hover:text-primary/80" onClick={() => setRows((r) => r.map((row) => ({ ...row, include: row.check.duplicate === null })))}>{t('csvImport.allButDuplicates')}</button>
          </div>
          <div className="surface divide-y">
            {rows.map((row, i) => {
              const out = row.row.amount.startsWith('-')
              const parts = rows.filter((other) => other.row.line === row.row.line)
              const changed = parts.length > 1 || Number(row.amount) !== Number(abs(row.row.amount))
              const amountField = (
                <div className="space-y-0.5">
                  <div className="flex items-center gap-1">
                    <div className="relative min-w-0 flex-1">
                      <Input
                        type="number"
                        inputMode="decimal"
                        step="0.01"
                        min="0.01"
                        aria-label={t('transactionForm.amount')}
                        className={cn('h-9 pr-6 text-right tabular-nums', out ? 'text-negative' : 'text-positive')}
                        value={row.amount}
                        onChange={(e) => update(i, { amount: e.target.value })}
                      />
                      <span className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-muted-foreground">{symbol}</span>
                    </div>
                    {row.part === 0 ? (
                      <Button variant="ghost" size="icon" className="h-9 w-8 shrink-0" aria-label={t('csvImport.split')} title={t('csvImport.split')} onClick={() => split(row.row.line)}>
                        <Scissors className="h-4 w-4" />
                      </Button>
                    ) : (
                      <Button variant="ghost" size="icon" className="h-9 w-8 shrink-0" aria-label={t('csvImport.removePart')} title={t('csvImport.removePart')} onClick={() => setRows((current) => current.filter((other) => other.key !== row.key))}>
                        <X className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                  {row.part === 0 && changed && (
                    <div className="text-right text-xs text-muted-foreground tabular-nums">
                      {t('csvImport.ofFile', { assigned: formatMoney(assignedTo(row.row.line), symbol), total: formatMoney(abs(row.row.amount), symbol) })}
                    </div>
                  )}
                </div>
              )
              return (
                <div
                  key={row.key}
                  className={cn(
                    'grid gap-2 px-4 py-3 lg:grid-cols-[auto_6.5rem_minmax(0,1fr)_9rem_13rem_11rem] lg:items-start lg:gap-3',
                    row.part > 0 && 'border-t-0 bg-muted/30',
                    !row.include && 'opacity-50'
                  )}
                >
                  <div className="flex items-center gap-3 lg:contents">
                    <Checkbox aria-label={t('csvImport.include')} className="lg:mt-2.5" checked={row.include} onChange={(e) => update(i, { include: e.target.checked })} />
                    <span className="text-sm tabular-nums text-muted-foreground lg:pt-2">{row.part === 0 ? formatShortDate(row.row.date) : t('csvImport.part', { number: row.part + 1 })}</span>
                    <div className="ml-auto w-36 lg:hidden">{amountField}</div>
                  </div>
                  <div className="min-w-0 space-y-1">
                    <Input value={row.description} onChange={(e) => update(i, { description: e.target.value })} className="h-9" />
                    {(row.check.duplicate || row.check.recurring) && (
                      <div className="flex flex-wrap gap-1.5">
                        {row.check.duplicate && (
                          <Badge variant="warning" className="font-normal">
                            {t('csvImport.duplicate', { description: row.check.duplicate.description ?? '—', date: formatShortDate(row.check.duplicate.occurredAt.slice(0, 10)) })}
                          </Badge>
                        )}
                        {row.check.recurring && <Badge variant="info" className="font-normal">{t('csvImport.recurring', { description: row.check.recurring.description })}</Badge>}
                      </div>
                    )}
                  </div>
                  <div className="hidden lg:block">{amountField}</div>
                  <Combobox
                    options={otherOptions(out, row.otherId)}
                    value={row.otherId}
                    onChange={(otherId) => update(i, { otherId })}
                    onCreate={(name) => update(i, { otherId: `${NEW_PREFIX}${name}` })}
                    placeholder={t(kindOf(row) === 'deposit' ? 'transactionForm.sourceRevenue' : 'transactionForm.destinationExpense')}
                    clearable
                  />
                  <Combobox
                    options={categoryOptions(row.categoryId)}
                    value={row.categoryId}
                    onChange={(categoryId) => update(i, { categoryId })}
                    onCreate={(name) => update(i, { categoryId: `${NEW_PREFIX}${name}` })}
                    placeholder={t('transactionForm.category')}
                    clearable
                  />
                </div>
              )
            })}
          </div>
          <FormActions>
            <Button variant="outline" onClick={() => setStep('columns')}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t('csvImport.backToColumns')}
            </Button>
            <Button disabled={included.length === 0 || busy || included.some((row) => !row.description.trim() || !(Number(row.amount) > 0))} onClick={() => void commit()}>
              <Upload className="mr-2 h-4 w-4" />
              {t('csvImport.import', { count: included.length })}
            </Button>
          </FormActions>
        </>
      )}
    </div>
  )
}

function Steps({ step }: { step: Step }) {
  const { t } = useTranslation()
  const steps: Step[] = ['file', 'columns', 'preview']
  const current = steps.indexOf(step)
  return (
    <ol className="flex items-center gap-2 text-sm">
      {steps.map((name, i) => (
        <li key={name} className="flex items-center gap-2">
          <span
            className={cn(
              'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
              i <= current ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
            )}
          >
            {i + 1}
          </span>
          <span className={cn(i === current ? 'font-medium' : 'text-muted-foreground', i !== current && 'max-sm:hidden')}>{t(`csvImport.step_${name}`)}</span>
          {i < steps.length - 1 && <span className="mx-1 h-px w-6 bg-border" aria-hidden />}
        </li>
      ))}
    </ol>
  )
}

function DropZone({ onFile }: { onFile: (file: File | undefined) => void }) {
  const { t } = useTranslation()
  const [over, setOver] = useState(false)
  const drop = (event: DragEvent) => {
    event.preventDefault()
    setOver(false)
    onFile(event.dataTransfer.files[0])
  }
  return (
    <label
      onDragOver={(event) => {
        event.preventDefault()
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={drop}
      className={cn(
        'surface flex cursor-pointer flex-col items-center gap-3 border-dashed px-6 py-14 text-center transition-colors',
        over ? 'border-primary bg-primary/5' : 'hover:bg-accent/40'
      )}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
        <FileUp className="h-6 w-6" />
      </span>
      <span className="font-medium">{t('csvImport.drop')}</span>
      <span className="max-w-md text-sm text-muted-foreground">{t('csvImport.dropHint')}</span>
      <input type="file" accept=".csv,.txt,text/csv" className="sr-only" onChange={(event) => onFile(event.target.files?.[0])} />
    </label>
  )
}

function SelectField({ value, onChange, options }: { value: string; onChange: (value: string) => void; options: { value: string; label: string }[] }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
