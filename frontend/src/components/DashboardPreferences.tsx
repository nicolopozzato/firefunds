import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useAllAccounts } from '@/hooks/use-accounts'
import { useSetDashboardAccounts } from '@/hooks/use-dashboard'
import { formatMoney, totalAccountValue } from '@/lib/utils'

/**
 * Which asset accounts the dashboard shows (balance chart and per-account
 * boxes). Inactive accounts are never shown, so they aren't listed; their
 * setting is kept as it is.
 */
export default function DashboardPreferences() {
  const { t } = useTranslation()
  const { data: accountsResponse, isLoading } = useAllAccounts('asset')
  const setDashboardAccounts = useSetDashboardAccounts()
  const accounts = accountsResponse?.items ?? []
  const activeAccounts = accounts.filter((account) => account.isActive)

  const [visibleIds, setVisibleIds] = useState<Set<string> | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (accountsResponse && visibleIds === null) {
      setVisibleIds(new Set(accountsResponse.items.filter((account) => account.showOnDashboard).map((account) => account.id)))
    }
  }, [accountsResponse, visibleIds])

  const toggle = (id: string) => {
    setSaved(false)
    setVisibleIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (visibleIds === null) return
    await setDashboardAccounts.mutateAsync([...visibleIds])
    setSaved(true)
  }

  return (
    <Card>
      <CardHeader>
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
          <form onSubmit={handleSubmit} className="space-y-6">
            <ul className="divide-y rounded-lg border">
              {activeAccounts.map((account) => (
                <li key={account.id}>
                  <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-accent/50">
                    <input
                      type="checkbox"
                      className="h-4 w-4 accent-primary"
                      checked={visibleIds.has(account.id)}
                      onChange={() => toggle(account.id)}
                    />
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{account.name}</span>
                    {account.assetKind && (
                      <Badge variant="outline">{t(`accountsCommon.assetKind_${account.assetKind}`)}</Badge>
                    )}
                    <span className="w-32 shrink-0 text-right text-sm tabular-nums text-muted-foreground">
                      {formatMoney(totalAccountValue(account), account.currencySymbol)}
                    </span>
                  </label>
                </li>
              ))}
            </ul>

            {saved && <p className="text-sm text-positive">{t('preferences.saved')}</p>}

            <div className="flex justify-end">
              <Button type="submit" disabled={setDashboardAccounts.isPending}>
                {t('common.save')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
