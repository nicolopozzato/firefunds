/**
 * From the file's items and how the user matched its names (accounts,
 * payees, categories) to what is recorded: the rows of the review, then the
 * import's payload. Pure functions: the page keeps the state, the server
 * checks the rows (`import-api.ts`).
 */
import { isTransferItem, sameName, type AccountName, type ImportItem } from './import-items'
import type { CommitImportBody, ImportRowCheck } from './import-api'
import type { CreateTransactionRequest } from '@/types/transaction'

/** A name of the file's for one of the user's accounts: one already in FireFunds, or one to create. */
export type AccountTarget = { kind: 'existing'; id: string } | { kind: 'new'; name: string; currency: string }

/** A payee's or category's name: kept, or another (an existing one's, to merge them), or none at all. */
export type NameTarget = { kind: 'name'; name: string } | { kind: 'none' }

export interface Mapping {
  /** The account of a bank's statement (no account column). */
  fixedAccountId: string | null
  /** By lower-case name. */
  accounts: Record<string, AccountTarget>
  payees: Record<string, NameTarget>
  categories: Record<string, NameTarget>
}

export interface Named {
  id: string
  name: string
}

export interface Lists {
  assets: (Named & { currencyCode?: string | null })[]
  expenses: Named[]
  revenues: Named[]
  categories: Named[]
}

/**
 * A transaction a file's line becomes: the line itself (`part` 0), or one
 * more when the line is split. The amount is the user's to change (50 paid,
 * 25 recorded); the file's stays in `item`.
 */
export interface ReviewRow {
  key: string
  part: number
  item: ImportItem
  check: ImportRowCheck
  include: boolean
  /** Positive: the direction is the file's. */
  amount: string
  description: string
  /** The user's account: an id, or `KEY:` and the key of one the import creates. */
  own: string
  /** An account id, `KEY:` a new account's key, `NEW:` a payee's name to find or create, '' for none. */
  other: string
  /** An id, `NEW:` a name, '' for none. */
  category: string
  tags: string[]
  notes: string
}

export const NEW_PREFIX = 'NEW:'
export const KEY_PREFIX = 'KEY:'

const lower = (name: string) => name.trim().toLocaleLowerCase()
const abs = (amount: string) => amount.replace(/^-/, '')

/** The key each new account goes by in the payload (short, as the server wants). */
export function accountKeys(accounts: AccountName[]): Record<string, string> {
  return Object.fromEntries(accounts.map((account, i) => [lower(account.name), `a${i + 1}`]))
}

/** The default match of a file's account: the user's account with the same name, else a new one. */
export function defaultAccountTarget(account: AccountName, lists: Lists, fallbackCurrency: string): AccountTarget {
  const existing = lists.assets.find((asset) => sameName(asset.name, account.name))
  return existing ? { kind: 'existing', id: existing.id } : { kind: 'new', name: account.name, currency: account.currency ?? fallbackCurrency }
}

/** The user's account an item is on, as `ReviewRow.own` writes it; '' when not matched. */
export function ownRef(item: ImportItem, mapping: Mapping, keys: Record<string, string>): string {
  if (item.account === null) return mapping.fixedAccountId ?? ''
  const target = mapping.accounts[lower(item.account)]
  if (!target) return ''
  return target.kind === 'existing' ? target.id : `${KEY_PREFIX}${keys[lower(item.account)]}`
}

/** The rows of the review, before the server's checks: the file's movements, as matched. */
export function reviewRows(items: ImportItem[], mapping: Mapping, lists: Lists, keys: Record<string, string>): ReviewRow[] {
  const ownNames = new Set([...Object.keys(mapping.accounts), ...lists.assets.map((asset) => lower(asset.name))])
  return items
    .filter((item) => item.kind === 'transaction')
    .map((item) => {
      const out = item.amount.startsWith('-')
      return {
        key: String(item.line),
        part: 0,
        item,
        check: { index: 0, duplicate: null, recurring: null, suggestion: null },
        include: true,
        amount: abs(item.amount),
        description: item.description || item.counterparty,
        own: ownRef(item, mapping, keys),
        other: otherRef(item, out, mapping, lists, keys, ownNames),
        category: categoryRef(item.category, mapping, lists),
        tags: item.tags,
        notes: item.notes,
      }
    })
}

/**
 * The server's checks applied: a row already recorded is left out, a
 * recurring occurrence takes that recurrence's other side and category, a
 * row the file left without them takes those of the transaction of before.
 */
export function withChecks(rows: ReviewRow[], checks: ImportRowCheck[]): ReviewRow[] {
  return rows.map((row, i) => {
    const check = checks[i]
    // A bank's own words for the other side give way to what was recorded before; what a file of
    // several accounts says (an empty side included, and what the user matched) stays.
    const guessOther = (row.item.counterpartyHint === null && row.item.account === null) || (row.item.counterparty === '' && row.item.counterpartyHint === null)
    const guessCategory = row.item.category.trim() === ''
    return {
      ...row,
      check,
      include: check.duplicate === null,
      other: guessOther ? check.recurring?.accountId || check.suggestion?.account?.id || row.other : row.other,
      category: row.category || (guessCategory ? check.recurring?.categoryId || check.suggestion?.categoryId || '' : ''),
    }
  })
}

/** Withdrawal, deposit or transfer, from the row's sides. */
export function rowKind(row: ReviewRow, assetIds: Set<string>): 'withdrawal' | 'deposit' | 'transfer' {
  if (row.other.startsWith(KEY_PREFIX) || assetIds.has(row.other)) return 'transfer'
  return row.item.amount.startsWith('-') ? 'withdrawal' : 'deposit'
}

