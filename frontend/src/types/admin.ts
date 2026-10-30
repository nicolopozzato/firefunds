export interface ServerSettings {
  registrationEnabled: boolean
  /** Whether the server asks GitHub for new versions (the `updates` task). */
  updateCheckEnabled: boolean
}

/** A published FireFunds version: "0.10.0", its release notes on GitHub. */
export interface Release {
  version: string
  url: string
  publishedAt: string | null
}

/** What the last check for new versions found (GET /api/admin/update). */
export interface UpdateStatus {
  enabled: boolean
  /** The version the server runs: "dev" on a development build. */
  currentVersion: string
  latest: Release | null
  updateAvailable: boolean
  checkedAt: string | null
}

export interface AdminUser {
  id: string
  firstName: string
  lastName: string
  email: string
  isAdmin: boolean
  isActive: boolean
  /** UTC instant; null while the user is active. */
  disabledAt: string | null
  /** The password was set by an administrator, to be changed at the next login. */
  mustChangePassword: boolean
  /** UTC instant of the last use of the app (to the quarter of an hour); null: never. */
  lastSeenAt: string | null
  createdAt: string
}

export interface AdminUserFields {
  firstName: string
  lastName: string
  email: string
  isAdmin: boolean
}

export interface CreateUserRequest extends AdminUserFields {
  password: string
  mustChangePassword: boolean
}

export interface SetPasswordRequest {
  password: string
  mustChangePassword: boolean
}

export interface BackupFile {
  name: string
  size: number
  /** UTC instant; null when the archive's manifest can't be read. */
  createdAt: string | null
  appVersion: string | null
  trigger: 'scheduled' | 'manual' | 'before_restore' | null
}

export interface BackupSettings {
  enabled: boolean
  intervalHours: number
  retentionDays: number
}

export interface BackupsOverview extends BackupSettings {
  /** The version of FireFunds running now. */
  appVersion: string
  /** False when the backups directory isn't a volume: they would vanish with the container. */
  onVolume: boolean
  backups: BackupFile[]
}

export type TaskKey = 'prices' | 'rates' | 'backup' | 'cleanup' | 'recurring' | 'updates'
export type TaskRunStatus = 'running' | 'success' | 'partial' | 'failed' | 'interrupted'

export interface TaskRun {
  id: string
  trigger: 'schedule' | 'retry' | 'manual'
  /** partial: done, but some of it couldn't be; interrupted: the process stopped before it finished. */
  status: TaskRunStatus
  /** UTC instants. */
  startedAt: string
  finishedAt: string | null
  /** What the task reports (counts, symbols, currencies): worded by lib/task-runs.ts. */
  details: Record<string, unknown>
  /** The technical reason of a failure, untranslated. */
  error: string | null
  /** Runs in a row with nothing to do kept as one: how many, since when. */
  repeats: number
  firstStartedAt: string | null
}

export interface ScheduledTask {
  key: TaskKey
  /** Cron expression, in the overview's timezone. */
  cron: string
  nextRunAt: string
  /** When it last finished with something done (success or partial). */
  lastDoneAt: string | null
  /** The newest run; the others page by page. */
  latestRun: TaskRun | null
}

export type DataStatus = 'ok' | 'stale' | 'missing'

export interface SymbolStatus {
  symbol: string
  latest: string | null
  /** The newest day with the provider's own close (not carried over). */
  latestClose: string | null
  status: DataStatus
}

export interface CurrencyStatus {
  currency: string
  latest: string | null
  /** The newest day a provider published a rate, and which. */
  published: string | null
  source: 'ecb' | 'yahoo' | null
  status: DataStatus
}

export interface TasksOverview {
  /** The server's market timezone: the tasks' times are in it. */
  timezone: string
  tasks: ScheduledTask[]
  symbols: SymbolStatus[]
  currencies: CurrencyStatus[]
}
