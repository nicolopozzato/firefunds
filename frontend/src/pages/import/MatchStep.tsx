import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'
import Combobox, { type ComboboxOption } from '@/components/Combobox'
import FormSection from '@/components/FormSection'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useCurrencyOptions } from '@/hooks/use-currencies'
import { sameName, type AccountName, type CategoryName, type PayeeName } from '@/lib/import-items'
import type { AccountTarget, Lists, Mapping, NameTarget } from '@/lib/import-plan'
import { currencySymbolFor, formatMoney, formatShortDate } from '@/lib/utils'

const NEW_ACCOUNT = '__new__'
const NONE = '__none__'
const PAGE = 30

interface MatchStepProps {
  accounts: AccountName[]
  payees: PayeeName[]
  categories: CategoryName[]
  mapping: Mapping
  onMapping: (mapping: Mapping) => void
  lists: Lists
  /** What the file has that isn't imported, or is taken together: said before the lists. */
  notes: string[]
}

const lower = (name: string) => name.trim().toLocaleLowerCase()

/**
 * The names the file uses, each matched once for all its rows: its accounts
 * to the user's accounts (or new ones), its payees and categories kept,
 * merged into ones already there, or left out.
 */
export default function MatchStep({ accounts, payees, categories, mapping, onMapping, lists, notes }: MatchStepProps) {
  const { t } = useTranslation()
  const currencyOptions = useCurrencyOptions()

  const setAccount = (name: string, target: AccountTarget) => onMapping({ ...mapping, accounts: { ...mapping.accounts, [lower(name)]: target } })
  const assetOptions: ComboboxOption[] = [{ value: NEW_ACCOUNT, label: t('csvImport.newAccount') }, ...lists.assets.map((asset) => ({ value: asset.id, label: asset.name }))]
  const payeeNames = useMemo(() => uniqueNames([...lists.expenses, ...lists.revenues].map((payee) => payee.name)), [lists.expenses, lists.revenues])
  const categoryNames = useMemo(() => uniqueNames(lists.categories.map((category) => category.name)), [lists.categories])

  return (
    <>
      {notes.length > 0 && (
        <ul className="surface space-y-1 px-4 py-3 text-sm text-muted-foreground">
          {notes.map((note) => (
            <li key={note}>{note}</li>
          ))}
        </ul>
      )}

      {accounts.length > 0 && (
        <FormSection title={t('csvImport.matchAccounts')}>
          <p className="mb-3 text-sm text-muted-foreground">{t('csvImport.matchAccountsHint')}</p>
          <ul className="divide-y">
            {accounts.map((account) => {
              const target = mapping.accounts[lower(account.name)]
              return (
                <li key={account.name} className="grid gap-2 py-3 md:grid-cols-[minmax(0,1fr)_16rem_12rem] md:items-center md:gap-3">
                  <div className="min-w-0">
                    <div className="cell-text font-medium">{account.name}</div>
                    <div className="text-xs text-muted-foreground">
                      {t('csvImport.movements', { count: account.count })}
                      {account.opening && <> · {t('csvImport.openingBalance', { amount: formatMoney(account.opening.amount, currencySymbolFor(account.currency ?? '')), date: formatShortDate(account.opening.date) })}</>}
                    </div>
                  </div>
                  <Combobox
                    options={assetOptions}
                    value={target?.kind === 'existing' ? target.id : NEW_ACCOUNT}
                    onChange={(value) =>
                      setAccount(
                        account.name,
                        value === NEW_ACCOUNT || value === '' ? { kind: 'new', name: account.name, currency: account.currency ?? lists.assets[0]?.currencyCode ?? 'EUR' } : { kind: 'existing', id: value }
                      )
                    }
                  />
                  {target?.kind === 'new' ? (
                    <Combobox options={currencyOptions} value={target.currency} onChange={(currency) => currency && setAccount(account.name, { ...target, currency })} />
                  ) : (
                    <span className="max-md:hidden" />
                  )}
                </li>
              )
            })}
          </ul>
        </FormSection>
      )}

      {payees.length > 0 && (
        <NameList
          title={t('csvImport.matchPayees')}
          hint={t('csvImport.matchPayeesHint')}
          names={payees}
          existing={payeeNames}
          targets={mapping.payees}
          onTarget={(name, target) => onMapping({ ...mapping, payees: { ...mapping.payees, [lower(name)]: target } })}
        />
      )}

      {categories.length > 0 && (
        <NameList
          title={t('csvImport.matchCategories')}
          hint={t('csvImport.matchCategoriesHint')}
          names={categories}
          existing={categoryNames}
          targets={mapping.categories}
          onTarget={(name, target) => onMapping({ ...mapping, categories: { ...mapping.categories, [lower(name)]: target } })}
        />
      )}
    </>
  )
}

