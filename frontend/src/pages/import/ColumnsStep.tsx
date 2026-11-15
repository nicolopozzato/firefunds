import { useTranslation } from 'react-i18next'
import { Landmark, Table2 } from 'lucide-react'
import ChoiceCards from '@/components/ChoiceCards'
import Combobox from '@/components/Combobox'
import Field from '@/components/Field'
import FormSection from '@/components/FormSection'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DATE_FORMATS, guessSettings, type CsvEncoding, type CsvRow, type CsvSettings } from '@/lib/csv'
import type { ImportPreset } from '@/lib/import-api'
import { cn, formatMoney, formatShortDate } from '@/lib/utils'
import { SelectField } from './shared'

const NONE = '__none__'

interface ColumnsStepProps {
  fileName: string
  /** A spreadsheet's sheets (more than one: the user picks). */
  sheets: string[]
  sheet: string
  onSheet: (sheet: string) => void
  /** Only a CSV file has an encoding to choose. */
  onEncoding: ((encoding: CsvEncoding) => void) | null
  table: string[][]
  settings: CsvSettings
  onSettings: (settings: CsvSettings) => void
  parsed: CsvRow[]
  validCount: number
  preset: ImportPreset | null
  accounts: { id: string; name: string }[]
  accountId: string
  onAccount: (id: string) => void
  savePreset: boolean
  onSavePreset: (save: boolean) => void
  presetName: string
  onPresetName: (name: string) => void
  symbol: string
}

