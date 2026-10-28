import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

const FIGURE = {
  xl: 'figure-xl',
  lg: 'figure-lg',
  md: 'figure-md',
  sm: 'text-sm font-medium tabular-nums',
}

interface StatProps {
  label: ReactNode
  value: ReactNode
  /** A line under the value: how it is made up, or how it moved. */
  detail?: ReactNode
  /** xl: the one figure a page is about; lg and md: the ones beside it; sm: details. */
  size?: keyof typeof FIGURE
  className?: string
  /** E.g. a colour for a signed amount. */
  valueClassName?: string
}

/** A figure with its label: the label always above, the figure in one of four sizes. */
export default function Stat({ label, value, detail, size = 'md', className, valueClassName }: StatProps) {
  return (
    <div className={cn('min-w-0', className)}>
      <div className="stat-label truncate">{label}</div>
      <div className={cn(size === 'sm' ? 'mt-0.5 truncate' : 'mt-1', FIGURE[size], valueClassName)}>{value}</div>
      {detail && <div className="mt-1 text-xs leading-snug text-muted-foreground">{detail}</div>}
    </div>
  )
}
