import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Download, TriangleAlert } from 'lucide-react'
import AdminTabs from '@/components/layout/AdminTabs'
import PageHeader from '@/components/PageHeader'
import SettingRow from '@/components/SettingRow'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useBackups, useCreateBackup, useUpdateBackupSettings } from '@/hooks/use-admin'
import { adminApi } from '@/lib/admin-api'
import { handleApiError } from '@/lib/error-handler'
import { formatDateTime, formatTime24h } from '@/lib/utils'
import type { BackupFile } from '@/types/admin'
import { Skeleton } from '@/components/ui/skeleton'

/** Hours between two backups: what the server accepts (BackupSettings::INTERVALS). */
const INTERVALS = [6, 12, 24, 48, 168]
const MAX_RETENTION_DAYS = 365

function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function BackupSettingsCard() {
  const { t } = useTranslation()
  const { data: overview, isLoading } = useBackups()
  const update = useUpdateBackupSettings()

  const [enabled, setEnabled] = useState(true)
  const [intervalHours, setIntervalHours] = useState(24)
  const [retentionDays, setRetentionDays] = useState('14')

  useEffect(() => {
    if (overview) {
      setEnabled(overview.enabled)
      setIntervalHours(overview.intervalHours)
      setRetentionDays(String(overview.retentionDays))
    }
  }, [overview])

  const days = Number(retentionDays)
  const valid = Number.isInteger(days) && days >= 1 && days <= MAX_RETENTION_DAYS
  const dirty =
    !!overview &&
    (enabled !== overview.enabled || (enabled && (intervalHours !== overview.intervalHours || days !== overview.retentionDays)))

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (!overview) return
    try {
      // Switched off, the other two stay as they were.
      await update.mutateAsync({
        enabled,
        intervalHours: enabled ? intervalHours : overview.intervalHours,
        retentionDays: enabled ? days : overview.retentionDays,
      })
      toast.success(t('admin.backups.settingsSaved'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('admin.backups.automatic')}</CardTitle>
        <CardDescription>{t('admin.backups.automaticDescription')}</CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-24 w-full" />
        ) : (
          <form onSubmit={handleSubmit}>
            <div className="divide-y">
              <SettingRow label={t('admin.backups.enabled')} htmlFor="backupEnabled">
                <Switch id="backupEnabled" checked={enabled} onCheckedChange={setEnabled} />
              </SettingRow>

              {enabled && (
                <>
                  <SettingRow label={t('admin.backups.interval')}>
                    <Select value={String(intervalHours)} onValueChange={(value) => setIntervalHours(Number(value))}>
                      <SelectTrigger className="sm:w-44">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {INTERVALS.map((hours) => (
                          <SelectItem key={hours} value={String(hours)}>
                            {t(`admin.backups.every.h${hours}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </SettingRow>

                  <SettingRow label={t('admin.backups.retention')} htmlFor="retentionDays">
                    <div className="flex items-center gap-2">
                      <Input
                        id="retentionDays"
                        type="number"
                        min={1}
                        max={MAX_RETENTION_DAYS}
                        step={1}
                        inputMode="numeric"
                        className="w-24"
                        value={retentionDays}
                        onChange={(e) => setRetentionDays(e.target.value)}
                      />
                      <span className="text-sm text-muted-foreground">{t('admin.backups.days')}</span>
                    </div>
                  </SettingRow>
                </>
              )}
            </div>

            <div className="mt-6 flex justify-end">
              <Button type="submit" disabled={update.isPending || !dirty || (enabled && !valid)}>
                {t('common.save')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}

function BackupRow({ file }: { file: BackupFile }) {
  const { t } = useTranslation()
  const [downloading, setDownloading] = useState(false)

  const download = async () => {
    setDownloading(true)
    try {
      await adminApi.downloadBackup(file.name)
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <TableRow>
      <TableCell className="whitespace-nowrap">
        {file.createdAt ? `${formatDateTime(file.createdAt)} · ${formatTime24h(file.createdAt)}` : file.name}
      </TableCell>
      <TableCell className="hidden text-muted-foreground sm:table-cell">{file.trigger ? t(`admin.backups.trigger.${file.trigger}`) : '—'}</TableCell>
      <TableCell className="hidden text-muted-foreground sm:table-cell">{file.appVersion ?? '—'}</TableCell>
      <TableCell className="whitespace-nowrap text-right tabular-nums text-muted-foreground">{formatSize(file.size)}</TableCell>
      <TableCell className="text-right">
        <Button variant="ghost" size="icon" onClick={download} disabled={downloading} aria-label={t('admin.backups.download')} title={t('admin.backups.download')}>
          <Download className="h-4 w-4" />
        </Button>
      </TableCell>
    </TableRow>
  )
}

export default function AdminBackups() {
  const { t } = useTranslation()
  const { data: overview, isLoading } = useBackups()
  const create = useCreateBackup()

  const backupNow = async () => {
    try {
      await create.mutateAsync()
      toast.success(t('admin.backups.created'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader title={t('admin.backups.title')} description={t('admin.backups.description')} />
      <AdminTabs />

      {overview && !overview.onVolume && (
        <div className="flex gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>{t('admin.backups.notOnVolume')}</p>
        </div>
      )}

      <BackupSettingsCard />

      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
          <div className="space-y-1.5">
            <CardTitle>{t('admin.backups.saved')}</CardTitle>
            <CardDescription>{t('admin.backups.savedDescription')}</CardDescription>
          </div>
          <Button onClick={backupNow} disabled={create.isPending} className="shrink-0">
            {create.isPending ? t('admin.backups.creating') : t('admin.backups.now')}
          </Button>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-48 w-full rounded-xl" />
          ) : overview && overview.backups.length > 0 ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('admin.backups.date')}</TableHead>
                  <TableHead className="hidden sm:table-cell">{t('admin.backups.kind')}</TableHead>
                  <TableHead className="hidden sm:table-cell">{t('admin.backups.version')}</TableHead>
                  <TableHead className="text-right">{t('admin.backups.size')}</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {overview.backups.map((file) => (
                  <BackupRow key={file.name} file={file} />
                ))}
              </TableBody>
            </Table>
          ) : (
            <div className="py-12 text-center text-sm text-muted-foreground">{t('admin.backups.none')}</div>
          )}
        </CardContent>
      </Card>

      <p className="text-sm text-muted-foreground">{t('admin.backups.restoreHint')}</p>
    </div>
  )
}
