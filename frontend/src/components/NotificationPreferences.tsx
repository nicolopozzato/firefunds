import { useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { BellRing, Send, Smartphone, Trash2 } from 'lucide-react'
import SettingRow from '@/components/SettingRow'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { usePushDevices, useRemovePushDevice, useTestPushDevice } from '@/hooks/use-notifications'
import { useSavePreferences } from '@/hooks/use-preferences'
import { handleApiError } from '@/lib/error-handler'
import { currentSubscription, disablePush, enablePush, pushSupport } from '@/lib/push'
import { formatShortDate } from '@/lib/utils'
import type { UpdatePreferencesRequest, User } from '@/types/auth'

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  )
}

/**
 * Preferences → Notifications: push on this device (or why it can't be),
 * what to receive and when, and every device that receives them.
 */
export default function NotificationPreferences({ user }: { user: User }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const savePreferences = useSavePreferences()
  const { data: devices } = usePushDevices()
  const removeDevice = useRemovePushDevice()
  const testDevice = useTestPushDevice()
  const [support, setSupport] = useState(pushSupport)
  const [endpoint, setEndpoint] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [summaryTime, setSummaryTime] = useState(user.pushSummaryTime)

  useEffect(() => {
    void currentSubscription().then((subscription) => setEndpoint(subscription?.endpoint ?? null))
  }, [])
  // On here only when the server knows this device too (it may have been removed from another one).
  const thisDevice = devices?.find((device) => device.endpoint === endpoint) ?? null

  const toggleHere = async () => {
    setBusy(true)
    try {
      if (thisDevice) {
        await disablePush()
      } else {
        await enablePush()
        toast.success(t('pushPrefs.enabled'))
      }
      const subscription = await currentSubscription()
      setEndpoint(subscription?.endpoint ?? null)
      await queryClient.invalidateQueries({ queryKey: ['push-devices'] })
    } catch (error) {
      setSupport(pushSupport())
      toast.error(error instanceof Error && error.message === 'permission' ? t('pushPrefs.permissionRefused') : handleApiError(error))
    } finally {
      setBusy(false)
    }
  }

  const save = async (changes: UpdatePreferencesRequest) => {
    try {
      await savePreferences(changes)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const test = async (id: string) => {
    try {
      await testDevice.mutateAsync(id)
      toast.success(t('pushPrefs.testSent'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <Section title={t('pushPrefs.thisDevice')} description={t('pushPrefs.thisDeviceDescription')}>
        {support === 'ok' ? (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                <BellRing className="h-4 w-4" />
              </span>
              <span className="text-sm">{t(thisDevice ? 'pushPrefs.onHere' : 'pushPrefs.offHere')}</span>
            </div>
            <Button variant={thisDevice ? 'outline' : 'default'} disabled={busy} onClick={() => void toggleHere()}>
              {t(thisDevice ? 'pushPrefs.turnOff' : 'pushPrefs.turnOn')}
            </Button>
          </div>
        ) : (
          <p className="rounded-lg bg-muted/50 px-3 py-2.5 text-sm">{t(`pushPrefs.support_${support}`)}</p>
        )}
      </Section>

      <Section title={t('pushPrefs.what')}>
        <div className="divide-y">
          <SettingRow label={t('pushPrefs.reminders')} hint={t('pushPrefs.remindersHint')} htmlFor="pushReminders">
            <Switch id="pushReminders" checked={user.pushReminders} onCheckedChange={(on) => void save({ pushReminders: on })} />
          </SettingRow>
          {user.pushReminders && (
            <SettingRow label={t('pushPrefs.summaryTime')} hint={t('pushPrefs.summaryTimeHint')} htmlFor="pushSummaryTime">
              <Input
                id="pushSummaryTime"
                type="time"
                className="w-32"
                value={summaryTime}
                onChange={(e) => setSummaryTime(e.target.value)}
                onBlur={() => summaryTime && summaryTime !== user.pushSummaryTime && void save({ pushSummaryTime: summaryTime })}
              />
            </SettingRow>
          )}
          {user.isAdmin && (
            <SettingRow label={t('pushPrefs.system')} hint={t('pushPrefs.systemHint')} htmlFor="pushSystem">
              <Switch id="pushSystem" checked={user.pushSystem} onCheckedChange={(on) => void save({ pushSystem: on })} />
            </SettingRow>
          )}
        </div>
      </Section>

      <Section title={t('pushPrefs.devices')} description={t('pushPrefs.devicesDescription')}>
        {devices && devices.length > 0 ? (
          <ul className="divide-y">
            {devices.map((device) => (
              <li key={device.id} className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                <Smartphone className="h-4 w-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 text-sm font-medium">
                    <span className="truncate">{device.label}</span>
                    {device.id === thisDevice?.id && <Badge variant="info" className="shrink-0 font-normal">{t('pushPrefs.thisOne')}</Badge>}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {t('pushPrefs.addedOn', { date: formatShortDate(device.createdAt) })}
                    {device.lastSentAt && ` · ${t('pushPrefs.lastSent', { date: formatShortDate(device.lastSentAt) })}`}
                  </div>
                </div>
                <div className="flex gap-1">
                  <Button variant="ghost" size="sm" disabled={testDevice.isPending} onClick={() => void test(device.id)}>
                    <Send className="mr-1.5 h-4 w-4" />
                    {t('pushPrefs.test')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('pushPrefs.remove')}
                    title={t('pushPrefs.remove')}
                    onClick={() =>
                      removeDevice.mutate(device.id, {
                        onSuccess: () => device.id === thisDevice?.id && void disablePush().then(() => setEndpoint(null)),
                      })
                    }
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{t('pushPrefs.noDevices')}</p>
        )}
      </Section>
    </div>
  )
}
