import type { ExchangeRateCurrency } from '@/types/exchange-rate'

export interface RateWarning {
  /** Currencies with no rate at all: the warning can't be dismissed. */
  missing: string[]
  /** Currencies whose shared rates stopped, when they are to be shown. */
  stale: string[]
  /** The oldest of the stale currencies' last days. */
  staleSince: string | null
  /** The currency the warning's link opens. */
  first: string
  /** What dismissing remembers: shown again once a stale currency gets older still, or another one stops. */
  signature: string
}

/**
 * What the warning above the pages says, or null for nothing to say. Missing
 * rates always show; stale ones until dismissed for the days they stopped on.
 */
export function rateWarning(currencies: ExchangeRateCurrency[], dismissed: string | null): RateWarning | null {
  const missing = currencies.filter((item) => item.status === 'missing').map((item) => item.currency)
  const staleItems = currencies.filter((item) => item.status === 'stale')
  const signature = staleItems.map((item) => `${item.currency}:${item.latestDate}`).join(',')
  const stale = staleItems.length > 0 && (missing.length > 0 || dismissed !== signature) ? staleItems : []
  if (missing.length === 0 && stale.length === 0) return null

  return {
    missing,
    stale: stale.map((item) => item.currency),
    staleSince: stale.map((item) => item.latestDate ?? '').sort()[0] || null,
    first: missing[0] ?? stale[0].currency,
    signature,
  }
}
