import type { InvestmentPrice } from '@/types/investment'

/**
 * Each day's change from the previous price (the last day before it with a
 * price of its own, not carried over), by date. Prices oldest first.
 */
export function changesFromPrevious(prices: InvestmentPrice[]): Map<string, { amount: number; percent: number }> {
  const changes = new Map<string, { amount: number; percent: number }>()
  let previous: number | null = null
  for (const price of prices) {
    const value = Number(price.price)
    if (previous !== null && previous !== 0) {
      changes.set(price.date, { amount: value - previous, percent: ((value - previous) / previous) * 100 })
    }
    if (price.source !== 'filled') previous = value
  }
  return changes
}
