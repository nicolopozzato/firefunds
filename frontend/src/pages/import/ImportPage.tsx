import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, ArrowRight, Upload } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import FormActions from '@/components/FormActions'
import { Button } from '@/components/ui/button'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useAllClassification } from '@/hooks/use-classification'
import { useAuth } from '@/contexts/AuthContext'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { decode, detectDelimiter, guessSettings, headerKey, parse, toRows, withDefaults, type CsvEncoding, type CsvSettings } from '@/lib/csv'
import { isSpreadsheet, readWorkbook, type Workbook } from '@/lib/excel'
import { isFireflyExport, readFirefly } from '@/lib/firefly'
import { importApi, type ImportBatch, type ImportPreset } from '@/lib/import-api'
import { collectNames, itemsFromRows, pairTransferLegs, type ImportItem } from '@/lib/import-items'
import {
  KEY_PREFIX,
  accountKeys,
  commitBody,
  defaultAccountTarget,
  ignoredOpenings,
  reviewRows,
  rowProblem,
  withChecks,
  type Lists,
  type Mapping,
  type ReviewRow,
} from '@/lib/import-plan'
import { cn, currencySymbolFor, deviceTimezone } from '@/lib/utils'
import ColumnsStep from './ColumnsStep'
import DoneStep from './DoneStep'
import FileStep from './FileStep'
import MatchStep from './MatchStep'
import ReviewStep from './ReviewStep'
import { Steps, type Step } from './shared'

const EMPTY_MAPPING: Mapping = { fixedAccountId: null, accounts: {}, payees: {}, categories: {} }

/**
 * Importing a file: a bank's statement or a spreadsheet of several accounts
 * (CSV or Excel, read in the browser; a bank recognised from its header uses
 * its preset), or a Firefly III export, recognised by itself. Its names are
 * matched once (accounts, payees, categories), the server checks the rows
 * (duplicates, recurring transactions, what was recorded before), the user
 * corrects them, and they are recorded as one import, which can be undone.
 */
