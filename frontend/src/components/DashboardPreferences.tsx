import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import SegmentedControl from '@/components/ui/segmented-control'
import SettingRow from '@/components/SettingRow'
import { useAuth } from '@/contexts/AuthContext'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useSetDashboardAccounts } from '@/hooks/use-dashboard'
import { DEFAULT_DASHBOARD_SECTIONS, useSavePreferences } from '@/hooks/use-preferences'
import { handleApiError } from '@/lib/error-handler'
import { formatMoney, totalAccountValue } from '@/lib/utils'
import type { DashboardSection, User } from '@/types/auth'

/** The summary stays on top and the account boxes beside: only the sections in between move. */
const FIXED_SECTIONS = new Set(['summary', 'accounts'])

/** Which sections the dashboard shows and in which order. Saved at each change. */
function SectionsCard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const savePreferences = useSavePreferences()
  const [sections, setSections] = useState<DashboardSection[]>(user?.dashboardSections ?? DEFAULT_DASHBOARD_SECTIONS)

  const save = (next: DashboardSection[]) => {
    const previous = sections
    setSections(next)
    savePreferences({ dashboardSections: next }).catch((error) => {
      setSections(previous)
      toast.error(handleApiError(error))
    })
  }

  const toggle = (index: number) =>
    save(sections.map((section, i) => (i === index ? { ...section, visible: !section.visible } : section)))

  const movable = sections.map((section, index) => ({ section, index })).filter(({ section }) => !FIXED_SECTIONS.has(section.key))
  const move = (index: number, direction: -1 | 1) => {
    const position = movable.findIndex((entry) => entry.index === index)
    const other = movable[position + direction]
    if (!other) return
    const next = [...sections]
    next[index] = sections[other.index]
    next[other.index] = sections[index]
    save(next)
  }

  // Shown in the order of the page: summary, the sections in between, the account boxes.
  const ordered = [
    ...sections.map((section, index) => ({ section, index })).filter(({ section }) => section.key === 'summary'),
    ...movable,
    ...sections.map((section, index) => ({ section, index })).filter(({ section }) => section.key === 'accounts'),
  ]

  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{t('preferences.dashboardSections')}</CardTitle>
        <CardDescription>{t('preferences.dashboardSectionsDescription')}</CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="divide-y rounded-lg border">
          {ordered.map(({ section, index }) => {
            const position = movable.findIndex((entry) => entry.index === index)
            return (
              <li key={section.key} className="flex items-center gap-3 px-3 py-1.5">
                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3 py-1">
                  <Checkbox checked={section.visible} onChange={() => toggle(index)} />
                  <span className="truncate text-sm font-medium">{t(`preferences.sections.${section.key}`)}</span>
                </label>
                {position >= 0 && (
                  <div className="flex shrink-0">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      disabled={position === 0}
                      onClick={() => move(index, -1)}
                      aria-label={t('preferences.moveUp')}
                    >
                      <ChevronUp className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      disabled={position === movable.length - 1}
                      onClick={() => move(index, 1)}
                      aria-label={t('preferences.moveDown')}
                    >
                      <ChevronDown className="h-4 w-4" />
                    </Button>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      </CardContent>
    </Card>
  )
}

/** What the chart opens on; also remembered when changed on the dashboard. */
function ChartCard({ user }: { user: User }) {
  const { t } = useTranslation()
  const savePreferences = useSavePreferences()
  const [view, setView] = useState(user.dashboardTrendView)
  const [unit, setUnit] = useState(user.dashboardInvestmentUnit)

  const fail = (error: unknown) => toast.error(handleApiError(error))

  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{t('preferences.dashboardChart')}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="divide-y">
          <SettingRow label={t('preferences.trendView')} wide>
            <SegmentedControl
              label={t('preferences.trendView')}
              fill
              value={view}
              onChange={(next: User['dashboardTrendView']) => {
                setView(next)
                savePreferences({ dashboardTrendView: next }).catch(fail)
              }}
              options={[
                { value: 'accounts', label: t('dashboard.viewAccounts') },
                { value: 'investments', label: t('dashboard.viewInvestments') },
              ]}
            />
          </SettingRow>
          <SettingRow label={t('preferences.investmentUnit')} wide>
            <SegmentedControl
              label={t('preferences.investmentUnit')}
              fill
              value={unit}
              onChange={(next: User['dashboardInvestmentUnit']) => {
                setUnit(next)
                savePreferences({ dashboardInvestmentUnit: next }).catch(fail)
              }}
              options={[
                { value: 'percent', label: t('preferences.investmentUnitPercent') },
                { value: 'money', label: t('preferences.investmentUnitMoney') },
              ]}
            />
          </SettingRow>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * Which asset accounts the dashboard shows (balance chart and per-account
 * boxes). Inactive accounts are never shown, so they aren't listed; their
 * setting is kept as it is. Saved at each change.
 */
function AccountsCard() {
  const { t } = useTranslation()
  const { data: accountsResponse, isLoading } = useAllAccounts('asset')
  const setDashboardAccounts = useSetDashboardAccounts()
  const accounts = accountsResponse?.items ?? []
  const activeAccounts = accounts.filter((account) => account.isActive)

  const [visibleIds, setVisibleIds] = useState<Set<string> | null>(null)

  useEffect(() => {
    if (accountsResponse && visibleIds === null) {
      setVisibleIds(new Set(accountsResponse.items.filter((account) => account.showOnDashboard).map((account) => account.id)))
    }
  }, [accountsResponse, visibleIds])

  const toggle = (id: string) => {
    if (visibleIds === null) return
    const previous = visibleIds
    const next = new Set(visibleIds)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setVisibleIds(next)
    setDashboardAccounts.mutateAsync([...next]).catch((error) => {
      setVisibleIds(previous)
      toast.error(handleApiError(error))
    })
  }

  return (
    <Card>
      <CardHeader className="pb-2 sm:pb-2">
        <CardTitle>{t('preferences.dashboardAccounts')}</CardTitle>
        <CardDescription>{t('preferences.dashboardAccountsDescription')}</CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading || visibleIds === null ? (
          <div className="space-y-2">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : activeAccounts.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('preferences.noActiveAccounts')}</p>
        ) : (
          <ul className="divide-y rounded-lg border">
            {activeAccounts.map((account) => (
              <li key={account.id}>
                <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-accent/50">
                  <Checkbox
                    checked={visibleIds.has(account.id)}
                    onChange={() => toggle(account.id)}
                  />
                  <span className="min-w-0 flex-1 truncate text-sm font-medium">{account.name}</span>
                  {account.assetKind && (
                    <Badge variant="outline" className="max-sm:hidden">{t(`accountsCommon.assetKind_${account.assetKind}`)}</Badge>
                  )}
                  <span className="shrink-0 text-right text-sm tabular-nums text-muted-foreground sm:w-32">
                    {formatMoney(totalAccountValue(account), account.currencySymbol)}
                  </span>
                </label>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

/** The dashboard's tab of the preferences: its sections, its chart, its accounts. */
export default function DashboardPreferences() {
  const { user } = useAuth()

  return (
    <div className="space-y-4 md:space-y-6">
      <SectionsCard />
      {user && <ChartCard user={user} />}
      <AccountsCard />
    </div>
  )
}