/** What the row's review still needs before it can be recorded; null when it is ready. */
export function rowProblem(row: ReviewRow): 'account' | 'description' | 'amount' | 'same' | null {
  if (row.own === '') return 'account'
  if (!row.description.trim()) return 'description'
  if (!(Number(row.amount) > 0)) return 'amount'
  if (row.other !== '' && row.other === row.own) return 'same'
  return null
}

/** The import's payload: the accounts to create, the rows kept, the balance changes. */
export function commitBody(
  fileName: string,
  rows: ReviewRow[],
  items: ImportItem[],
  accounts: AccountName[],
  mapping: Mapping,
  keys: Record<string, string>,
  assetIds: Set<string>,
  timezone: string
): CommitImportBody {
  const created = accounts.flatMap((account) => {
    const target = mapping.accounts[lower(account.name)]
    if (target?.kind !== 'new') return []
    return [
      {
        key: keys[lower(account.name)],
        name: target.name.trim() || account.name,
        currencyCode: target.currency,
        ...(account.opening ? { openingBalance: account.opening.amount, openingBalanceDate: account.opening.date } : {}),
      },
    ]
  })

  const adjustments = items.flatMap((item) => {
    const own = item.kind === 'adjustment' ? ownRef(item, mapping, keys) : ''
    if (own === '') return []
    return [
      {
        ...(own.startsWith(KEY_PREFIX) ? { accountKey: own.slice(KEY_PREFIX.length) } : { accountId: own }),
        date: item.date,
        amount: item.amount,
        description: item.description.slice(0, 255) || null,
      },
    ]
  })

  return {
    fileName,
    accounts: created,
    rows: rows
      .filter((row) => row.include)
      .map((row) => ({
        ...rowPayload(row, assetIds, timezone),
        ...(row.part === 0 && row.check.recurring && row.other === row.check.recurring.accountId
          ? { recurringId: row.check.recurring.id, recurringDay: row.check.recurring.day }
          : {}),
      })),
    adjustments,
  }
}

/** Opening balances the file gives for accounts already in FireFunds: they keep their own. */
export function ignoredOpenings(accounts: AccountName[], mapping: Mapping): number {
  return accounts.filter((account) => account.opening && mapping.accounts[lower(account.name)]?.kind === 'existing').length
}

function rowPayload(row: ReviewRow, assetIds: Set<string>, timezone: string): CommitImportBody['rows'][number] {
  const out = row.item.amount.startsWith('-')
  const transfer = rowKind(row, assetIds) === 'transfer'
  const side = (ref: string, which: 'source' | 'destination') => {
    if (ref.startsWith(KEY_PREFIX)) return { key: { [`${which}AccountKey`]: ref.slice(KEY_PREFIX.length) }, id: null, name: null }
    if (ref.startsWith(NEW_PREFIX)) return { key: {}, id: null, name: ref.slice(NEW_PREFIX.length) }
    return { key: {}, id: ref || null, name: null }
  }
  const source = side(out ? row.own : row.other, 'source')
  const destination = side(out ? row.other : row.own, 'destination')
  const category = row.category.startsWith(NEW_PREFIX) ? { id: null, name: row.category.slice(NEW_PREFIX.length) } : { id: row.category || null, name: null }
  const foreign = row.part === 0 && row.item.foreignAmount !== null && row.item.foreignCurrency !== null
  const transaction: CreateTransactionRequest = {
    description: row.description.trim() || null,
    amount: row.amount,
    occurredAt: `${row.item.date}T${row.item.time ?? '12:00:00'}`,
    timezone,
    sourceAccountId: source.id,
    newSourceAccountName: source.name,
    destinationAccountId: destination.id,
    newDestinationAccountName: destination.name,
    categoryId: category.id,
    newCategoryName: category.name,
    tagIds: [],
    newTagNames: row.tags,
    notes: row.notes.trim() || null,
    // A transfer's other amount is what arrived; anything else's is what was paid abroad.
    ...(foreign && transfer && out ? { destinationAmount: row.item.foreignAmount } : {}),
    ...(foreign && !transfer ? { foreignAmount: row.item.foreignAmount, foreignCurrency: row.item.foreignCurrency } : {}),
  }
  return { transaction, ...source.key, ...destination.key }
}

function otherRef(item: ImportItem, out: boolean, mapping: Mapping, lists: Lists, keys: Record<string, string>, ownNames: Set<string>): string {
  if (item.counterparty === '' || item.counterpartyHint === 'none') return ''
  if (isTransferItem(item, ownNames)) {
    const target = mapping.accounts[lower(item.counterparty)]
    if (target) return target.kind === 'existing' ? target.id : `${KEY_PREFIX}${keys[lower(item.counterparty)]}`
    return lists.assets.find((asset) => sameName(asset.name, item.counterparty))?.id ?? ''
  }
  const target = mapping.payees[lower(item.counterparty)] ?? { kind: 'name', name: item.counterparty }
  if (target.kind === 'none') return ''
  const existing = (out ? lists.expenses : lists.revenues).find((payee) => sameName(payee.name, target.name))
  return existing ? existing.id : `${NEW_PREFIX}${target.name.trim()}`
}

function categoryRef(name: string, mapping: Mapping, lists: Lists): string {
  if (name.trim() === '') return ''
  const target = mapping.categories[lower(name)] ?? { kind: 'name', name }
  if (target.kind === 'none') return ''
  const existing = lists.categories.find((category) => sameName(category.name, target.name))
  return existing ? existing.id : `${NEW_PREFIX}${target.name.trim()}`
}