export default function ImportPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [step, setStep] = useState<Step>('file')
  const [fileName, setFileName] = useState('')
  const [buffer, setBuffer] = useState<ArrayBuffer | null>(null)
  const [workbook, setWorkbook] = useState<Workbook | null>(null)
  const [sheet, setSheet] = useState('')
  const [table, setTable] = useState<string[][]>([])
  const [settings, setSettings] = useState<CsvSettings | null>(null)
  const [firefly, setFirefly] = useState<{ skipped: Record<string, number> } | null>(null)
  const [preset, setPreset] = useState<ImportPreset | null>(null)
  const [savePreset, setSavePreset] = useState(true)
  const [presetName, setPresetName] = useState('')
  const [items, setItems] = useState<ImportItem[]>([])
  const [paired, setPaired] = useState(0)
  const [mapping, setMapping] = useState<Mapping>(EMPTY_MAPPING)
  const [rows, setRows] = useState<ReviewRow[]>([])
  const [batch, setBatch] = useState<ImportBatch | null>(null)
  const [busy, setBusy] = useState(false)

  const { data: presets } = useQuery({ queryKey: ['import-presets'], queryFn: () => importApi.presets() })
  const { data: assets } = useAllAccounts('asset')
  const { data: expenses } = useAllAccounts('expense')
  const { data: revenues } = useAllAccounts('revenue')
  const { data: categories } = useAllClassification('categories')
  const lists: Lists = useMemo(
    () => ({ assets: assets?.items ?? [], expenses: expenses?.items ?? [], revenues: revenues?.items ?? [], categories: categories?.items ?? [] }),
    [assets, expenses, revenues, categories]
  )
  const timezone = user?.timezone ?? deviceTimezone()
  const userCurrency = user?.currency || 'EUR'

  const byRowPayees = firefly === null && settings?.accountMode !== 'column'
  const names = useMemo(() => collectNames(items, byRowPayees), [items, byRowPayees])
  const keys = useMemo(() => accountKeys(names.accounts), [names.accounts])
  const newAccounts = names.accounts.flatMap((account) => {
    const target = mapping.accounts[account.name.trim().toLocaleLowerCase()]
    return target?.kind === 'new' ? [{ key: keys[account.name.trim().toLocaleLowerCase()], name: target.name, currency: target.currency }] : []
  })

  /** A table (a CSV file in an encoding, or a sheet): Firefly's export, a preset's bank, else a guess. */
  const readTable = (parsed: string[][], encoding: CsvEncoding, delimiter: string) => {
    setTable(parsed)
    if (isFireflyExport(parsed[0] ?? [])) {
      const read = readFirefly(parsed)
      setFirefly({ skipped: read.skipped })
      setSettings(null)
      startMatch(read.items, 0, null)
      return
    }
    setFirefly(null)
    const known = (presets ?? []).find((candidate) => headerKey(parsed[candidate.settings.skipRows] ?? []) === headerKey(candidate.headers))
    if (known) {
      setPreset(known)
      setPresetName(known.name)
      setSettings(withDefaults({ ...known.settings, encoding }))
      if (known.accountId) setMapping({ ...EMPTY_MAPPING, fixedAccountId: known.accountId })
    } else {
      setPreset(null)
      setSettings(guessSettings(parsed, encoding, delimiter))
    }
    setStep('columns')
  }

  const readText = (data: ArrayBuffer, encoding?: CsvEncoding) => {
    let used: CsvEncoding = encoding ?? 'utf-8'
    let text = decode(data, used)
    if (!encoding && text.includes('�')) {
      used = 'windows-1252'
      text = decode(data, used)
    }
    const delimiter = settings && encoding ? settings.delimiter : detectDelimiter(text)
    readTable(parse(text, delimiter), used, delimiter)
  }

  const open = async (file: File | undefined) => {
    if (!file) return
    const data = await file.arrayBuffer()
    setFileName(file.name)
    setBuffer(data)
    setPresetName(file.name.replace(/\.[^.]+$/, ''))
    setMapping(EMPTY_MAPPING)
    try {
      if (isSpreadsheet(file.name)) {
        const book = await readWorkbook(data)
        setWorkbook(book)
        setSheet(book.sheets[0] ?? '')
        readTable(book.table(book.sheets[0] ?? ''), 'utf-8', ',')
      } else {
        setWorkbook(null)
        readText(data)
      }
    } catch {
      toast.error(t('csvImport.unreadable'))
    }
  }

  const parsed = useMemo(() => (settings ? toRows(table, settings) : []), [table, settings])
  const validCount = useMemo(() => itemsFromRows(parsed).length, [parsed])

  /** The items read, with the user's names matched as a first guess: same name, else new. */
  const startMatch = (read: ImportItem[], legs: number, fixedAccountId: string | null) => {
    setItems(read)
    setPaired(legs)
    const found = collectNames(read, false)
    setMapping((current) => ({
      ...current,
      fixedAccountId,
      accounts: Object.fromEntries(found.accounts.map((account) => [account.name.trim().toLocaleLowerCase(), defaultAccountTarget(account, lists, userCurrency)])),
    }))
    setStep('match')
  }

  const fromColumns = async () => {
    if (!settings) return
    const fixed = settings.accountMode === 'fixed'
    if (savePreset && presetName.trim()) {
      try {
        const saved = await importApi.savePreset({ name: presetName.trim(), accountId: fixed ? mapping.fixedAccountId : null, settings, headers: table[settings.skipRows] ?? [] }, preset?.id)
        setPreset(saved)
        void queryClient.invalidateQueries({ queryKey: ['import-presets'] })
      } catch (error) {
        toast.error(handleApiError(error))
        return
      }
    }
    const read = itemsFromRows(parsed)
    if (fixed) {
      const found = collectNames(read, true)
      if (found.categories.length === 0) {
        setItems(read)
        setPaired(0)
        await toReview(read, { ...mapping, accounts: {} })
        return
      }
      startMatch(read, 0, mapping.fixedAccountId)
      return
    }
    const ownNames = new Set([...read.flatMap((item) => (item.account ? [item.account.trim().toLocaleLowerCase()] : [])), ...lists.assets.map((asset) => asset.name.trim().toLocaleLowerCase())])
    const legs = pairTransferLegs(read, ownNames)
    startMatch(legs.items, legs.paired, null)
  }

  /** The rows, checked by the server: duplicates, recurring transactions, what was recorded before. */
  const toReview = async (source: ImportItem[] = items, current: Mapping = mapping) => {
    setBusy(true)
    try {
      const built = reviewRows(source, current, lists, keys)
      if (built.length === 0) {
        setRows([])
        setStep('review')
        return
      }
      const checks = await importApi.preview(
        built.map((row) => ({
          date: row.item.date,
          amount: row.item.amount,
          ...(row.own !== '' && !row.own.startsWith(KEY_PREFIX) ? { accountId: row.own } : {}),
          description: row.item.description,
          counterparty: row.item.counterparty,
        }))
      )
      setRows(withChecks(built, checks))
      setStep('review')
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const assetIds = new Set(lists.assets.map((asset) => asset.id))
  const included = rows.filter((row) => row.include)
  const problems = included.filter((row) => rowProblem(row) !== null).length
  const adjustments = items.filter((item) => item.kind === 'adjustment').length

  const commit = async () => {
    setBusy(true)
    try {
      const result = await importApi.commit(commitBody(fileName, rows, items, names.accounts, mapping, keys, assetIds, timezone))
      void dataChanged(queryClient)
      void queryClient.invalidateQueries({ queryKey: ['import-batches'] })
      setBatch(result)
      setStep('done')
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const reset = () => {
    setStep('file')
    setItems([])
    setRows([])
    setBatch(null)
    setFirefly(null)
  }

  const symbolFor = (own: string) => {
    const currency = own.startsWith(KEY_PREFIX)
      ? newAccounts.find((account) => account.key === own.slice(KEY_PREFIX.length))?.currency
      : lists.assets.find((asset) => asset.id === own)?.currencyCode
    return currencySymbolFor(currency ?? userCurrency)
  }

  const hasMatch = names.accounts.length > 0 || names.payees.length > 0 || names.categories.length > 0
  const steps: Step[] = ['file', ...(firefly ? [] : (['columns'] as Step[])), ...(hasMatch || step === 'match' ? (['match'] as Step[]) : []), 'review', 'done']

  const matchNotes = [
    firefly && t('csvImport.fireflyRecognised', { count: items.filter((item) => item.kind === 'transaction').length }),
    ...Object.entries(firefly?.skipped ?? {}).map(([type, count]) => t('csvImport.fireflySkipped', { count, type })),
    paired > 0 && t('csvImport.pairedLegs', { count: paired }),
    adjustments > 0 && t('csvImport.adjustmentsNote', { count: adjustments }),
    ignoredOpenings(names.accounts, mapping) > 0 && t('csvImport.ignoredOpenings', { count: ignoredOpenings(names.accounts, mapping) }),
  ].filter((note): note is string => typeof note === 'string')

  return (
    <div className={cn('space-y-6', step === 'review' ? 'max-w-6xl' : step === 'match' ? 'max-w-5xl' : 'max-w-3xl')}>
      <PageHeader title={t('csvImport.title')} description={t('csvImport.description')} />
      {step !== 'file' && <Steps steps={steps} step={step} />}

      {step === 'file' && <FileStep onFile={(file) => void open(file)} />}

      {step === 'columns' && settings && (
        <>
          <ColumnsStep
            fileName={fileName}
            sheets={workbook?.sheets ?? []}
            sheet={sheet}
            onSheet={(name) => {
              if (!workbook) return
              setSheet(name)
              readTable(workbook.table(name), 'utf-8', ',')
            }}
            onEncoding={workbook ? null : (encoding) => buffer && readText(buffer, encoding)}
            table={table}
            settings={settings}
            onSettings={setSettings}
            parsed={parsed}
            validCount={validCount}
            preset={preset}
            accounts={lists.assets}
            accountId={mapping.fixedAccountId ?? ''}
            onAccount={(id) => setMapping({ ...mapping, fixedAccountId: id || null })}
            savePreset={savePreset}
            onSavePreset={setSavePreset}
            presetName={presetName}
            onPresetName={setPresetName}
            symbol={currencySymbolFor(lists.assets.find((asset) => asset.id === mapping.fixedAccountId)?.currencyCode ?? userCurrency)}
          />
          <FormActions>
            <Button variant="outline" onClick={reset}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t('csvImport.otherFile')}
            </Button>
            <Button disabled={(settings.accountMode === 'fixed' && !mapping.fixedAccountId) || validCount === 0 || busy} onClick={() => void fromColumns()}>
              {t('csvImport.toNext', { count: validCount })}
              <ArrowRight className="ml-2 h-4 w-4" />
            </Button>
          </FormActions>
        </>
      )}

      {step === 'match' && (
        <>
          <MatchStep accounts={names.accounts} payees={names.payees} categories={names.categories} mapping={mapping} onMapping={setMapping} lists={lists} notes={matchNotes} />
          <FormActions>
            <Button variant="outline" onClick={() => (firefly ? reset() : setStep('columns'))}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t(firefly ? 'csvImport.otherFile' : 'csvImport.backToColumns')}
            </Button>
            <Button disabled={busy || newAccounts.some((account) => !account.currency)} onClick={() => void toReview()}>
              {t('csvImport.toReview')}
              <ArrowRight className="ml-2 h-4 w-4" />
            </Button>
          </FormActions>
        </>
      )}

      {step === 'review' && (
        <>
          <ReviewStep rows={rows} onRows={setRows} lists={lists} newAccounts={newAccounts} showOwn={!byRowPayees || firefly !== null} symbolFor={symbolFor} />
          <FormActions>
            <Button variant="outline" onClick={() => setStep(steps.includes('match') ? 'match' : 'columns')}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              {t(steps.includes('match') ? 'csvImport.backToMatch' : 'csvImport.backToColumns')}
            </Button>
            <Button disabled={(included.length === 0 && adjustments === 0 && newAccounts.length === 0) || problems > 0 || busy} onClick={() => void commit()}>
              <Upload className="mr-2 h-4 w-4" />
              {problems > 0 ? t('csvImport.toFix', { count: problems }) : t('csvImport.import', { count: included.length })}
            </Button>
          </FormActions>
        </>
      )}

      {step === 'done' && batch && <DoneStep batch={batch} onAnother={reset} />}
    </div>
  )
}
