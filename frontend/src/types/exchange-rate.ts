/** What 1 unit of a currency is worth in the user's own one, on a day. */
export interface ExchangeRate {
  date: string
  rate: string
  /** ecb, yahoo, filled (carried over from the day before) or manual (the user's). */
  source: 'ecb' | 'yahoo' | 'filled' | 'manual'
  /** The rate before the user's correction; only for a manual one. */
  originalRate: string | null
}

export interface ExchangeRateCurrency {
  currency: string
  date: string | null
  rate: string | null
  /** missing: no rate at all (sums count it as it is, holdings have no value); stale: the shared rates stopped days ago. */
  status: 'ok' | 'missing' | 'stale'
  /** The newest day with a rate of its own; later days carry it over. */
  latestDate: string | null
}

export interface ExchangeRatesOverview {
  /** The user's own currency: every rate is in it. */
  currency: string
  currencies: ExchangeRateCurrency[]
}
