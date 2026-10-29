import { useMemo, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import PageHeader from '@/components/PageHeader'
import SettingRow from '@/components/SettingRow'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import SegmentedControl from '@/components/ui/segmented-control'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import Combobox from '@/components/Combobox'
import { useCurrencyOptions } from '@/hooks/use-currencies'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useHideAmounts } from '@/hooks/use-hide-amounts'
import { useSavePreferences } from '@/hooks/use-preferences'
import DashboardPreferences from '@/components/DashboardPreferences'
import NotificationPreferences from '@/components/NotificationPreferences'
import ApiTokensPreferences from '@/components/ApiTokensPreferences'
import ExchangeRatesPanel from '@/components/ExchangeRatesPanel'
import { useExchangeRatesOverview } from '@/hooks/use-exchange-rates'
import { useAuth } from '@/contexts/AuthContext'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { DENSITIES, type Density } from '@/lib/theme'
import { numberFormatter } from '@/lib/utils'
import type { NumberFormat, StartPage, StartPeriod, UpdatePreferencesRequest, User, UserTheme } from '@/types/auth'

const LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'it', label: 'Italiano' },
]

const PAGE_SIZES = [10, 25, 50, 100]

const NUMBER_FORMATS: NumberFormat[] = ['it-IT', 'en-US', 'de-CH', 'fr-FR']

const START_PAGES: StartPage[] = ['dashboard', 'transactions', 'investments', 'reports']

const START_PERIODS: StartPeriod[] = ['last', 'currentMonth', 'last30Days', 'last60Days', 'currentYear']

/** Radix's Select can't hold an empty value: "no default account". */
const NO_ACCOUNT = 'none'

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

function SettingsCard({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>
        <div className="divide-y">{children}</div>
      </CardContent>
    </Card>
  )
}

/**
 * The preferences take effect as they are changed, each saved on its own:
 * the screen shows what is chosen at once, and goes back if saving fails.
 */
