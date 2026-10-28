import type { ReactNode } from 'react'
import { cn, formatPercent } from '@/lib/utils'

/**
 * A change in percent, coloured by what it means: more income is good, more
 * expenses are not (`higherIsBetter` false).
 */
export function ChangePercent({ value, higherIsBetter = true, className }: { value: number | null; higherIsBetter?: boolean; className?: string }) {
  if (value === null || !Number.isFinite(value)) return <span className={cn('text-muted-foreground', className)}>—</span>
  const good = higherIsBetter ? value > 0 : value < 0
  return (
    <span className={cn('tabular-nums', value === 0 ? 'text-muted-foreground' : good ? 'text-positive' : 'text-negative', className)}>
      {value > 0 ? '+' : ''}
      {formatPercent(value, Math.abs(value) < 10 ? 1 : 0)}
    </span>
  )
}

/** A line of a chart's tooltip: a dot, a label, a value. */
export function TooltipLine({ color, label, value, muted }: { color?: string; label: string; value: string; muted?: boolean }) {
  return (
    <span className={cn('flex items-center gap-1.5 whitespace-nowrap text-xs', muted && 'opacity-70')}>
      {color && <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} />}
      <span className="text-muted-foreground">{label}:</span>
      <span className="font-semibold tabular-nums text-foreground">{value}</span>
    </span>
  )
}

/** A report section: a card with a title and, on the right, its options or legend. */
export function ReportCard({ title, actions, children, className }: { title: string; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={cn('min-w-0 surface p-4 sm:p-5', className)}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="section-title">{title}</h3>
        {actions}
      </div>
      {children}
    </section>
  )
}
