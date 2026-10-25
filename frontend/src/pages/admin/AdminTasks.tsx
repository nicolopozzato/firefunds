import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ChevronDown, Play } from 'lucide-react'
import AdminTabs from '@/components/layout/AdminTabs'
import PageHeader from '@/components/PageHeader'
import Pagination from '@/components/Pagination'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useRunTask, useTaskRuns, useTasks } from '@/hooks/use-admin'
import { handleApiError } from '@/lib/error-handler'
import { describeCron, runSummary } from '@/lib/task-runs'
import { cn, formatDateTime, formatTime24h } from '@/lib/utils'
import type { DataStatus, ScheduledTask, TaskRun, TaskRunStatus } from '@/types/admin'

const RUN_TONES: Record<TaskRunStatus, string> = {
  running: 'bg-info/10 text-info',
  success: 'bg-positive/10 text-positive',
  partial: 'bg-warning/10 text-warning',
  failed: 'bg-negative/10 text-negative',
  interrupted: 'bg-negative/10 text-negative',
}
const DATA_TONES: Record<DataStatus, string> = {
  ok: 'bg-positive/10 text-positive',
  stale: 'bg-warning/10 text-warning',
  missing: 'bg-negative/10 text-negative',
}
const RUNS_PER_PAGE = 10
/** Problems first, then alphabetical. */
const DATA_ORDER: Record<DataStatus, number> = { missing: 0, stale: 1, ok: 2 }

function Pill({ tone, children }: { tone: string; children: ReactNode }) {
  return <span className={cn('inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', tone)}>{children}</span>
}

function instant(value: string): string {
  return `${formatDateTime(value)} · ${formatTime24h(value)}`
}

function RunEntry({ task, run }: { task: ScheduledTask; run: TaskRun }) {
  const { t } = useTranslation()
  const summary = runSummary(task.key, run)

  return (
    <li className="space-y-1 py-3 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
        <span>
          {run.repeats > 1 && run.firstStartedAt
            ? t('admin.tasks.repeated', { count: run.repeats, since: instant(run.firstStartedAt), last: formatTime24h(run.startedAt) })
            : instant(run.startedAt)}
        </span>
        <span>· {t(`admin.tasks.trigger_${run.trigger}`)}</span>
        <Pill tone={RUN_TONES[run.status]}>{t(`admin.tasks.status_${run.status}`)}</Pill>
      </div>
      {summary && <p className="text-sm">{t(`admin.tasks.summary.${summary.key}`, summary.params)}</p>}
      {run.error && <pre className="whitespace-pre-wrap break-words rounded-md bg-muted px-2 py-1.5 font-mono text-xs text-muted-foreground">{run.error}</pre>}
    </li>
  )
}

/** A task's runs, newest first, page by page: loaded only once opened. */
function RunLog({ task }: { task: ScheduledTask }) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const [page, setPage] = useState(1)
  const { data, isLoading } = useTaskRuns(task.key, page, RUNS_PER_PAGE, isOpen)

  return (
    <details className="group rounded-md border" onToggle={(event) => setIsOpen(event.currentTarget.open)}>
      <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm font-medium text-muted-foreground [&::-webkit-details-marker]:hidden">
        {t('admin.tasks.log')}
        <ChevronDown className="ml-auto h-4 w-4 transition-transform group-open:rotate-180" />
      </summary>
      <div className="border-t">
        <div className="px-3 py-3">
          {isLoading || !data ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : data.items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('admin.tasks.noRuns')}</p>
          ) : (
            <ul className="divide-y divide-border">
              {data.items.map((run) => (
                <RunEntry key={run.id} task={task} run={run} />
              ))}
            </ul>
          )}
        </div>
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />}
      </div>
    </details>
  )
}

