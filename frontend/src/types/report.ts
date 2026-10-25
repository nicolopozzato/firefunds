import type { DatedValue } from './dashboard'

/** A column per calendar month or per calendar year. */
export type ReportGranularity = 'month' | 'year'

/** What a report's range is compared with: nothing, the range right before, the same range a year earlier. */
export type ReportComparison = 'none' | 'previous' | 'lastYear'

/** One column of the income and expenses report and what it adds up to. */
export interface ReportPeriod {
  /** "2026-03" for a month, "2026" for a year. */
  key: string
  from: string
  to: string
  /** Only part of the month or year is in the range. */
  partial: boolean
  income: string
  expenses: string
  /** income − expenses */
  balance: string
  /** Moved to investment accounts, net of what came back. */
  invested: string
}

/** "Start" = end of the day before the range. */
export interface IncomeExpenseSummary {
  income: string
  expenses: string
  balance: string
  savingsRate: string | null
  /** Part of the balance moved to investment accounts: not an expense. */
  invested: string
  netWorthStart: string
  netWorthEnd: string
  netWorthChange: string
  /** Calendar months of the range up to today: what the monthly averages divide by. */
  months: number
}

export interface IncomeExpenseComparison {
  from: string
  to: string
  /** Its own columns, matched to the report's by position. */
  periods: ReportPeriod[]
  summary: IncomeExpenseSummary
}

export interface ReportAccount {
  id: string
  name: string
  assetKind: 'checking' | 'investment' | null
  isActive: boolean
  start: string
  end: string
  change: string
  /** Investment accounts: the market value held, part of start and end. */
  investedStart: string | null
  investedEnd: string | null
  /** The account's own currency: start, end and change are in the user's, each at its day's rate. */
  currencyCode?: string | null
}

export interface CategoryRow {
  /** null = no category */
  id: string | null
  name: string | null
  /** One per column. */
  values: string[]
  total: string
  average: string
  comparisonValues: string[] | null
  comparisonTotal: string | null
}

export interface CategoryTable {
  /** Largest first, no category last. */
  rows: CategoryRow[]
  totals: string[]
  total: string
  average: string
  comparisonTotal: string | null
}

export interface IncomeExpenseReport {
  currencySymbol: string | null
  from: string
  to: string
  by: ReportGranularity
  periods: ReportPeriod[]
  summary: IncomeExpenseSummary
  comparison: IncomeExpenseComparison | null
  accounts: ReportAccount[]
  /** Day by day, at most a few hundred points. */
  netWorth: DatedValue[]
  expenses: CategoryTable
  income: CategoryTable
}

/** A column of the net worth report: the end of a month. */
export interface NetWorthMonth {
  /** Y-m */
  month: string
  /** The month's last day, today for the current month; null for a month still to come. */
  date: string | null
}

/** A value at the end of each month of the year and of the year before; null for months to come. */
export interface NetWorthRow {
  id: string | null
  name: string | null
  ticker: string | null
  values: (string | null)[]
  previousValues: (string | null)[]
  /** End of December two years before: what January of the year before changed from. */
  previousOpening: string | null
  /** Cash rows: the account's own currency (values are in the user's); price and rate rows: the currency they are of. */
  currencyCode?: string | null
}

export interface NetWorthReport {
  currencySymbol: string | null
  year: number
  firstYear: number
  currentYear: number
  months: NetWorthMonth[]
  previousMonths: NetWorthMonth[]
  /** Each asset account's free cash. */
  cash: NetWorthRow[]
  /** Each instrument held, every account together. */
  investments: NetWorthRow[]
  total: NetWorthRow
  /** Income − expenses of each month. */
  saved: NetWorthRow
  /** What the market did each month: change in value − money put in. */
  market: NetWorthRow
  /** Closing price at the end of each month. */
  prices: NetWorthRow[]
  /** What the exchange rates did to what is held each month. */
  exchange?: NetWorthRow | null
  /** What 1 unit of each other currency was worth in the user's at the end of each month. */
  rates?: NetWorthRow[]
  /** The net worth by currency of what is held, in the user's currency; empty with one currency. */
  currencies?: NetWorthRow[]
}
