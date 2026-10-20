import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

/*
 * The pieces of a transaction's or a trade's detail page, so the two read
 * the same: the headline (icon, amount, what and when), where the money went
 * (from → to) and the other fields.
 */

const TONES = {
  positive: 'bg-positive/10 text-positive',
  negative: 'bg-negative/10 text-negative',
  info: 'bg-info/10 text-info',
} as const

/** The icon in a tinted circle, the amount, and under it what it is and when, as plain text. */
export function DetailHeadline({
  icon,
  tone,
  amount,
  subtitle,
}: {
  icon: ReactNode
  tone: keyof typeof TONES
  amount: ReactNode
  subtitle: ReactNode
}) {
  return (
    <div className="flex items-center gap-4">
      <div className={cn('flex h-12 w-12 shrink-0 items-center justify-center rounded-full [&>svg]:h-6 [&>svg]:w-6', TONES[tone])}>
        {icon}
      </div>
      <div className="min-w-0">
        <div className="flex flex-wrap items-baseline gap-x-2">{amount}</div>
        <div className="mt-0.5 text-sm text-muted-foreground">{subtitle}</div>
      </div>
    </div>
  )
}

/** Where the money went, two short lines: "From  account", "To  other account". */
export function DetailFlow({ from, to }: { from: ReactNode; to: ReactNode }) {
  const { t } = useTranslation()

  return (
    <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-2 text-sm">
      <dt className="text-muted-foreground">{t('transactionsCommon.flowFrom')}</dt>
      <dd className="min-w-0">{from}</dd>
      <dt className="text-muted-foreground">{t('transactionsCommon.flowTo')}</dt>
      <dd className="min-w-0">{to}</dd>
    </dl>
  )
}

/**
 * The other fields: label and value in rows on phones, a grid on larger
 * screens; on desktop a column of their own, right of the headline and the flow.
 */
export function DetailFields({ children }: { children: ReactNode }) {
  return (
    <dl className="grid content-start gap-x-6 gap-y-4 max-sm:gap-0 max-sm:divide-y sm:grid-cols-2 lg:border-l lg:pl-6">
      {children}
    </dl>
  )
}

/** Desktop: headline and flow on the left, the fields on the right; one under the other below that. */
export function DetailLayout({ summary, fields }: { summary: ReactNode; fields: ReactNode }) {
  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
      <div className="space-y-6">{summary}</div>
      {fields}
    </div>
  )
}

/** `wide`: the whole row (e.g. notes). */
export function DetailField({ label, wide, children }: { label: string; wide?: boolean; children: ReactNode }) {
  return (
    <div
      className={cn(
        'min-w-0 space-y-1 max-sm:flex max-sm:items-baseline max-sm:justify-between max-sm:gap-4 max-sm:space-y-0 max-sm:py-3',
        wide && 'max-sm:block max-sm:space-y-1 sm:col-span-2'
      )}
    >
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className={cn('min-w-0 text-sm', !wide && 'max-sm:text-right')}>{children}</dd>
    </div>
  )
}
