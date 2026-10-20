import { ReactNode } from 'react'
import { cn } from '@/lib/utils'

interface PageHeaderProps {
  title: string
  description?: string
  /** Buttons, or PageActions (on phones a couple of icons, which stay beside the title). */
  actions?: ReactNode
  /** The actions are PageActions: on phones they never wrap under the title. */
  compactActions?: boolean
}

export default function PageHeader({ title, description, actions, compactActions = false }: PageHeaderProps) {
  return (
    // When title and buttons don't fit on one line (phones), the buttons wrap under the title;
    // PageActions' icons stay beside it and the title wraps instead.
    <div
      className={cn(
        'flex flex-wrap items-center justify-between gap-3',
        compactActions && 'max-md:flex-nowrap max-md:items-start'
      )}
    >
      <div className={cn('min-w-0 space-y-1', compactActions && 'max-md:flex-1')}>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {description && (
          // Phones: the title is enough, the description would push the content down.
          <p className="hidden text-sm text-muted-foreground md:block">{description}</p>
        )}
      </div>
      {actions && (
        <div className="flex shrink-0 items-center gap-2">
          {actions}
        </div>
      )}
    </div>
  )
}
