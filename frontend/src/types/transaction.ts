export type TransactionKind = 'all' | 'withdrawal' | 'deposit' | 'transfer'
/** Lists only: also `trade` (investment BUY/SELLs only) and `cash` (money transactions only, any kind). */
export type TransactionListKind = TransactionKind | 'trade' | 'cash'

export interface AccountRef {
  id: string
  name: string
  type: 'asset' | 'expense' | 'revenue' | 'instrument'
  /** Only for an instrument standing in as a side: etf, stock… (its name is then the ticker). */
  instrumentType?: string | null
}

export interface TagRef {
  id: string
  name: string
}

/** 'money' is a plain Transaction; 'investmentBuy'/'investmentSell' stand in for an InvestmentTransaction in the merged "all transactions" list. */
export type TransactionRowKind = 'money' | 'investmentBuy' | 'investmentSell'

export interface Transaction {
  id: string
  description: string | null
  /** null: money coming from outside the tracked accounts ("external"). */
  sourceAccount: AccountRef | null
  /** null: money leaving the tracked accounts ("external"). */
  destinationAccount: AccountRef | null
  /** The clock time where it happened, no offset: show it as it is. */
  occurredAt: string
  /** Where it happened (IANA). */
  timezone: string
  amount: string
  currency: string
  foreignAmount: string | null
  foreignCurrency: string | null
  categoryId: string | null
  categoryName: string | null
  tags: TagRef[]
  notes: string | null
  createdAt: string
  updatedAt: string
  kind: TransactionRowKind
  /** Investment BUY/SELL only; null for a money transaction. */
  quantity: string | null
  unitPrice: string | null
  /** Investment rows: the currency the unit price is in (the instrument's quote currency). */
  unitPriceCurrency?: string | null
  /** A transfer between currencies: what arrived on the destination account, in its currency (`amount` left the source). */
  destinationAmount?: string | null
  destinationCurrency?: string | null
  fees: string | null
  taxes: string | null
}

export interface DateRange {
  earliest: string | null
  latest: string | null
}

export interface DistributionEntry {
  id: string | null
  name: string | null
  total: string
}

export interface Distributions {
  categories: DistributionEntry[]
  sourceAccounts: DistributionEntry[]
  destinationAccounts: DistributionEntry[]
}

export interface CreateTransactionRequest {
  description?: string | null
  sourceAccountId: string | null
  destinationAccountId: string | null
  occurredAt: string
  /** Where it happened (IANA); left out on an edit to keep it. */
  timezone?: string
  amount: string
  /** A transfer between currencies: what arrived on the destination account. */
  destinationAmount?: string | null
  foreignAmount?: string | null
  foreignCurrency?: string | null
  categoryId?: string | null
  tagIds?: string[]
  notes?: string | null
  /**
   * Written in the form instead of picked (a side's id is then null): the
   * server finds the user's one with that name, any case, or creates it in
   * the same save. Source: a revenue account, destination: an expense account.
   */
  newSourceAccountName?: string | null
  newDestinationAccountName?: string | null
  newCategoryName?: string | null
  newTagNames?: string[]
}
