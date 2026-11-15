import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Scissors, X } from 'lucide-react'
import Combobox, { type ComboboxOption } from '@/components/Combobox'
import Stepper from '@/components/Stepper'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import SegmentedControl from '@/components/ui/segmented-control'
import { KEY_PREFIX, NEW_PREFIX, rowKind, rowProblem, type Lists, type ReviewRow } from '@/lib/import-plan'
import { cn, formatMoney, formatShortDate } from '@/lib/utils'
import { SelectField } from './shared'

const PAGE = 50

type Filter = 'all' | 'check' | 'excluded'
type KindFilter = 'any' | 'withdrawal' | 'deposit' | 'transfer'

interface ReviewStepProps {
  rows: ReviewRow[]
  onRows: (update: (rows: ReviewRow[]) => ReviewRow[]) => void
  lists: Lists
  /** The accounts the import creates, by key. */
  newAccounts: { key: string; name: string }[]
  /** A file of several accounts shows each row's own account. */
  showOwn: boolean
  symbolFor: (own: string) => string
}

const abs = (amount: string) => amount.replace(/^-/, '')

/**
 * The rows the file becomes, a page at a time: what each will be, the ones
 * to look at (already recorded, recurring, something missing), each one's
 * other side, category and amount to correct, a line to split.
 */
