import { describe, expect, it } from 'vitest'
import { rateWarning } from './exchange-rate-warning'
import type { ExchangeRateCurrency } from '@/types/exchange-rate'

const currency = (code: string, status: ExchangeRateCurrency['status'], latestDate: string | null = '2026-10-24'): ExchangeRateCurrency => ({
  currency: code,
  date: '2026-10-24',
  rate: status === 'missing' ? null : '0.9',
  status,
  latestDate: status === 'missing' ? null : latestDate,
})

describe('rateWarning', () => {
  it('says nothing when every rate can be trusted, or there are none', () => {
    expect(rateWarning([], null)).toBeNull()
    expect(rateWarning([currency('USD', 'ok')], null)).toBeNull()
  })

  it('names the missing currencies, and links to the first', () => {
    const warning = rateWarning([currency('GBP', 'ok'), currency('KPW', 'missing'), currency('XOF', 'missing')], null)
    expect(warning).toMatchObject({ missing: ['KPW', 'XOF'], stale: [], first: 'KPW' })
  })

  it('shows stale rates until dismissed for the days they stopped on', () => {
    const currencies = [currency('USD', 'stale', '2026-10-20'), currency('GBP', 'stale', '2026-10-18')]
    const warning = rateWarning(currencies, null)
    expect(warning).toMatchObject({ missing: [], stale: ['USD', 'GBP'], staleSince: '2026-10-18', first: 'USD' })

    expect(rateWarning(currencies, warning!.signature)).toBeNull()
    expect(rateWarning([currency('USD', 'stale', '2026-10-20'), currency('GBP', 'stale', '2026-10-17')], warning!.signature), 'older still: back').not.toBeNull()
  })

  it('keeps stale rates in view alongside missing ones, which cannot be dismissed', () => {
    const currencies = [currency('USD', 'stale', '2026-10-20'), currency('KPW', 'missing')]
    const signature = rateWarning(currencies, null)!.signature
    expect(rateWarning(currencies, signature)).toMatchObject({ missing: ['KPW'], stale: ['USD'], first: 'KPW' })
  })
})
