import { useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { TriangleAlert, X } from 'lucide-react'
import { useExchangeRatesOverview } from '@/hooks/use-exchange-rates'
import { rateWarning } from '@/lib/exchange-rate-warning'
import { formatDateTime } from '@/lib/utils'

const DISMISSED_KEY = 'firefunds.exchangeRateWarningDismissed'

function readDismissed(): string | null {
  try {
    return localStorage.getItem(DISMISSED_KEY)
  } catch {
    return null
  }
}

/**
 * Above every page while a total can't be trusted: a currency in use with no
 * rate at all (sums count it as if it were the user's own, holdings in it
 * have no value), or shared rates that stopped days ago (the last one is
 * carried over). Missing rates stay until fixed; stale ones can be dismissed
 * until they get older still. Nothing for someone with a single currency.
 */
export default function ExchangeRateWarning() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const [searchParams] = useSearchParams()
  const { data } = useExchangeRatesOverview()
  const [dismissed, setDismissed] = useState(readDismissed)

  // The rates tab says it itself, next to the place to fix it.
  const onRatesTab = pathname === '/preferences' && searchParams.get('tab') === 'rates'
  const warning = data && !onRatesTab ? rateWarning(data.currencies, dismissed) : null
  if (!data || !warning) return null

  const { missing, stale } = warning
  const dismiss = () => {
    try {
      localStorage.setItem(DISMISSED_KEY, warning.signature)
    } catch {
      // Not remembered: it comes back on the next load.
    }
    setDismissed(warning.signature)
  }

  return (
    <div role="status" className="mb-4 flex gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm md:mb-6">
      <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden />
      <div className="min-w-0 flex-1 space-y-1">
        {missing.length > 0 && (
          <p>{t('exchangeRates.warningMissing', { count: missing.length, currencies: missing.join(', '), own: data.currency })}</p>
        )}
        {stale.length > 0 && (
          <p>{t('exchangeRates.warningStale', { count: stale.length, currencies: stale.join(', '), date: warning.staleSince ? formatDateTime(warning.staleSince) : '' })}</p>
        )}
        <Link to={`/preferences?tab=rates&currency=${warning.first}`} className="inline-block font-medium text-primary hover:underline">
          {t(missing.length > 0 ? 'exchangeRates.warningMissingAction' : 'exchangeRates.warningStaleAction')}
        </Link>
      </div>
      {missing.length === 0 && (
        <button
          type="button"
          onClick={dismiss}
          aria-label={t('exchangeRates.warningDismiss')}
          className="-m-1 h-7 w-7 shrink-0 rounded-md p-1 text-muted-foreground hover:bg-warning/20 hover:text-foreground"
        >
          <X className="h-5 w-5" />
        </button>
      )}
    </div>
  )
}
