import type { DistributionEntry, Transaction } from './transaction'

/** One point of a daily series: an amount, or a percentage for a performance line. */
export interface DatedValue {
  date: string
  value: string
}

/** The period's headline figures over every account. "Start" = end of the day before the period. */
export interface DashboardSummary {
  income: string
  expenses: string
  /** income − expenses */
  balance: string
  /** balance as a percentage of income; null without income. */
  savingsRate: string | null
  netWorthStart: string
  netWorthEnd: string
  netWorthChange: string
  netWorthChangePercent: string | null
  investmentsStart: string
  investmentsEnd: string
  /** BUYs (costs included) minus SELLs in the period. */
  investmentContributions: string
  /** What the market did: end − start − contributions. */
  investmentGain: string
}

export interface DashboardAccount {
  id: string
  name: string
  assetKind: 'checking' | 'investment' | null
  currencySymbol: string | null
  /** At the end of the period; for an investment account, free cash plus what is held. */
  balance: string
  history: DatedValue[]
  /** The latest in the period, newest first. */
  transactions: Transaction[]
}

export interface DashboardInstrument {
  instrumentId: string
  instrumentName: string
  instrumentTicker: string | null
  valueStart: string
  valueEnd: string
  contributions: string
  /** valueEnd − valueStart − contributions */
  gain: string
  /** Market value of what is held, on the same days as the accounts' history. */
  values: DatedValue[]
  priceChangePercent: string | null
  /** The price's change since the period started, in percent, day by day. */
  performance: DatedValue[]
}

export interface Dashboard {
  currencySymbol: string | null
  summary: DashboardSummary
  accounts: DashboardAccount[]
  expensesByCategory: DistributionEntry[]
  expenseAccounts: DistributionEntry[]
  revenueAccounts: DistributionEntry[]
  instruments: DashboardInstrument[]
}
