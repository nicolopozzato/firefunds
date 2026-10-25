import { describe, expect, it } from 'vitest'
import { describeCron, runSummary } from './task-runs'
import type { TaskRun } from '@/types/admin'

const run = (details: Record<string, unknown>, status: TaskRun['status'] = 'success'): TaskRun => ({
  id: '1',
  trigger: 'schedule',
  status,
  startedAt: '2026-10-25T23:10:00+00:00',
  finishedAt: '2026-10-25T23:10:30+00:00',
  details,
  error: null,
  repeats: 1,
  firstStartedAt: null,
})

describe('runSummary', () => {
  it('words what each task reported', () => {
    expect(runSummary('prices', run({ symbols: 14, empty: [], failed: [] }))).toEqual({ key: 'pricesDone', params: { count: 14 } })
    expect(runSummary('prices', run({ symbols: 14, empty: ['XYZ.MI'], failed: ['ABC'] }, 'partial'))).toEqual({
      key: 'pricesNotUpdated',
      params: { count: 2, total: 14, symbols: 'XYZ.MI, ABC' },
    })
    expect(runSummary('rates', run({ currencies: [], unresolved: [] }))).toEqual({ key: 'ratesNone' })
    expect(runSummary('rates', run({ currencies: ['USD', 'KPW'], unresolved: ['KPW'] }, 'partial'))).toEqual({
      key: 'ratesNotFound',
      params: { count: 1, currencies: 'KPW' },
    })
    expect(runSummary('backup', run({ skipped: 'not_due' }))).toEqual({ key: 'backupNotDue' })
    expect(runSummary('backup', run({ file: 'firefunds_x.tar', size: 10, deleted: 2 }))).toEqual({ key: 'backupDone', params: { file: 'firefunds_x.tar', count: 2 } })
    expect(runSummary('cleanup', run({ revoked: 0, runs: 0 }))).toEqual({ key: 'cleanupNone' })
    expect(runSummary('cleanup', run({ revoked: 3, runs: 40 }))).toEqual({ key: 'cleanupDone', params: { sessions: 3, runs: 40 } })
  })

  it('has nothing to say for a run still going, cut off, or failed before reporting', () => {
    expect(runSummary('prices', run({}, 'running'))).toBeNull()
    expect(runSummary('prices', run({ symbols: 1 }, 'interrupted'))).toBeNull()
    expect(runSummary('backup', run({}, 'failed'))).toBeNull()
  })
})

describe('describeCron', () => {
  it('reads the shapes the tasks use', () => {
    expect(describeCron('5 * * * *')).toEqual({ kind: 'hourly', minute: 5 })
    expect(describeCron('10 0,4,8,12 * * *')).toEqual({ kind: 'daily', times: ['00:10', '04:10', '08:10', '12:10'] })
    expect(describeCron('30 3 * * *')).toEqual({ kind: 'daily', times: ['03:30'] })
  })

  it('leaves anything else alone', () => {
    expect(describeCron('*/15 * * * *')).toBeNull()
    expect(describeCron('0 9 * * 1')).toBeNull()
  })
})
