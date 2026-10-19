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

export default function StatCard({ title, value, icon: Icon, iconColor, valueClassName, detail }: StatCardProps) {
  return (
    <Card className="group hover:shadow-xl hover:shadow-primary/10 cursor-default">
      {/* Phones: two cards per row, so smaller text and no icon. */}
      <CardContent className="px-4 py-3 sm:px-5 sm:py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="text-xs font-medium text-muted-foreground tracking-wide">{title}</p>
            <h3 className={cn('mt-1 break-words text-lg font-bold tabular-nums sm:mt-1.5 sm:text-2xl', valueClassName)}>{value}</h3>
            {detail && <div className="mt-1 text-[11px] leading-snug text-muted-foreground sm:mt-1.5 sm:text-xs">{detail}</div>}
          </div>
          <div className={cn('hidden rounded-lg p-2.5 transition-transform duration-200 group-hover:scale-110 sm:block', iconColorStyles[iconColor])}>
            <Icon className="h-5 w-5" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
