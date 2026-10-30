import type { AccountRef, Transaction } from '@/types/transaction'

/** Something that came from outside and may be a transaction, waiting for the user ("Da rivedere"). */
export interface Draft {
  id: string
  status: 'pending' | 'accepted' | 'matched' | 'discarded'
  source: string
  sourceName: string | null
  description: string | null
  amount: string
  currency: string
  direction: 'out' | 'in' | null
  occurredAt: string
  timezone: string
  sourceAccount: AccountRef | null
  destinationAccount: AccountRef | null
  notes: string | null
  rawText: string | null
  /** An identical transaction already recorded: is it this one? */
  possibleDuplicate: Transaction | null
  transactionIds: string[]
  createdAt: string
  /** What it most likely is, from what came before (pending drafts only). */
  suggestion: DraftSuggestion | null
}

export interface DraftSuggestion {
  kind: 'withdrawal' | 'deposit' | 'transfer' | null
  /** The other side. */
  account: AccountRef | null
  categoryId: string | null
  /** The other leg of a transfer, closed with this one. */
  pairedDraftId: string | null
  /** Payees with a similar name, when none is sure. */
  similar: AccountRef[]
  /** What the user always writes with this payee, in place of the bank's merchant. */
  description: string | null
  /** What the user wrote with this payee lately, to pick from. */
  recent: Pick<PayeeHistory, 'descriptions' | 'categories'> | null
}

/** What the user wrote before with a payee, whatever account paid; latest first. */
export interface PayeeHistory {
  /** The description the latest ones agree on. */
  usual: string | null
  usualCategoryId: string | null
  categoryId: string | null
  descriptions: { description: string; categoryId: string | null; uses: number }[]
  categories: string[]
}

export type ApiTokenScope = 'full' | 'drafts'

export interface ApiToken {
  id: string
  name: string
  scope: ApiTokenScope
  hint: string
  createdAt: string
  lastUsedAt: string | null
  /** Only right after it is made. */
  token?: string
}
