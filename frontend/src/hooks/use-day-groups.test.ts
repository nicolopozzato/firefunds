import { describe, expect, it } from 'vitest'
import { groupByDay } from './use-day-groups'

describe('groupByDay', () => {
  it('groups by the day written, keeping the order', () => {
    const items = [
      { id: 1, at: '2026-10-02T09:00:00' },
      { id: 2, at: '2026-10-01T23:30:00' },
      { id: 3, at: '2026-10-01T00:30:00' },
    ]

    const groups = groupByDay(items, (item) => item.at)

    expect(groups.map((group) => [group.day, group.items.map((item) => item.id)])).toEqual([
      ['2026-10-02', [1]],
      ['2026-10-01', [2, 3]],
    ])
  })
})
