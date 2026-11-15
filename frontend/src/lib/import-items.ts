/**
 * What every file the import reads becomes (a bank's statement, a
 * spreadsheet of several accounts, a Firefly III export), before the user
 * matches its names to FireFunds and reviews it: one item per movement, its
 * amount signed from the side of the user's account it is on. Pure
 * functions: tested on their own.
 */
import type { CsvRow } from './csv'

/** What the other side of a movement is, when the file says (Firefly does: its account types). */
export type CounterpartyHint = 'asset' | 'payee' | 'none'

export interface ImportItem {
  /** The line in the file (1-based, header included). */
  line: number
  /** A movement; an account's opening balance; another change to its balance (a reconciliation). */
  kind: 'transaction' | 'opening' | 'adjustment'
  /** yyyy-MM-dd */
  date: string
  /** HH:mm:ss, when the file has the time. */
  time: string | null
  /** Signed, "." for decimals: negative is out of the account. */
  amount: string
  /** The user's account the movement is on, by name; null: the one account chosen for the whole file. */
  account: string | null
  /** The currency the file writes the amount in. */
  currency: string | null
  counterparty: string
  /** Null: not said, it is a payee unless it names one of the user's accounts. */
  counterpartyHint: CounterpartyHint | null
  description: string
  category: string
  tags: string[]
  notes: string
  /** What was paid in another currency (a card abroad), or what arrived on the other account of a transfer. */
  foreignAmount: string | null
  foreignCurrency: string | null
}

/** Each valid line of a CSV or Excel file (a day and an amount not zero). */
export function itemsFromRows(rows: CsvRow[]): ImportItem[] {
  return rows
    .filter((row): row is CsvRow & { date: string; amount: string } => row.date !== null && row.amount !== null && Number(row.amount) !== 0)
    .map((row) => ({
      line: row.line,
      kind: 'transaction',
      date: row.date,
      time: null,
      amount: row.amount,
      account: row.account || null,
      currency: null,
      counterparty: row.counterparty,
      counterpartyHint: null,
      description: row.description,
      category: row.category,
      tags: row.tags,
      notes: row.notes,
      foreignAmount: null,
      foreignCurrency: null,
    }))
}

const lower = (name: string) => name.trim().toLocaleLowerCase()

/** Whether the item's other side is one of the user's accounts: said by the file, else named like one. */
export function isTransferItem(item: ImportItem, ownNames: Set<string>): boolean {
  if (item.counterpartyHint !== null) return item.counterpartyHint === 'asset'
  return item.counterparty !== '' && ownNames.has(lower(item.counterparty))
}

/**
 * A spreadsheet of several accounts lists a transfer twice, once on each
 * account: the money going out of A to B, and coming into B from A, the
 * same amount within a day. Only the leg going out is kept.
 *
 * @return the items left, and how many legs were dropped
 */
export function pairTransferLegs(items: ImportItem[], ownNames: Set<string>): { items: ImportItem[]; paired: number } {
  const dropped = new Set<ImportItem>()
  const incoming = items.filter((item) => item.kind === 'transaction' && !item.amount.startsWith('-') && item.account !== null && isTransferItem(item, ownNames))
  for (const out of items) {
    if (out.kind !== 'transaction' || !out.amount.startsWith('-') || out.account === null || !isTransferItem(out, ownNames)) continue
    const leg = incoming.find(
      (item) =>
        !dropped.has(item) &&
        lower(item.account ?? '') === lower(out.counterparty) &&
        lower(item.counterparty) === lower(out.account ?? '') &&
        Number(item.amount) === -Number(out.amount) &&
        Math.abs(dayNumber(item.date) - dayNumber(out.date)) <= 1
    )
    if (leg) dropped.add(leg)
  }
  return { items: items.filter((item) => !dropped.has(item)), paired: dropped.size }
}

export interface AccountName {
  name: string
  /** How many movements are on it, or go to it as a transfer. */
  count: number
  currency: string | null
  /** Its opening balance, when the file has it (Firefly). */
  opening: { amount: string; date: string } | null
}

export interface PayeeName {
  name: string
  count: number
}

export interface CategoryName {
  name: string
  count: number
}

/**
 * The names a file of several accounts uses, each once (the first spelling
 * met), with how often: the user's accounts (those its rows are on, and
 * those a transfer goes to), the payees, the categories. A bank's statement
 * (no account column) has its payees matched row by row instead.
 */
export function collectNames(items: ImportItem[], byRowPayees: boolean): { accounts: AccountName[]; payees: PayeeName[]; categories: CategoryName[] } {
  const accounts = new Map<string, AccountName>()
  const payees = new Map<string, PayeeName>()
  const categories = new Map<string, CategoryName>()
  const ownNames = new Set(items.filter((item) => item.account !== null).map((item) => lower(item.account ?? '')))

  const account = (name: string, currency: string | null) => {
    const key = lower(name)
    const entry = accounts.get(key) ?? { name: name.trim(), count: 0, currency: null, opening: null }
    entry.currency ??= currency
    accounts.set(key, entry)
    return entry
  }

  for (const item of items) {
    if (item.account !== null) {
      const entry = account(item.account, item.currency)
      if (item.kind === 'opening') entry.opening = { amount: item.amount, date: item.date }
      else entry.count++
    }
    if (item.kind !== 'transaction') continue
    if (item.counterparty !== '' && isTransferItem(item, ownNames)) {
      account(item.counterparty, item.foreignCurrency ?? item.currency).count++
    } else if (item.counterparty !== '' && item.counterpartyHint !== 'none' && !byRowPayees) {
      const key = lower(item.counterparty)
      const entry = payees.get(key) ?? { name: item.counterparty.trim(), count: 0 }
      entry.count++
      payees.set(key, entry)
    }
    if (item.category.trim() !== '') {
      const key = lower(item.category)
      const entry = categories.get(key) ?? { name: item.category.trim(), count: 0 }
      entry.count++
      categories.set(key, entry)
    }
  }

  const byCount = <T extends { name: string; count: number }>(list: T[]) => list.sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
  return { accounts: byCount([...accounts.values()]), payees: byCount([...payees.values()]), categories: byCount([...categories.values()]) }
}

/** The same name, ignoring case and the spaces around it. */
export function sameName(a: string, b: string): boolean {
  return lower(a) === lower(b)
}

function dayNumber(date: string): number {
  return Date.parse(`${date}T00:00:00Z`) / 86_400_000
}
