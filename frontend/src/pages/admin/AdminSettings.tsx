import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import AdminTabs from '@/components/layout/AdminTabs'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import SettingRow from '@/components/SettingRow'
import { Switch } from '@/components/ui/switch'
import { useServerSettings, useUpdateServerSettings } from '@/hooks/use-admin'
import { handleApiError } from '@/lib/error-handler'
import { Skeleton } from '@/components/ui/skeleton'

export default function AdminSettings() {
  const { t } = useTranslation()
  const { data: settings, isLoading } = useServerSettings()
  const updateSettings = useUpdateServerSettings()

  const [registrationEnabled, setRegistrationEnabled] = useState(false)
  const [updateCheckEnabled, setUpdateCheckEnabled] = useState(true)

  useEffect(() => {
    if (settings) {
      setRegistrationEnabled(settings.registrationEnabled)
      setUpdateCheckEnabled(settings.updateCheckEnabled)
    }
  }, [settings])

  const handleToggle = async (checked: boolean) => {
    setRegistrationEnabled(checked)
    try {
      await updateSettings.mutateAsync({ registrationEnabled: checked, updateCheckEnabled })
      toast.success(t(checked ? 'admin.settings.registrationTurnedOn' : 'admin.settings.registrationTurnedOff'))
    } catch (error) {
      setRegistrationEnabled(!checked)
      toast.error(handleApiError(error))
    }
  }

  const handleUpdateCheckToggle = async (checked: boolean) => {
    setUpdateCheckEnabled(checked)
    try {
      await updateSettings.mutateAsync({ registrationEnabled, updateCheckEnabled: checked })
      toast.success(t(checked ? 'admin.settings.updateCheckTurnedOn' : 'admin.settings.updateCheckTurnedOff'))
    } catch (error) {
      setUpdateCheckEnabled(!checked)
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader title={t('admin.settings.title')} description={t('admin.settings.description')} />
      <AdminTabs />

      <Card>
        <CardHeader>
          <CardTitle>{t('admin.settings.registration')}</CardTitle>
          <CardDescription>{t('admin.settings.registrationDescription')}</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : (
            <SettingRow label={t('admin.settings.registrationEnabled')} htmlFor="registrationEnabled">
              <Switch id="registrationEnabled" checked={registrationEnabled} disabled={updateSettings.isPending} onCheckedChange={handleToggle} />
            </SettingRow>
          )}
        </CardContent>
      </Card>

      <p className="text-xs text-muted-foreground">{t('admin.settings.registrationHint')}</p>

      <Card>
        <CardHeader>
          <CardTitle>{t('admin.settings.updates')}</CardTitle>
          <CardDescription>{t('admin.settings.updatesDescription')}</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : (
            <SettingRow label={t('admin.settings.updateCheckEnabled')} hint={t('admin.settings.updateCheckHint')} htmlFor="updateCheckEnabled">
              <Switch id="updateCheckEnabled" checked={updateCheckEnabled} disabled={updateSettings.isPending} onCheckedChange={handleUpdateCheckToggle} />
            </SettingRow>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
