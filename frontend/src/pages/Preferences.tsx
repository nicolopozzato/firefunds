import { useMemo, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import Combobox from '@/components/Combobox'
import DashboardPreferences from '@/components/DashboardPreferences'
import { useAuth } from '@/contexts/AuthContext'
import { authApi } from '@/lib/auth-api'
import { cn, numberFormatter } from '@/lib/utils'
import type { NumberFormat, UserTheme } from '@/types/auth'

const LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'it', label: 'Italiano' },
]

const PAGE_SIZES = [10, 25, 50, 100]

const NUMBER_FORMATS: NumberFormat[] = ['it-IT', 'en-US', 'de-CH', 'fr-FR']

/** Each option is labelled by what it produces, e.g. "1.234,56". */
function numberFormatExample(format: NumberFormat): string {
  return numberFormatter({ minimumFractionDigits: 2 }, format).format(1234.56)
}

/** All IANA timezone identifiers the browser knows about, e.g. "Europe/Rome". */
function listTimezones(): string[] {
  try {
    const intlWithSupportedValues = Intl as typeof Intl & { supportedValuesOf?: (key: string) => string[] }
    return intlWithSupportedValues.supportedValuesOf?.('timeZone') ?? [Intl.DateTimeFormat().resolvedOptions().timeZone]
  } catch {
    return [Intl.DateTimeFormat().resolvedOptions().timeZone]
  }
}

function GeneralPreferences() {
  const { t, i18n } = useTranslation()
  const { user, refreshUser } = useAuth()

  const [locale, setLocale] = useState(user?.locale ?? 'en')
  const [theme, setTheme] = useState<UserTheme>(user?.theme ?? 'system')
  const [pageSize, setPageSize] = useState(user?.pageSize ?? 50)
  const [timezone, setTimezone] = useState(user?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone)
  const [numberFormat, setNumberFormat] = useState<NumberFormat>(user?.numberFormat ?? 'it-IT')
  const [capitalGainsTaxRate, setCapitalGainsTaxRate] = useState(String(Number(user?.capitalGainsTaxRate ?? '26')))
  const [isSaving, setIsSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  const timezoneOptions = useMemo(
    () => listTimezones().map((tz) => ({ value: tz, label: tz })),
    []
  )

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setIsSaving(true)
    setSaved(false)

    try {
      await authApi.updatePreferences({
        locale,
        theme,
        pageSize,
        timezone,
        numberFormat,
        capitalGainsTaxRate: capitalGainsTaxRate || undefined,
      })
      i18n.changeLanguage(locale)
      await refreshUser()
      setSaved(true)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('preferences.general')}</CardTitle>
        <CardDescription>{t('preferences.generalDescription')}</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>{t('preferences.language')}</Label>
              <Select value={locale} onValueChange={setLocale}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {LANGUAGES.map((lang) => (
                    <SelectItem key={lang.code} value={lang.code}>
                      {lang.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('preferences.numberFormat')}</Label>
              <Select value={numberFormat} onValueChange={(value) => setNumberFormat(value as NumberFormat)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {NUMBER_FORMATS.map((format) => (
                    <SelectItem key={format} value={format}>
                      {numberFormatExample(format)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('preferences.theme')}</Label>
              <Select value={theme} onValueChange={(value) => setTheme(value as UserTheme)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="light">{t('preferences.themeLight')}</SelectItem>
                  <SelectItem value="dark">{t('preferences.themeDark')}</SelectItem>
                  <SelectItem value="system">{t('preferences.themeSystem')}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('preferences.pageSize')}</Label>
              <Select value={String(pageSize)} onValueChange={(value) => setPageSize(Number(value))}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PAGE_SIZES.map((size) => (
                    <SelectItem key={size} value={String(size)}>
                      {size}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('preferences.timezone')}</Label>
              <Combobox
                options={timezoneOptions}
                value={timezone}
                onChange={setTimezone}
                placeholder={t('preferences.selectTimezone')}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="capitalGainsTaxRate">{t('preferences.capitalGainsTaxRate')}</Label>
              <Input
                id="capitalGainsTaxRate"
                type="number"
                step="0.01"
                min="0"
                max="100"
                value={capitalGainsTaxRate}
                onChange={(e) => setCapitalGainsTaxRate(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">{t('preferences.capitalGainsTaxRateHint')}</p>
            </div>
          </div>

          {saved && <p className="text-sm text-positive">{t('preferences.saved')}</p>}

          <div className="flex justify-end">
            <Button type="submit" disabled={isSaving}>
              {t('common.save')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

type PreferencesTab = 'general' | 'dashboard'

export default function Preferences() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const tab: PreferencesTab = searchParams.get('tab') === 'dashboard' ? 'dashboard' : 'general'

  const tabs: { value: PreferencesTab; label: string }[] = [
    { value: 'general', label: t('preferences.general') },
    { value: 'dashboard', label: t('preferences.dashboard') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader title={t('preferences.title')} description={t('preferences.description')} />

      <div role="tablist" className="flex gap-1 border-b">
        {tabs.map((option) => (
          <button
            key={option.value}
            type="button"
            role="tab"
            aria-selected={tab === option.value}
            onClick={() => setSearchParams(option.value === 'general' ? {} : { tab: option.value }, { replace: true })}
            className={cn(
              '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors',
              tab === option.value
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )}
          >
            {option.label}
          </button>
        ))}
      </div>

      {tab === 'general' ? <GeneralPreferences /> : <DashboardPreferences />}
    </div>
  )
}
