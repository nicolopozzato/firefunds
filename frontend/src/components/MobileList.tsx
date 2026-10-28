import type { ReactNode } from 'react'
import { Check, ChevronRight } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { useLongPress } from '@/hooks/use-long-press'
import { cn } from '@/lib/utils'

/**
 * Phones: the building blocks of the lists that replace wide tables. Rows sit
 * in rounded cards, optionally under a heading (e.g. a day), and each opens
 * its detail page, where the actions are.
 */

export function MobileListCard({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('divide-y overflow-hidden surface', className)}>{children}</div>
}

export function MobileListSection({ title, children }: { title: ReactNode; children: ReactNode }) {
  return (
    <section>
      <h3 className="group-label px-1 pb-1.5">{title}</h3>
      <MobileListCard>{children}</MobileListCard>
    </section>
  )
}

interface MobileListRowProps {
  /** E.g. a round icon or a colour dot. */
  leading?: ReactNode
  title: ReactNode
  subtitle?: ReactNode
  /** Under the subtitle, e.g. what a search matched. */
  extra?: ReactNode
  /** On the right: usually an amount. */
  value?: ReactNode
  valueClassName?: string
  /** Under the value, muted. */
  valueDetail?: ReactNode
  onClick?: () => void
  /** A chevron on the right, for rows without a value. */
  chevron?: boolean
  /** Holding the row down: e.g. starts selecting rows. */
  onLongPress?: () => void
  /** While rows are being selected: whether this one is (a round check takes the leading's place). */
  selected?: boolean
}

export function MobileListRow({ leading, title, subtitle, extra, value, valueClassName, valueDetail, onClick, chevron, onLongPress, selected }: MobileListRowProps) {
  const longPress = useLongPress(onLongPress)
  const selecting = selected !== undefined

  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={selecting ? selected : undefined}
      {...longPress}
      className={cn(
        'flex w-full select-none items-center gap-3 px-4 py-3 text-left transition-colors [-webkit-touch-callout:none] active:bg-accent',
        selected && 'bg-primary/5'
      )}
    >
      {selecting ? (
        <span
          className={cn(
            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition-colors',
            selected ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'
          )}
        >
          {selected && <Check className="h-3.5 w-3.5" strokeWidth={3} />}
        </span>
      ) : (
        leading
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate font-medium">{title}</span>
        {subtitle && <span className="block truncate text-xs text-muted-foreground">{subtitle}</span>}
        {extra}
      </span>
      {(value !== undefined || valueDetail) && (
        <span className="shrink-0 text-right">
          {value !== undefined && <span className={cn('block text-sm font-semibold tabular-nums', valueClassName)}>{value}</span>}
          {valueDetail && <span className="block text-xs text-muted-foreground tabular-nums">{valueDetail}</span>}
        </span>
      )}
      {chevron && <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60" />}
    </button>
  )
}

/**
 * The icon in front of a row, just the coloured symbol as in the desktop
 * tables (no background), so the title and subtitle get the width;
 * `className` gives its colour when the icon has none of its own.
 */
export function MobileListIcon({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <span className={cn('flex shrink-0 items-center justify-center [&>svg]:h-5 [&>svg]:w-5', className)}>
      {children}
    </span>
  )
}

export function MobileListSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <MobileListCard>
      {Array.from({ length: rows }).map((_, index) => (
        <div key={index} className="flex items-center gap-3 px-4 py-3">
          <Skeleton className="h-5 w-5 shrink-0 rounded-full" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="h-3 w-1/2" />
          </div>
          <Skeleton className="h-4 w-16" />
        </div>
      ))}
    </MobileListCard>
  )
}