export default function ReviewStep({ rows, onRows, lists, newAccounts, showOwn, symbolFor }: ReviewStepProps) {
  const { t } = useTranslation()
  const [filter, setFilter] = useState<Filter>('all')
  const [kind, setKind] = useState<KindFilter>('any')
  const [page, setPage] = useState(0)
  const assetIds = new Set(lists.assets.map((asset) => asset.id))
  const needsLook = (row: ReviewRow) => row.check.duplicate !== null || row.check.recurring !== null || (row.include && rowProblem(row) !== null)

  const included = rows.filter((row) => row.include)
  const counts = { withdrawal: 0, deposit: 0, transfer: 0 }
  for (const row of included) counts[rowKind(row, assetIds)]++
  const toLook = rows.filter(needsLook).length

  const visible = rows
    .map((row, index) => ({ row, index }))
    .filter(({ row }) => (filter === 'all' ? true : filter === 'check' ? needsLook(row) : !row.include) && (kind === 'any' || rowKind(row, assetIds) === kind))
  const pages = Math.max(1, Math.ceil(visible.length / PAGE))
  const current = Math.min(page, pages - 1)

  const update = (index: number, patch: Partial<ReviewRow>) => onRows((list) => list.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  const assignedTo = (line: number) => rows.filter((row) => row.item.line === line).reduce((sum, row) => sum + (Number(row.amount) || 0), 0)

  /** One more transaction from the same line, after its last part, offered what is left. */
  const split = (line: number) =>
    onRows((list) => {
      const parts = list.filter((row) => row.item.line === line)
      const first = parts[0]
      const rest = Math.max(0, Math.round((Number(abs(first.item.amount)) - parts.reduce((sum, row) => sum + (Number(row.amount) || 0), 0)) * 100) / 100)
      const part: ReviewRow = {
        ...first,
        key: `${line}-${parts.length}`,
        part: Math.max(...parts.map((row) => row.part)) + 1,
        check: { index: first.check.index, duplicate: null, recurring: null, suggestion: null },
        include: true,
        amount: rest > 0 ? String(rest) : '',
        description: '',
        other: '',
        category: '',
        tags: [],
        notes: '',
      }
      const at = list.indexOf(parts[parts.length - 1]) + 1
      return [...list.slice(0, at), part, ...list.slice(at)]
    })

  const ownName = (own: string) =>
    own.startsWith(KEY_PREFIX) ? (newAccounts.find((account) => account.key === own.slice(KEY_PREFIX.length))?.name ?? '?') : (lists.assets.find((asset) => asset.id === own)?.name ?? '?')

  const otherOptions = (row: ReviewRow): ComboboxOption[] => {
    const out = row.item.amount.startsWith('-')
    const options: ComboboxOption[] = [
      ...(out ? lists.expenses : lists.revenues).map((item) => ({ value: item.id, label: item.name })),
      ...lists.assets.filter((item) => item.id !== row.own).map((item) => ({ value: item.id, label: item.name, sublabel: t('csvImport.transfer') })),
      ...newAccounts
        .filter((account) => `${KEY_PREFIX}${account.key}` !== row.own)
        .map((account) => ({ value: `${KEY_PREFIX}${account.key}`, label: account.name, sublabel: t('csvImport.transfer') })),
    ]
    if (row.other.startsWith(NEW_PREFIX)) options.push({ value: row.other, label: t('transactionForm.pendingNew', { name: row.other.slice(NEW_PREFIX.length) }) })
    return options
  }
  const categoryOptions = (selected: string): ComboboxOption[] => [
    ...lists.categories.map((category) => ({ value: category.id, label: category.name })),
    ...(selected.startsWith(NEW_PREFIX) ? [{ value: selected, label: t('transactionForm.pendingNew', { name: selected.slice(NEW_PREFIX.length) }) }] : []),
  ]

  return (
    <>
      <div className="surface flex flex-wrap items-center gap-x-5 gap-y-1.5 px-4 py-3 text-sm">
        <span className="font-medium">{t('csvImport.selected', { count: included.length, total: rows.length })}</span>
        <span className="text-negative">{t('csvImport.countWithdrawals', { count: counts.withdrawal })}</span>
        <span className="text-positive">{t('csvImport.countDeposits', { count: counts.deposit })}</span>
        <span className="text-info">{t('csvImport.countTransfers', { count: counts.transfer })}</span>
        <span className="ml-auto flex gap-3">
          <button type="button" className="text-primary hover:text-primary/80" onClick={() => onRows((list) => list.map((row) => ({ ...row, include: true })))}>
            {t('csvImport.all')}
          </button>
          <button type="button" className="text-primary hover:text-primary/80" onClick={() => onRows((list) => list.map((row) => ({ ...row, include: row.check.duplicate === null })))}>
            {t('csvImport.allButDuplicates')}
          </button>
        </span>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <SegmentedControl<Filter>
          label={t('csvImport.filter')}
          value={filter}
          onChange={(value) => {
            setFilter(value)
            setPage(0)
          }}
          options={[
            { value: 'all', label: t('csvImport.filterAll') },
            { value: 'check', label: t('csvImport.filterCheck', { count: toLook }) },
            { value: 'excluded', label: t('csvImport.filterExcluded', { count: rows.length - included.length }) },
          ]}
        />
        <div className="w-44">
          <SelectField
            label={t('csvImport.kindFilter')}
            value={kind}
            onChange={(value) => {
              setKind(value as KindFilter)
              setPage(0)
            }}
            options={[
              { value: 'any', label: t('csvImport.kindAny') },
              { value: 'withdrawal', label: t('transactionsCommon.kindOne_withdrawal') },
              { value: 'deposit', label: t('transactionsCommon.kindOne_deposit') },
              { value: 'transfer', label: t('transactionsCommon.kindOne_transfer') },
            ]}
          />
        </div>
        {pages > 1 && (
          <Stepper
            className="ml-auto"
            onPrevious={() => setPage(current - 1)}
            onNext={() => setPage(current + 1)}
            previousDisabled={current === 0}
            nextDisabled={current >= pages - 1}
            previousLabel={t('csvImport.previousPage')}
            nextLabel={t('csvImport.nextPage')}
          >
            {t('csvImport.page', { page: current + 1, pages })}
          </Stepper>
        )}
      </div>

      {visible.length === 0 ? (
        <p className="surface px-4 py-8 text-center text-sm text-muted-foreground">{t('csvImport.nothingHere')}</p>
      ) : (
        <div className="surface divide-y">
          {visible.slice(current * PAGE, (current + 1) * PAGE).map(({ row, index }) => {
            const out = row.item.amount.startsWith('-')
            const symbol = symbolFor(row.own)
            const parts = rows.filter((other) => other.item.line === row.item.line)
            const changed = parts.length > 1 || Number(row.amount) !== Number(abs(row.item.amount))
            const problem = row.include ? rowProblem(row) : null
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
                      onChange={(e) => update(index, { amount: e.target.value })}
                    />
                    <span className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-muted-foreground">{symbol}</span>
                  </div>
                  {row.part === 0 ? (
                    <Button variant="ghost" size="icon" className="h-9 w-8 shrink-0" aria-label={t('csvImport.split')} title={t('csvImport.split')} onClick={() => split(row.item.line)}>
                      <Scissors className="h-4 w-4" />
                    </Button>
                  ) : (
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-9 w-8 shrink-0"
                      aria-label={t('csvImport.removePart')}
                      title={t('csvImport.removePart')}
                      onClick={() => onRows((list) => list.filter((other) => other.key !== row.key))}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  )}
                </div>
                {row.part === 0 && changed && (
                  <div className="text-right text-xs text-muted-foreground tabular-nums">
                    {t('csvImport.ofFile', { assigned: formatMoney(assignedTo(row.item.line), symbol), total: formatMoney(abs(row.item.amount), symbol) })}
                  </div>
                )}
              </div>
            )
            return (
              <div
                key={row.key}
                className={cn(
                  'grid gap-2 px-4 py-3 lg:grid-cols-[auto_7rem_minmax(0,1fr)_9rem_13rem_11rem] lg:items-start lg:gap-3',
                  row.part > 0 && 'border-t-0 bg-muted/30',
                  !row.include && 'opacity-50'
                )}
              >
                <div className="flex items-center gap-3 lg:contents">
                  <Checkbox aria-label={t('csvImport.include')} className="lg:mt-2.5" checked={row.include} onChange={(e) => update(index, { include: e.target.checked })} />
                  <div className="min-w-0 text-sm lg:pt-2">
                    <div className="tabular-nums text-muted-foreground">{row.part === 0 ? formatShortDate(row.item.date) : t('csvImport.part', { number: row.part + 1 })}</div>
                    {showOwn && row.part === 0 && <div className="cell-text text-xs text-muted-foreground">{ownName(row.own)}</div>}
                  </div>
                  <div className="ml-auto w-36 lg:hidden">{amountField}</div>
                </div>
                <div className="min-w-0 space-y-1">
                  <Input value={row.description} aria-label={t('transactionsCommon.description')} onChange={(e) => update(index, { description: e.target.value })} className="h-9" />
                  {(row.check.duplicate || row.check.recurring || problem || row.tags.length > 0) && (
                    <div className="flex flex-wrap gap-1.5">
                      {problem && <Badge variant="destructive" className="font-normal">{t(`csvImport.problem_${problem}`)}</Badge>}
                      {row.check.duplicate && (
                        <Badge variant="warning" className="font-normal">
                          {t('csvImport.duplicate', { description: row.check.duplicate.description ?? '—', date: formatShortDate(row.check.duplicate.occurredAt.slice(0, 10)) })}
                        </Badge>
                      )}
                      {row.check.recurring && <Badge variant="info" className="font-normal">{t('csvImport.recurring', { description: row.check.recurring.description })}</Badge>}
                      {row.tags.map((tag) => (
                        <Badge key={tag} variant="secondary" className="font-normal">#{tag}</Badge>
                      ))}
                    </div>
                  )}
                </div>
                <div className="hidden lg:block">{amountField}</div>
                <Combobox
                  options={otherOptions(row)}
                  value={row.other}
                  onChange={(other) => update(index, { other })}
                  onCreate={(name) => update(index, { other: `${NEW_PREFIX}${name}` })}
                  placeholder={t(rowKind(row, assetIds) === 'deposit' ? 'transactionForm.sourceRevenue' : 'transactionForm.destinationExpense')}
                  clearable
                />
                <Combobox
                  options={categoryOptions(row.category)}
                  value={row.category}
                  onChange={(category) => update(index, { category })}
                  onCreate={(name) => update(index, { category: `${NEW_PREFIX}${name}` })}
                  placeholder={t('transactionForm.category')}
                  clearable
                />
              </div>
            )
          })}
        </div>
      )}
    </>
  )
}
