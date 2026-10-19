import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { useServerSettings, useUpdateServerSettings } from '@/hooks/use-admin'
import { handleApiError } from '@/lib/error-handler'

export default function AdminSettings() {
  const { t } = useTranslation()
  const { data: settings, isLoading } = useServerSettings()
  const updateSettings = useUpdateServerSettings()

  const [registrationEnabled, setRegistrationEnabled] = useState(false)

  useEffect(() => {
    if (settings) {
      setRegistrationEnabled(settings.registrationEnabled)
    }
  }, [settings])

  const handleToggle = async (checked: boolean) => {
    setRegistrationEnabled(checked)
    try {
      await updateSettings.mutateAsync({ registrationEnabled: checked })
      toast.success(t(checked ? 'admin.settings.registrationTurnedOn' : 'admin.settings.registrationTurnedOff'))
    } catch (error) {
      setRegistrationEnabled(!checked)
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('admin.settings.title')} description={t('admin.settings.description')} />

      <Card>
        <CardHeader>
          <CardTitle>{t('admin.settings.registration')}</CardTitle>
          <CardDescription>{t('admin.settings.registrationDescription')}</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">{t('common.loading')}</p>
          ) : (
            <div className="flex items-center gap-2">
              <input
                id="registrationEnabled"
                type="checkbox"
                className="h-4 w-4 rounded border-input"
                checked={registrationEnabled}
                disabled={updateSettings.isPending}
                onChange={(e) => handleToggle(e.target.checked)}
              />
              <Label htmlFor="registrationEnabled">{t('admin.settings.registrationEnabled')}</Label>
            </div>
          )}
        </CardContent>
      </Card>

      <p className="text-xs text-muted-foreground">{t('admin.settings.registrationHint')}</p>
    </div>
  )
}
