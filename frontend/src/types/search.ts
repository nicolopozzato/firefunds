import type { TransactionListKind } from '@/types/transaction'

/** The kinds the search results can be narrowed to. */
export type SearchKind = Extract<TransactionListKind, 'all' | 'withdrawal' | 'deposit' | 'transfer' | 'trade'>

/** What the transactions found add up to, per kind (decimal strings, 0 when none). */
export interface TransactionTotals {
  withdrawal: string
  deposit: string
  transfer: string
  investmentBuy: string
  investmentSell: string
}

export interface AccountSuggestion {
  id: string
  name: string
  type: 'asset' | 'expense' | 'revenue'
}

export interface InstrumentSuggestion {
  id: string
  name: string
  ticker: string | null
  type: string
}

/** Accounts and instruments matching what is typed in the search box. */
export interface SearchSuggestions {
  accounts: AccountSuggestion[]
  instruments: InstrumentSuggestion[]
}
