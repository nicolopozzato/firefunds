/**
 * A Firefly III transaction export (Export data → transactions, a CSV), read
 * into the import's items. Firefly has no investments: withdrawals, deposits,
 * transfers, opening balances and reconciliations. Its account types say what
 * each side is: an asset account, a liability (FireFunds has none: it becomes
 * an asset account with a balance below zero), a payee, or Firefly's "Cash
 * account", which is FireFunds' empty side.
 */
import type { CounterpartyHint, ImportItem } from './import-items'

const REQUIRED = ['type', 'amount', 'description', 'date', 'source_name', 'source_type', 'destination_name', 'destination_type']

const OWN_TYPES = ['asset account', 'default account', 'loan', 'debt', 'mortgage', 'liability credit account']

/** Whether the file's header is a Firefly export's. */
export function isFireflyExport(header: string[]): boolean {
  const names = new Set(header.map((cell) => cell.trim().toLowerCase()))
  return REQUIRED.every((name) => names.has(name)) && (names.has('journal_id') || names.has('group_id'))
}

export interface FireflyRead {
  items: ImportItem[]
  /** Lines of a kind FireFunds can't record, by Firefly type. */
  skipped: Record<string, number>
}

/** The export's lines as items (the header first; separator ","). */
export function readFirefly(table: string[][]): FireflyRead {
  const header = (table[0] ?? []).map((cell) => cell.trim().toLowerCase())
  const at = (cells: string[], name: string) => (cells[header.indexOf(name)] ?? '').trim()
  const items: ImportItem[] = []
  const skipped: Record<string, number> = {}

  table.slice(1).forEach((cells, i) => {
    const type = at(cells, 'type').toLowerCase()
    const sourceType = at(cells, 'source_type').toLowerCase()
    const destinationType = at(cells, 'destination_type').toLowerCase()
    const source = at(cells, 'source_name')
    const destination = at(cells, 'destination_name')
    const amount = at(cells, 'amount').replace(/^[-+]/, '')
    const [date, rest] = at(cells, 'date').split('T')
    const time = rest ? rest.slice(0, 8) : null
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? '') || !/^\d+(\.\d+)?$/.test(amount) || Number(amount) === 0) {
      skipped[type || '?'] = (skipped[type || '?'] ?? 0) + 1
      return
    }

    const sourceOwn = OWN_TYPES.includes(sourceType)
    const destinationOwn = OWN_TYPES.includes(destinationType)
    const base = {
      line: i + 2,
      date,
      time: time && /^\d{2}:\d{2}:\d{2}$/.test(time) ? time : null,
      currency: at(cells, 'currency_code') || null,
      description: at(cells, 'description') || at(cells, 'group_title'),
      category: at(cells, 'category'),
      tags: at(cells, 'tags').split(',').map((tag) => tag.trim()).filter(Boolean),
      notes: at(cells, 'notes'),
      foreignAmount: at(cells, 'foreign_amount').replace(/^[-+]/, '') || null,
      foreignCurrency: at(cells, 'foreign_currency_code') || null,
    }
    if (base.foreignAmount !== null && (Number(base.foreignAmount) === 0 || base.foreignCurrency === null)) {
      base.foreignAmount = null
      base.foreignCurrency = null
    }

    if (type === 'opening balance' || type === 'liability credit' || type === 'reconciliation') {
      // One side is the user's account; the other is Firefly's own bookkeeping.
      const into = destinationOwn
      if (!into && !sourceOwn) {
        skipped[type] = (skipped[type] ?? 0) + 1
        return
      }
      items.push({
        ...base,
        kind: type === 'reconciliation' ? 'adjustment' : 'opening',
        amount: into ? amount : `-${amount}`,
        account: into ? destination : source,
        counterparty: '',
        counterpartyHint: 'none',
        foreignAmount: null,
        foreignCurrency: null,
      })
      return
    }

    // A deposit is on its destination; a withdrawal or a transfer on its source.
    const deposit = type === 'deposit' || (!sourceOwn && destinationOwn)
    if (!(deposit ? destinationOwn : sourceOwn)) {
      skipped[type || '?'] = (skipped[type || '?'] ?? 0) + 1
      return
    }
    items.push({
      ...base,
      kind: 'transaction',
      amount: deposit ? amount : `-${amount}`,
      account: deposit ? destination : source,
      counterparty: deposit ? source : destination,
      counterpartyHint: hint(deposit ? sourceType : destinationType),
    })
  })

  return { items, skipped }
}

function hint(type: string): CounterpartyHint {
  if (OWN_TYPES.includes(type)) return 'asset'
  if (type === 'cash account') return 'none'
  return 'payee'
}