/** Payees or categories: how many are new, a search, and each one's match, a page at a time. */
function NameList({
  title,
  hint,
  names,
  existing,
  targets,
  onTarget,
}: {
  title: string
  hint: string
  names: { name: string; count: number }[]
  existing: string[]
  targets: Record<string, NameTarget>
  onTarget: (name: string, target: NameTarget) => void
}) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [onlyNew, setOnlyNew] = useState(false)
  const [shown, setShown] = useState(PAGE)
  const isKnown = (name: string) => existing.some((other) => sameName(other, name))
  const newCount = names.filter((entry) => !isKnown(entry.name)).length
  const filtered = names.filter((entry) => (!onlyNew || !isKnown(entry.name)) && (search === '' || lower(entry.name).includes(lower(search))))

  return (
    <FormSection
      title={title}
      aside={<span className="text-sm font-normal text-muted-foreground">{t('csvImport.namesCount', { count: names.length, new: newCount })}</span>}
    >
      <p className="mb-3 text-sm text-muted-foreground">{hint}</p>
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <div className="relative min-w-0 flex-1 sm:max-w-xs">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('csvImport.searchNames')} aria-label={t('csvImport.searchNames')} className="h-9 pl-8" />
        </div>
        {newCount > 0 && newCount < names.length && (
          <Button variant={onlyNew ? 'secondary' : 'ghost'} size="sm" className="h-9" aria-pressed={onlyNew} onClick={() => setOnlyNew(!onlyNew)}>
            {t('csvImport.onlyNew')}
          </Button>
        )}
      </div>
      <ul className="divide-y">
        {filtered.slice(0, shown).map((entry) => {
          const target = targets[lower(entry.name)] ?? { kind: 'name', name: entry.name }
          const value = target.kind === 'none' ? NONE : target.name
          const options: ComboboxOption[] = [
            { value: NONE, label: t('csvImport.leaveOut') },
            ...existing.map((name) => ({ value: name, label: name })),
            ...(target.kind === 'name' && !isKnown(target.name) ? [{ value: target.name, label: t('transactionForm.pendingNew', { name: target.name }) }] : []),
          ]
          return (
            <li key={entry.name} className="grid gap-2 py-2.5 md:grid-cols-[minmax(0,1fr)_16rem] md:items-center md:gap-3">
              <div className="flex min-w-0 items-center gap-2">
                <span className="cell-text">{entry.name}</span>
                {!isKnown(entry.name) && <Badge variant="info" className="shrink-0 font-normal">{t('csvImport.newName')}</Badge>}
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{entry.count}</span>
              </div>
              <Combobox
                options={options}
                value={value}
                onChange={(picked) => onTarget(entry.name, picked === NONE ? { kind: 'none' } : { kind: 'name', name: picked || entry.name })}
                onCreate={(name) => onTarget(entry.name, { kind: 'name', name })}
              />
            </li>
          )
        })}
      </ul>
      {filtered.length > shown && (
        <Button variant="ghost" size="sm" className="mt-2" onClick={() => setShown(shown + PAGE)}>
          {t('csvImport.showMore', { count: filtered.length - shown })}
        </Button>
      )}
    </FormSection>
  )
}

function uniqueNames(names: string[]): string[] {
  const seen = new Set<string>()
  return names.filter((name) => (seen.has(lower(name)) ? false : (seen.add(lower(name)), true))).sort((a, b) => a.localeCompare(b))
}