function GeneralPreferences({ user }: { user: User }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const savePreferences = useSavePreferences()
  const currencyOptions = useCurrencyOptions()
  const { data: assetAccounts } = useAllAccounts('asset')
  const [hideAmounts, setHideAmounts] = useHideAmounts()

  // What is on screen: the user's preferences, plus the change being saved.
  const [pending, setPending] = useState<UpdatePreferencesRequest>({})
  const shown = { ...user, defaultAccountId: user.defaultAccountId ?? '', ...pending }
  const [taxRate, setTaxRate] = useState(String(Number(user.capitalGainsTaxRate)))

  const timezoneOptions = useMemo(() => listTimezones().map((tz) => ({ value: tz, label: tz })), [])
  const accountOptions = (assetAccounts?.items ?? []).filter((account) => account.isActive)

  const save = async (changes: UpdatePreferencesRequest, { affectsData = false } = {}) => {
    setPending((current) => ({ ...current, ...changes }))
    try {
      await savePreferences(changes)
      if (changes.locale) void i18n.changeLanguage(changes.locale)
      // Rows per page, the tax rate, the timezone ("today"), the currency change what lists and totals show.
      if (affectsData) void dataChanged(queryClient)
    } catch (error) {
      toast.error(handleApiError(error))
    } finally {
      setPending((current) => {
        const next = { ...current }
        for (const key of Object.keys(changes)) delete next[key as keyof UpdatePreferencesRequest]
        return next
      })
    }
  }

  const saveTaxRate = () => {
    const rate = taxRate.trim()
    if (rate === '' || Number(rate) === Number(user.capitalGainsTaxRate)) {
      setTaxRate(String(Number(user.capitalGainsTaxRate)))
      return
    }
    void save({ capitalGainsTaxRate: rate }, { affectsData: true })
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <SettingsCard title={t('preferences.regional')}>
        <SettingRow label={t('preferences.language')} wide>
          <Select value={shown.locale} onValueChange={(locale) => void save({ locale })}>
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
        </SettingRow>

        <SettingRow label={t('preferences.timezone')} hint={t('preferences.timezoneHint')} wide>
          <Combobox
            options={timezoneOptions}
            value={shown.timezone}
            onChange={(timezone) => void save({ timezone }, { affectsData: true })}
            placeholder={t('preferences.selectTimezone')}
          />
        </SettingRow>

        <SettingRow label={t('preferences.numberFormat')} wide>
          <Select value={shown.numberFormat} onValueChange={(value) => void save({ numberFormat: value as NumberFormat })}>
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
        </SettingRow>

        <SettingRow label={t('preferences.currency')} hint={t('preferences.currencyHint')} wide>
          <Combobox
            options={currencyOptions}
            value={shown.currency}
            onChange={(currency) => void save({ currency }, { affectsData: true })}
            placeholder={t('accountsCommon.selectCurrency')}
          />
        </SettingRow>
      </SettingsCard>

      <SettingsCard title={t('preferences.appearance')}>
        <SettingRow label={t('preferences.theme')} wide>
          <SegmentedControl
            label={t('preferences.theme')}
            fill
            value={shown.theme}
            onChange={(theme: UserTheme) => void save({ theme })}
            options={[
              { value: 'light', label: t('preferences.themeLight') },
              { value: 'dark', label: t('preferences.themeDark') },
              { value: 'system', label: t('preferences.themeSystem') },
            ]}
          />
        </SettingRow>

        <SettingRow label={t('preferences.density')} hint={t('preferences.densityHint')} wide>
          <SegmentedControl
            label={t('preferences.density')}
            fill
            value={shown.density as Density}
            onChange={(density: Density) => void save({ density })}
            options={DENSITIES.map((density) => ({ value: density, label: t(`preferences.densities.${density}`) }))}
          />
        </SettingRow>

        <SettingRow label={t('preferences.hideAmounts')} hint={t('preferences.hideAmountsHint')} htmlFor="hideAmounts">
          <Switch id="hideAmounts" checked={hideAmounts} onCheckedChange={setHideAmounts} />
        </SettingRow>
      </SettingsCard>

      <SettingsCard title={t('preferences.behaviour')}>
        <SettingRow label={t('preferences.startPage')} wide>
          <Select value={shown.startPage} onValueChange={(value) => void save({ startPage: value as StartPage })}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {START_PAGES.map((page) => (
                <SelectItem key={page} value={page}>
                  {t(`preferences.startPages.${page}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingRow>

        <SettingRow label={t('preferences.startPeriod')} wide>
          <Select value={shown.startPeriod} onValueChange={(value) => void save({ startPeriod: value as StartPeriod })}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {START_PERIODS.map((period) => (
                <SelectItem key={period} value={period}>
                  {period === 'last' ? t('preferences.startPeriodLast') : t(`timeframe.${period}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingRow>

        <SettingRow label={t('preferences.defaultAccount')} hint={t('preferences.defaultAccountHint')} wide>
          <Select
            value={shown.defaultAccountId || NO_ACCOUNT}
            onValueChange={(value) => void save({ defaultAccountId: value === NO_ACCOUNT ? '' : value })}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_ACCOUNT}>{t('preferences.noDefaultAccount')}</SelectItem>
              {accountOptions.map((account) => (
                <SelectItem key={account.id} value={account.id}>
                  {account.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingRow>

        <SettingRow label={t('preferences.pageSize')} wide>
          <Select value={String(shown.pageSize)} onValueChange={(value) => void save({ pageSize: Number(value) }, { affectsData: true })}>
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
        </SettingRow>
      </SettingsCard>

      <SettingsCard title={t('preferences.investments')}>
        <SettingRow label={t('preferences.capitalGainsTaxRate')} hint={t('preferences.capitalGainsTaxRateHint')} htmlFor="capitalGainsTaxRate">
          <Input
            id="capitalGainsTaxRate"
            type="number"
            step="0.01"
            min="0"
            max="100"
            className="w-24 text-right"
            value={taxRate}
            onChange={(e) => setTaxRate(e.target.value)}
            onBlur={saveTaxRate}
            onKeyDown={(e) => {
              if (e.key === 'Enter') e.currentTarget.blur()
            }}
          />
        </SettingRow>
      </SettingsCard>
    </div>
  )
}

type PreferencesTab = 'general' | 'dashboard' | 'notifications' | 'api' | 'rates'

export default function Preferences() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  // Exchange rates only exist for someone with more than one currency: the tab is there only then.
  const { data: rates } = useExchangeRatesOverview()
  const hasRates = !!rates && rates.currencies.length > 0
  const requested = searchParams.get('tab')
  const tab: PreferencesTab =
    requested === 'dashboard' || requested === 'notifications' || requested === 'api'
      ? requested
      : requested === 'rates' && hasRates
        ? 'rates'
        : 'general'

  const tabs: { value: PreferencesTab; label: string }[] = [
    { value: 'general', label: t('preferences.general') },
    { value: 'dashboard', label: t('preferences.dashboard') },
    { value: 'notifications', label: t('preferences.notifications') },
    { value: 'api', label: t('preferences.api') },
    ...(hasRates ? [{ value: 'rates' as const, label: t('preferences.exchangeRates') }] : []),
  ]

  return (
    <div className="space-y-6">
      <PageHeader title={t('preferences.title')} />

      <SegmentedControl
        label={t('preferences.title')}
        options={tabs}
        value={tab}
        onChange={(value) => setSearchParams(value === 'general' ? {} : { tab: value }, { replace: true })}
      />

      {/* Settings read best in a column: not stretched across a wide screen. The rates have a table: full width. */}
      {tab === 'rates' ? (
        <ExchangeRatesPanel />
      ) : (
        <div className="max-w-3xl">
          {tab === 'general' ? (
            user && <GeneralPreferences user={user} />
          ) : tab === 'notifications' ? (
            user && <NotificationPreferences user={user} />
          ) : tab === 'api' ? (
            <ApiTokensPreferences />
          ) : (
            <DashboardPreferences />
          )}
        </div>
      )}
    </div>
  )
}
