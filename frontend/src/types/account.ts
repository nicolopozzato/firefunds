import type { DistributionEntry } from '@/types/transaction'

export type AccountType = 'asset' | 'expense' | 'revenue'

/** Set once at creation for asset accounts and never changed afterwards. */
export type AssetAccountKind = 'checking' | 'investment'

/** Which accounts the list shows: by active state, and (asset accounts only) by kind. */
export type AccountStatusFilter = 'all' | 'active' | 'inactive'

export interface AccountListFilters {
  status?: AccountStatusFilter
  kind?: AssetAccountKind
}

export interface Account {
  id: string
  type: AccountType
  name: string
  iban: string | null
  bic: string | null
  accountNumber: string | null
  notes: string | null
  isActive: boolean
  /** Shown on the dashboard (a user preference, asset accounts only). */
  showOnDashboard: boolean
  assetKind: AssetAccountKind | null
  currencyCode: string | null
  currencySymbol: string | null
  openingBalance: string | null
  openingBalanceDate: string | null
  currentBalance: string
  lastActivityAt: string | null
  createdAt: string
  updatedAt: string
  /** Current market value of everything held on this account; only set for an investment account. */
  investedValue: string | null
  /** investedValue minus the positions' cost basis; only set for an investment account. */
  gainAmount: string | null
  /** Capital gains tax owed if every position were sold today (an estimate); only set for an investment account. */
  estimatedTax?: string | null
  /** gainAmount as a percentage of the cost basis; only set for an investment account. */
  gainPercent: string | null
}

export interface CreateAccountRequest {
  type: AccountType
  name: string
  iban?: string | null
  bic?: string | null
  accountNumber?: string | null
  notes?: string | null
  isActive?: boolean
  currencyCode?: string | null
  openingBalance?: string | null
  openingBalanceDate?: string | null
  assetKind?: AssetAccountKind | null
}

export interface UpdateAccountRequest {
  name: string
  iban?: string | null
  bic?: string | null
  accountNumber?: string | null
  notes?: string | null
  isActive?: boolean
  currencyCode?: string | null
  openingBalance?: string | null
  openingBalanceDate?: string | null
}

export interface BalancePoint {
  date: string
  balance: string
}

export interface AccountValueHistoryPoint {
  date: string
  available: string
  invested: string
  total: string
}

export interface AccountDistributions {
  expenses: DistributionEntry[]
  income: DistributionEntry[]
}

/** What deleting an account would affect, shown before the user confirms. */
export interface AccountDeletionPreview {
  /** Non-zero opening balance, moved as a transaction on transfer; null if there is none. */
  openingBalance: string | null
  transactions: number
  investmentTransactions: number
  openPositions: number
  /** Accounts of the same type, kind and currency that can take over its transactions. */
  transferTargets: { id: string; name: string }[]
}

/** Where a dragged account lands: right after or right before another account of the same type. */
export type MoveAccountTarget = { afterId: string } | { beforeId: string }

/** What to do with an account's transactions when deleting it. */
export type DeleteAccountOptions = { transferTo: string } | { deleteTransactions: true } | Record<string, never>