function TaskCard({ task, timezone }: { task: ScheduledTask; timezone: string }) {
  const { t } = useTranslation()
  const runTask = useRunTask()
  const latest = task.latestRun
  const schedule = describeCron(task.cron)
  const isRunning = runTask.isPending || latest?.status === 'running'

  const runNow = async () => {
    try {
      const overview = await runTask.mutateAsync(task.key)
      const run = overview.tasks.find((other) => other.key === task.key)?.latestRun
      if (run?.status === 'failed') toast.error(t('admin.tasks.runFailed'))
      else toast.success(t('admin.tasks.runDone'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
        <div className="min-w-0 space-y-1.5">
          <CardTitle className="flex flex-wrap items-center gap-2">
            {t(`admin.tasks.task_${task.key}`)}
            {latest && <Pill tone={RUN_TONES[latest.status]}>{t(`admin.tasks.status_${latest.status}`)}</Pill>}
          </CardTitle>
          <CardDescription>{t(`admin.tasks.taskDescription_${task.key}`)}</CardDescription>
        </div>
        <Button variant="outline" size="sm" onClick={runNow} disabled={isRunning} className="shrink-0">
          <Play className="mr-2 h-4 w-4" />
          {isRunning ? t('admin.tasks.running') : t('admin.tasks.runNow')}
        </Button>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="space-y-1.5 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="shrink-0 text-muted-foreground">{t('admin.tasks.schedule')}</dt>
            <dd className="text-right">
              {schedule === null
                ? task.cron
                : schedule.kind === 'hourly'
                  ? t('admin.tasks.scheduleHourly', { minute: String(schedule.minute).padStart(2, '0') })
                  : t('admin.tasks.scheduleDaily', { times: schedule.times.join(', '), timezone })}
            </dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="shrink-0 text-muted-foreground">{t('admin.tasks.lastDone')}</dt>
            <dd className="text-right">{task.lastDoneAt ? instant(task.lastDoneAt) : t('admin.tasks.never')}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="shrink-0 text-muted-foreground">{t('admin.tasks.nextRun')}</dt>
            <dd className="text-right">{instant(task.nextRunAt)}</dd>
          </div>
        </dl>

        <RunLog task={task} />
      </CardContent>
    </Card>
  )
}

export default function AdminTasks() {
  const { t } = useTranslation()
  const { data: overview, isLoading } = useTasks()

  const symbols = [...(overview?.symbols ?? [])].sort((a, b) => DATA_ORDER[a.status] - DATA_ORDER[b.status] || a.symbol.localeCompare(b.symbol))
  const currencies = [...(overview?.currencies ?? [])].sort((a, b) => DATA_ORDER[a.status] - DATA_ORDER[b.status] || a.currency.localeCompare(b.currency))

  return (
    <div className="space-y-6">
      <AdminTabs />
      <PageHeader title={t('admin.tasks.title')} description={t('admin.tasks.description')} />

      {isLoading || !overview ? (
        <div className="py-12 text-center text-sm text-muted-foreground">{t('common.loading')}</div>
      ) : (
        <>
          <div className="grid items-start gap-6 lg:grid-cols-2">
            {overview.tasks.map((task) => (
              <TaskCard key={task.key} task={task} timezone={overview.timezone} />
            ))}
          </div>

          <div className="grid items-start gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{t('admin.tasks.prices')}</CardTitle>
                <CardDescription>{t('admin.tasks.pricesDescription')}</CardDescription>
              </CardHeader>
              <CardContent>
                {symbols.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">{t('admin.tasks.noSymbols')}</p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{t('admin.tasks.symbol')}</TableHead>
                        <TableHead>{t('admin.tasks.latestClose')}</TableHead>
                        <TableHead className="text-right">{t('admin.tasks.state')}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {symbols.map((row) => (
                        <TableRow key={row.symbol}>
                          <TableCell className="font-mono text-xs">{row.symbol}</TableCell>
                          <TableCell className="whitespace-nowrap">{row.latestClose ? formatDateTime(row.latestClose) : '—'}</TableCell>
                          <TableCell className="text-right">
                            <Pill tone={DATA_TONES[row.status]}>{t(`admin.tasks.price_${row.status}`)}</Pill>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('admin.tasks.rates')}</CardTitle>
                <CardDescription>{t('admin.tasks.ratesDescription')}</CardDescription>
              </CardHeader>
              <CardContent>
                {currencies.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">{t('admin.tasks.noCurrencies')}</p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{t('admin.tasks.currency')}</TableHead>
                        <TableHead>{t('admin.tasks.latestRate')}</TableHead>
                        <TableHead className="hidden sm:table-cell">{t('admin.tasks.published')}</TableHead>
                        <TableHead className="text-right">{t('admin.tasks.state')}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {currencies.map((row) => (
                        <TableRow key={row.currency}>
                          <TableCell className="font-medium">{row.currency}</TableCell>
                          <TableCell className="whitespace-nowrap">{row.latest ? formatDateTime(row.latest) : '—'}</TableCell>
                          <TableCell className="hidden whitespace-nowrap sm:table-cell">
                            {row.published ? `${formatDateTime(row.published)}${row.source ? ` · ${t(`exchangeRates.source_${row.source}`)}` : ''}` : '—'}
                          </TableCell>
                          <TableCell className="text-right">
                            <Pill tone={DATA_TONES[row.status]}>{t(`admin.tasks.rate_${row.status}`)}</Pill>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </div>
        </>
      )}
    </div>
  )
}
