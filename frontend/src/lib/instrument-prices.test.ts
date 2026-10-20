import { describe, expect, it } from 'vitest'
import { changesFromPrevious } from './instrument-prices'
import type { InvestmentPrice } from '@/types/investment'

const price = (date: string, value: string, source: InvestmentPrice['source'] = 'market'): InvestmentPrice => ({
  date,
  price: value,
  source,
  originalPrice: source === 'manual' ? null : value,
})

describe('changesFromPrevious', () => {
  it('compares each day with the last price of its own, not a carried-over one', () => {
    const changes = changesFromPrevious([
      price('2026-10-02', '100'),
      price('2026-10-03', '100', 'filled'),
      price('2026-10-04', '100', 'filled'),
      price('2026-10-05', '102'),
      price('2026-10-06', '101', 'manual'),
    ])

    expect(changes.has('2026-10-02')).toBe(false)
    expect(changes.get('2026-10-03')).toEqual({ amount: 0, percent: 0 })
    expect(changes.get('2026-10-05')).toEqual({ amount: 2, percent: 2 })
    expect(changes.get('2026-10-06')?.amount).toBe(-1)
    expect(changes.get('2026-10-06')?.percent).toBeCloseTo(-0.9804, 4)
  })
})