/** How the file reads: one account or several, which column is what, as the first lines show it. */
export default function ColumnsStep(props: ColumnsStepProps) {
  const { t } = useTranslation()
  const { settings, table } = props
  const set = (patch: Partial<CsvSettings>) => props.onSettings({ ...settings, ...patch })
  const header = table[settings.skipRows] ?? []
  const columns = header.map((name, i) => ({ value: String(i), label: `${columnLetter(i)} · ${name.trim() || '—'}` }))
  const optional = (value: number | null, onChange: (column: number | null) => void, label: string) => (
    <Field label={label} optional>
      <SelectField
        label={label}
        value={value === null ? NONE : String(value)}
        onChange={(v) => onChange(v === NONE ? null : Number(v))}
        options={[{ value: NONE, label: t('csvImport.none') }, ...columns]}
      />
    </Field>
  )

  return (
    <>
      <FormSection
        title={props.fileName}
        aside={props.preset && <Badge variant="info" className="font-normal">{t('csvImport.recognised', { name: props.preset.name })}</Badge>}
        contentClassName="space-y-5"
      >
        <ChoiceCards
          label={t('csvImport.fileKind')}
          value={settings.accountMode}
          onChange={(accountMode) => set({ accountMode, accountColumn: settings.accountColumn ?? 0 })}
          options={[
            { value: 'fixed', label: t('csvImport.kindStatement'), description: t('csvImport.kindStatementHint'), icon: Landmark },
            { value: 'column', label: t('csvImport.kindLedger'), description: t('csvImport.kindLedgerHint'), icon: Table2 },
          ]}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          {settings.accountMode === 'fixed' ? (
            <Field label={t('csvImport.account')} className="sm:col-span-2">
              <Combobox
                options={props.accounts.map((item) => ({ value: item.id, label: item.name }))}
                value={props.accountId}
                onChange={props.onAccount}
                placeholder={t('transactionForm.selectAccount')}
              />
            </Field>
          ) : (
            <Field label={t('csvImport.accountColumn')} hint={t('csvImport.accountColumnHint')} className="sm:col-span-2">
              <SelectField label={t('csvImport.accountColumn')} value={String(settings.accountColumn ?? 0)} onChange={(v) => set({ accountColumn: Number(v) })} options={columns} />
            </Field>
          )}
          {props.sheets.length > 1 && (
            <Field label={t('csvImport.sheet')}>
              <SelectField label={t('csvImport.sheet')} value={props.sheet} onChange={props.onSheet} options={props.sheets.map((name) => ({ value: name, label: name }))} />
            </Field>
          )}
          {props.onEncoding && (
            <Field label={t('csvImport.encoding')} hint={t('csvImport.encodingHint')}>
              <SelectField
                label={t('csvImport.encoding')}
                value={settings.encoding}
                onChange={(encoding) => props.onEncoding?.(encoding as CsvEncoding)}
                options={[
                  { value: 'utf-8', label: 'UTF-8' },
                  { value: 'windows-1252', label: 'Windows (Latin-1)' },
                ]}
              />
            </Field>
          )}
          <Field label={t('csvImport.skipRows')} hint={t('csvImport.skipRowsHint')}>
            <Input
              type="number"
              min="0"
              max="50"
              value={settings.skipRows}
              // Another header: the columns are guessed again from it.
              onChange={(e) => props.onSettings(guessSettings(table, settings.encoding, settings.delimiter, Math.max(0, Number(e.target.value) || 0)))}
            />
          </Field>
        </div>
      </FormSection>

      <FormSection title={t('csvImport.columns')} contentClassName="grid gap-4 sm:grid-cols-2">
        <Field label={t('csvImport.dateColumn')}>
          <SelectField label={t('csvImport.dateColumn')} value={String(settings.dateColumn)} onChange={(v) => set({ dateColumn: Number(v) })} options={columns} />
        </Field>
        <Field label={t('csvImport.dateFormat')}>
          <SelectField
            label={t('csvImport.dateFormat')}
            value={settings.dateFormat}
            onChange={(v) => set({ dateFormat: v as CsvSettings['dateFormat'] })}
            options={DATE_FORMATS.map((format) => ({ value: format, label: format }))}
          />
        </Field>
        <Field label={t('csvImport.amountMode')}>
          <SelectField
            label={t('csvImport.amountMode')}
            value={settings.amountMode}
            onChange={(v) => set({ amountMode: v as CsvSettings['amountMode'] })}
            options={[
              { value: 'single', label: t('csvImport.amountSingle') },
              { value: 'split', label: t('csvImport.amountSplit') },
            ]}
          />
        </Field>
        <Field label={t('csvImport.decimal')}>
          <SelectField
            label={t('csvImport.decimal')}
            value={settings.decimal}
            onChange={(v) => set({ decimal: v as CsvSettings['decimal'] })}
            options={[
              { value: ',', label: '1.234,56' },
              { value: '.', label: '1,234.56' },
            ]}
          />
        </Field>
        {settings.amountMode === 'single' ? (
          <Field label={t('csvImport.amountColumn')}>
            <SelectField label={t('csvImport.amountColumn')} value={String(settings.amountColumn)} onChange={(v) => set({ amountColumn: Number(v) })} options={columns} />
          </Field>
        ) : (
          <>
            <Field label={t('csvImport.debitColumn')}>
              <SelectField label={t('csvImport.debitColumn')} value={String(settings.debitColumn)} onChange={(v) => set({ debitColumn: Number(v) })} options={columns} />
            </Field>
            <Field label={t('csvImport.creditColumn')}>
              <SelectField label={t('csvImport.creditColumn')} value={String(settings.creditColumn)} onChange={(v) => set({ creditColumn: Number(v) })} options={columns} />
            </Field>
          </>
        )}
        <div className="flex items-end pb-2">
          <div className="flex items-center gap-2.5">
            <Checkbox id="invert" checked={settings.invert} onChange={(e) => set({ invert: e.target.checked })} />
            <Label htmlFor="invert" className="font-normal">{t('csvImport.invert')}</Label>
          </div>
        </div>
        <Field label={t('csvImport.descriptionColumns')} hint={t('csvImport.descriptionColumnsHint')} className="sm:col-span-2">
          <div className="flex flex-wrap gap-1.5">
            {columns.map((column) => {
              const on = settings.descriptionColumns.includes(Number(column.value))
              return (
                <button
                  key={column.value}
                  type="button"
                  aria-pressed={on}
                  onClick={() =>
                    set({ descriptionColumns: on ? settings.descriptionColumns.filter((c) => c !== Number(column.value)) : [...settings.descriptionColumns, Number(column.value)] })
                  }
                  className={cn('rounded-md border px-2.5 py-1 text-xs', on ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent')}
                >
                  {column.label}
                </button>
              )
            })}
          </div>
        </Field>
        {optional(settings.counterpartyColumn, (counterpartyColumn) => set({ counterpartyColumn }), t('csvImport.counterpartyColumn'))}
        {optional(settings.categoryColumn, (categoryColumn) => set({ categoryColumn }), t('csvImport.categoryColumn'))}
        {optional(settings.tagsColumn, (tagsColumn) => set({ tagsColumn }), t('csvImport.tagsColumn'))}
        {optional(settings.notesColumn, (notesColumn) => set({ notesColumn }), t('csvImport.notesColumn'))}
      </FormSection>

      <FormSection
        title={t('csvImport.sample')}
        aside={<span className="text-sm font-normal text-muted-foreground">{t('csvImport.readCount', { valid: props.validCount, total: props.parsed.length })}</span>}
      >
        <div className="divide-y text-sm">
          {props.parsed.slice(0, 6).map((row) => (
            <div key={row.line} className="flex items-center gap-3 py-2">
              <span className={cn('w-24 shrink-0 tabular-nums', row.date === null && 'text-destructive')}>{row.date ? formatShortDate(row.date) : t('csvImport.noDate')}</span>
              <span className="min-w-0 flex-1 truncate">
                {settings.accountMode === 'column' && <span className="text-muted-foreground">{row.account || '?'} · </span>}
                {row.description || <span className="text-muted-foreground">—</span>}
                {row.category && <span className="text-muted-foreground"> · {row.category}</span>}
              </span>
              <span className={cn('shrink-0 tabular-nums', row.amount === null ? 'text-destructive' : row.amount.startsWith('-') ? 'text-negative' : 'text-positive')}>
                {row.amount !== null ? formatMoney(row.amount, props.symbol) : t('csvImport.noAmount')}
              </span>
            </div>
          ))}
        </div>
        <div className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
          <div className="flex items-center gap-2.5">
            <Checkbox id="savePreset" checked={props.savePreset} onChange={(e) => props.onSavePreset(e.target.checked)} />
            <Label htmlFor="savePreset" className="font-normal">{t(props.preset ? 'csvImport.updatePreset' : 'csvImport.savePreset')}</Label>
          </div>
          {props.savePreset && <Input aria-label={t('csvImport.presetName')} value={props.presetName} maxLength={100} onChange={(e) => props.onPresetName(e.target.value)} />}
        </div>
      </FormSection>
    </>
  )
}

/** A, B, … Z, AA, AB… as spreadsheets name columns. */
function columnLetter(index: number): string {
  let name = ''
  for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) name = String.fromCharCode(65 + ((n - 1) % 26)) + name
  return name
}
