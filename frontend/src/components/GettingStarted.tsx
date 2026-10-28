import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Check, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { useAllAccounts } from '@/hooks/use-accounts'
import { transactionsApi } from '@/lib/transactions-api'
import { cn } from '@/lib/utils'

const DISMISSED_KEY = 'getting-started-dismissed'
const PREFERENCES_KEY = 'getting-started-preferences'

function remembered(key: string): boolean {
  try {
    return localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

function remember(key: string): void {
  try {
    localStorage.setItem(key, '1')
  } catch {
    // Not remembered on this device: the card simply shows again.
  }
}

/**
 * A new user's first steps, on top of the dashboard: one thing at a time
 * (an account, a transaction, the formats), each ticked off as it is done,
 * only the next one with a button. Gone once the first two are done, or when
 * closed (this device).
 */
export default function GettingStarted() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [dismissed, setDismissed] = useState(() => remembered(DISMISSED_KEY))
  const { data: accounts } = useAllAccounts('asset')
  const { data: dateRange } = useQuery({
    queryKey: ['transactions', 'date-range'],
    queryFn: () => transactionsApi.dateRange(),
  })

  if (dismissed || !accounts || !dateRange) return null

  const steps = [
    { key: 'account', done: accounts.items.length > 0, to: '/accounts/asset/new' },
    { key: 'transaction', done: dateRange.earliest !== null, to: '/transactions/new' },
    { key: 'preferences', done: remembered(PREFERENCES_KEY), to: '/preferences' },
  ]
  if (steps[0].done && steps[1].done) return null
  const next = steps.find((step) => !step.done)

  const go = (step: (typeof steps)[number]) => {
    if (step.key === 'preferences') remember(PREFERENCES_KEY)
    navigate(step.to)
  }

  return (
    <Card className="animate-in fade-in-0">
      <CardContent className="pt-4 sm:pt-5">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="section-title">{t('gettingStarted.title')}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{t('gettingStarted.description')}</p>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="-mr-2 -mt-2 h-8 w-8 shrink-0 text-muted-foreground"
            aria-label={t('gettingStarted.dismiss')}
            title={t('gettingStarted.dismiss')}
            onClick={() => {
              remember(DISMISSED_KEY)
              setDismissed(true)
            }}
          >
            <X className="h-4 w-4" />
          </Button>
        </div>

        <ol className="mt-4 divide-y rounded-lg border">
          {steps.map((step, index) => {
            const isNext = step === next
            return (
              <li key={step.key} className="flex items-start gap-3 px-3 py-3 sm:items-center sm:px-4">
                <span
                  className={cn(
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums',
                    step.done && 'border-positive/40 bg-positive/15 text-positive',
                    isNext && 'border-primary text-primary'
                  )}
                >
                  {step.done ? <Check className="h-3.5 w-3.5" /> : index + 1}
                </span>
                <span className="min-w-0 flex-1">
                  <span className={cn('block text-sm font-medium', step.done && 'text-muted-foreground line-through decoration-muted-foreground/40')}>
                    {t(`gettingStarted.${step.key}`)}
                  </span>
                  {isNext && <span className="block text-xs text-muted-foreground">{t(`gettingStarted.${step.key}Hint`)}</span>}
                  {/* Phones: the button under the text, so the text keeps the width. */}
                  {isNext && (
                    <Button size="sm" className="mt-2 sm:hidden" onClick={() => go(step)}>
                      {t(`gettingStarted.${step.key}Action`)}
                    </Button>
                  )}
                </span>
                {isNext && (
                  <Button size="sm" className="hidden sm:inline-flex" onClick={() => go(step)}>
                    {t(`gettingStarted.${step.key}Action`)}
                  </Button>
                )}
              </li>
            )
          })}
        </ol>
      </CardContent>
    </Card>
  )
}
