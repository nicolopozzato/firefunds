import type { DistributionEntry } from '@/types/transaction'

export type InvestmentInstrumentType = 'etf' | 'stock' | 'bond' | 'fund' | 'cryptocurrency' | 'other'

export interface InvestmentInstrument {
  id: string
  name: string
  type: InvestmentInstrumentType
  ticker: string | null
  yahooTicker: string | null
  isin: string | null
  /** This instrument's own capital gains tax percentage; null = the user's. */
  capitalGainsTaxRate: string | null
  createdAt: string
  updatedAt: string
}

export interface InvestmentInstrumentRequest {
  name: string
  type: InvestmentInstrumentType
  ticker?: string | null
  yahooTicker?: string | null
  isin?: string | null
  capitalGainsTaxRate?: string | null
}

export interface InvestmentPrice {
  date: string
  price: string
  originalPrice: string
}

export type MarketState = 'open' | 'pre_market' | 'post_market' | 'closed' | 'unknown'

export interface InvestmentLivePrice {
  price: string | null
  marketState: MarketState
  fetchedAt: string
}

export type InvestmentTransactionType = 'buy' | 'sell'

export interface AccountRef {
  id: string
  name: string
  type: 'asset' | 'expense' | 'revenue'
}

export interface InvestmentTransaction {
  id: string
  description: string | null
  account: AccountRef
  instrumentId: string
  instrumentName: string
  instrumentTicker: string | null
  type: InvestmentTransactionType
  quantity: string
  unitPrice: string
  /** What the broker charged/credited for the units, fees and taxes excluded: usually quantity × unit price, rounded by the broker. */
  amount: string
  fees: string
  /** Taxes the broker withheld on the trade (capital gains on a SELL, Tobin tax on a BUY). */
  taxes: string
  totalAmount: string
  /** The broker account's currency code (unit price, fees, taxes and total are in it). */
  currency: string
  occurredAt: string
  notes: string | null
  createdAt: string
  updatedAt: string
  /** True when the instrument's daily closing-price history still has a gap through yesterday after this save's best-effort backfill attempt. */
  pricesIncomplete: boolean
}

/** The pies above the investment transactions list. An instrument type comes as its code (`etf`…), to translate. */
export interface InvestmentDistributions {
  instrumentTypes: DistributionEntry[]
  instruments: DistributionEntry[]
  accounts: DistributionEntry[]
}

export interface CreateInvestmentTransactionRequest {
  description?: string | null
  accountId: string
  instrumentId: string
  type: InvestmentTransactionType
  quantity: string
  unitPrice: string
  /** Defaults to quantity × unit price when left out. */
  amount?: string
  fees?: string
  taxes?: string
  occurredAt: string
  notes?: string | null
}

export interface InvestmentTransactionAuditLogChange {
  field: string
  oldValue: unknown
  newValue: unknown
}

export interface InvestmentTransactionAuditLogEntry {
  id: string
  action: 'created' | 'updated'
  changes: InvestmentTransactionAuditLogChange[] | null
  createdAt: string
}

export interface PositionHistoryPoint {
  date: string
  value: string
  costBasis: string
}

/** An open position on one specific broker account (not aggregated across brokers). */
export interface OpenPosition {
  instrumentId: string
  instrumentName: string
  instrumentTicker: string | null
  quantity: string
  costBasis: string
  averageCost: string
  /** Day (YYYY-MM-DD, user's timezone) the current holding was opened. */
  openedOn: string | null
  currentPrice: string | null
  currentValue: string | null
  gainAmount: string | null
  gainPercent: string | null
  /** Capital gains tax percentage applied (the instrument's, or the user's). */
  taxRate: string
  /** Tax owed on the gain if sold today: 0 at a loss; null without a price. */
  estimatedTax: string | null
  history: PositionHistoryPoint[]
}

/** The part of a portfolio position held on one investment account. */
export interface PortfolioPositionAccount {
  accountId: string
  accountName: string
  quantity: string
  costBasis: string
  averageCost: string
  currentValue: string | null
  gainAmount: string | null
  gainPercent: string | null
}

/** One instrument across every investment account holding it. */
export interface PortfolioPosition extends OpenPosition {
  /** Share of the portfolio's market value, as a percentage. */
  portfolioShare: string | null
  /** Share of the net worth, as a percentage. */
  netWorthShare: string | null
  accounts: PortfolioPositionAccount[]
}

/** Money put into (BUYs, costs included) and taken out of (SELLs, net of costs) investments in a month. */
export interface MonthlyInvestmentFlow {
  /** YYYY-MM */
  month: string
  bought: string
  sold: string
  /** bought - sold: positive = money put in. */
  net: string
}

/** Every investment account at once: Investments -> Overview. */
export interface Portfolio {
  currencySymbol: string | null
  marketValue: string
  costBasis: string
  gainAmount: string
  gainPercent: string
  estimatedTax: string
  /** Free cash on the investment accounts. */
  cash: string
  /** Every asset account's balance plus the market value of what is held. */
  netWorth: string
  /** marketValue as a percentage of netWorth; null without a positive net worth. */
  netWorthShare: string | null
  /** Largest value first. */
  positions: PortfolioPosition[]
  /** The whole portfolio day by day, holdings closed since included. */
  history: PositionHistoryPoint[]
  monthlyFlows: MonthlyInvestmentFlow[]
}

/** What deleting an instrument would affect, shown before the user confirms. */
export interface InstrumentDeletionPreview {
  investmentTransactions: number
  openPositions: number
}
