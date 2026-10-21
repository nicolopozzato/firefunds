export interface ServerSettings {
  registrationEnabled: boolean
}

export interface AdminUser {
  id: string
  firstName: string
  lastName: string
  email: string
  isAdmin: boolean
  createdAt: string
}

export interface AdminCurrency {
  id: string
  code: string
  name: string
  symbol: string
  decimalPlaces: number
  isEnabled: boolean
}

export interface CurrencyRequest {
  code: string
  name: string
  symbol: string
  decimalPlaces: number
  isEnabled: boolean
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
