import type { TaskKey, TaskRun } from '@/types/admin'

/** A translation key under `admin.tasks.summary` and what fills it. */
export interface RunSummary {
  key: string
  params?: Record<string, string | number>
}

const list = (value: unknown): string[] => (Array.isArray(value) ? value.map(String) : [])
const count = (value: unknown): number => (typeof value === 'number' ? value : 0)

/**
 * What a run did, in words, from what the task reported (`details`): null
 * for a run with nothing to say (still running, or failed before reporting:
 * its error says why).
 */
export function runSummary(task: TaskKey, run: TaskRun): RunSummary | null {
  const details = run.details
  if (run.status === 'running' || run.status === 'interrupted' || Object.keys(details).length === 0) return null

  switch (task) {
    case 'prices': {
      const symbols = count(details.symbols)
      const notUpdated = [...list(details.empty), ...list(details.failed)]
      if (symbols === 0) return { key: 'pricesNone' }
      return notUpdated.length === 0
        ? { key: 'pricesDone', params: { count: symbols } }
        : { key: 'pricesNotUpdated', params: { count: notUpdated.length, total: symbols, symbols: notUpdated.join(', ') } }
    }
    case 'rates': {
      const currencies = list(details.currencies)
      const unresolved = list(details.unresolved)
      if (currencies.length === 0) return { key: 'ratesNone' }
      return unresolved.length === 0
        ? { key: 'ratesDone', params: { currencies: currencies.join(', ') } }
        : { key: 'ratesNotFound', params: { count: unresolved.length, currencies: unresolved.join(', ') } }
    }
    case 'backup':
      if (details.skipped === 'off') return { key: 'backupOff' }
      if (details.skipped === 'not_due') return { key: 'backupNotDue' }
      return { key: 'backupDone', params: { file: String(details.file ?? ''), count: count(details.deleted) } }
    case 'cleanup': {
      const revoked = count(details.revoked)
      const runs = count(details.runs)
      if (revoked === 0 && runs === 0) return { key: 'cleanupNone' }
      return { key: 'cleanupDone', params: { sessions: revoked, runs } }
    }
    case 'recurring': {
      const created = count(details.created)
      const failed = count(details.failed)
      const linked = count(details.linked)
      const reminded = count(details.reminded)
      if (failed > 0) return { key: 'recurringFailed', params: { count: failed, created } }
      if (created === 0 && linked === 0 && reminded === 0) return { key: 'recurringNone' }
      return { key: 'recurringDone', params: { count: created, linked, reminded } }
    }
  }
}

export type CronSchedule = { kind: 'every'; minutes: number } | { kind: 'hourly'; minute: number } | { kind: 'daily'; times: string[] }

/**
 * The tasks' cron expressions in words: "every 15 minutes", "every hour at
 * :05", "every day at 00:10, 04:10". Only the shapes the tasks use; anything
 * else stays as it is.
 */
export function describeCron(cron: string): CronSchedule | null {
  const [minute, hour, dayOfMonth, month, dayOfWeek, ...rest] = cron.trim().split(/\s+/)
  const every = /^\*\/(\d+)$/.exec(minute ?? '')
  if (every && hour === '*' && dayOfMonth === '*' && month === '*' && dayOfWeek === '*' && rest.length === 0) {
    return { kind: 'every', minutes: Number(every[1]) }
  }
  if (rest.length > 0 || dayOfMonth !== '*' || month !== '*' || dayOfWeek !== '*' || !/^\d+$/.test(minute ?? '')) return null
  if (hour === '*') return { kind: 'hourly', minute: Number(minute) }
  if (!/^\d+(,\d+)*$/.test(hour ?? '')) return null

  const pad = (value: number) => String(value).padStart(2, '0')
  return { kind: 'daily', times: hour.split(',').map((h) => `${pad(Number(h))}:${pad(Number(minute))}`) }
}
