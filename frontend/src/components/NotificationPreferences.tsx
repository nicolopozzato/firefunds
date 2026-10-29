import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
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
import { usePushHere } from '@/hooks/use-push-here'
import { handleApiError } from '@/lib/error-handler'
import { disablePush } from '@/lib/push'
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
  const savePreferences = useSavePreferences()
  const { data: devices } = usePushDevices()
  const removeDevice = useRemovePushDevice()
  const testDevice = useTestPushDevice()
  const push = usePushHere()
  const thisDevice = push.device
  const [summaryTime, setSummaryTime] = useState(user.pushSummaryTime)

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
        {push.support === 'ok' ? (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                <BellRing className="h-4 w-4" />
              </span>
              <span className="text-sm">{t(thisDevice ? 'pushPrefs.onHere' : 'pushPrefs.offHere')}</span>
            </div>
            <Button variant={thisDevice ? 'outline' : 'default'} disabled={push.busy || !push.known} onClick={() => void (thisDevice ? push.disable() : push.enable())}>
              {t(thisDevice ? 'pushPrefs.turnOff' : 'pushPrefs.turnOn')}
            </Button>
          </div>
        ) : (
          <p className="rounded-lg bg-muted/50 px-3 py-2.5 text-sm">{t(`pushPrefs.support_${push.support}`)}</p>
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
          <SettingRow label={t('pushPrefs.drafts')} hint={t('pushPrefs.draftsHint')} htmlFor="pushDrafts">
            <Switch id="pushDrafts" checked={user.pushDrafts} onCheckedChange={(on) => void save({ pushDrafts: on })} />
          </SettingRow>
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
                        onSuccess: () => device.id === thisDevice?.id && void disablePush().then(push.refresh),
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
