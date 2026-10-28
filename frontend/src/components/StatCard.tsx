import type { ReactNode } from 'react'
import { Card, CardContent } from '@/components/ui/card'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

interface StatCardProps {
  title: string
  value: ReactNode
  icon: LucideIcon
  iconColor: 'blue' | 'green' | 'orange' | 'yellow'
  /** E.g. a color class for a signed amount. */
  valueClassName?: string
  /** A line under the value: how it is made up, or how it moved. */
  detail?: ReactNode
}

// Decorative, from the theme (lib/theme.ts) so they suit light and dark mode.
const iconColorStyles = {
  blue: 'bg-info/10 text-info',
  green: 'bg-positive/10 text-positive',
  orange: 'bg-warning/10 text-warning',
  yellow: 'bg-[hsl(var(--series-4)/0.1)] text-[hsl(var(--series-4))]',
}

/** One of the dashboard's headline figures: the same label, figure and detail as `Stat`, in a card with an icon. */
export default function StatCard({ title, value, icon: Icon, iconColor, valueClassName, detail }: StatCardProps) {
  return (
    <Card className="surface-interactive group">
      {/* Phones: two cards per row, so a smaller figure and no icon. */}
      <CardContent className="p-4 pt-4 sm:p-5 sm:pt-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="stat-label">{title}</p>
            <p className={cn('mt-1 break-words figure-md sm:figure-lg', valueClassName)}>{value}</p>
            {detail && <div className="mt-1 text-xs leading-snug text-muted-foreground">{detail}</div>}
          </div>
          <div className={cn('hidden rounded-lg p-2 transition-transform duration-300 ease-out group-hover:scale-110 motion-reduce:transition-none sm:block', iconColorStyles[iconColor])}>
            <Icon className="h-4 w-4" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
