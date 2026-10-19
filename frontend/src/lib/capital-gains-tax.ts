import { numberFormatter } from '@/lib/utils'
import type { InvestmentInstrumentType } from '@/types/investment'

/** Italian rate on white-list government bonds, and on the share of a fund invested in them. */
export const WHITE_LIST_TAX_RATE = 12.5

/** A tax rate with decimals only when it has them: 26% / 15,46%. */
export function formatRate(rate: string | number): string {
  return `${numberFormatter({ maximumFractionDigits: 2 }).format(Number(rate))}%`
}

/**
 * Effective rate of a fund with this share (%) in white-list bonds: the
 * standard rate on the rest, 12.5% on that share. 78.08% at 26% -> "15.46".
 */
export function rateFromWhiteListShare(standardRate: number, sharePercent: number): string {
  const share = sharePercent / 100
  return (Math.round((standardRate * (1 - share) + WHITE_LIST_TAX_RATE * share) * 100) / 100).toFixed(2)
}

/**
 * The white-list share (%) an effective rate implies, the reverse of
 * rateFromWhiteListShare(); null when it can't come from one (a rate outside
 * 12.5%..standard, or an instrument that isn't a fund or a bond).
 */
export function whiteListShareFromRate(
  standardRate: number,
  rate: number,
  type: InvestmentInstrumentType
): number | null {
  if (!['etf', 'fund', 'bond'].includes(type)) return null
  if (rate < WHITE_LIST_TAX_RATE || rate >= standardRate || standardRate <= WHITE_LIST_TAX_RATE) return null
  return ((standardRate - rate) / (standardRate - WHITE_LIST_TAX_RATE)) * 100
}
