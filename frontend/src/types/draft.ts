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
