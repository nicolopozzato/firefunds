import type { ReactNode } from 'react'
import { Plus, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface EmptyStateProps {
  title: ReactNode
  description?: ReactNode
  /** E.g. "Add account": a button that starts with a plus. */
  action?: { label: string; onClick: () => void }
  icon?: LucideIcon
  className?: string
}

/** What a list or a section shows with nothing in it: a title, a line, and what to do about it. */
export default function EmptyState({ title, description, action, icon: Icon, className }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center justify-center px-4 py-12 text-center', className)}>
      {Icon && (
        <div className="mb-3 rounded-full bg-muted p-3">
          <Icon className="h-6 w-6 text-muted-foreground" />
        </div>
      )}
      <h3 className="section-title">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
      {action && (
        <Button className="mt-4" onClick={action.onClick}>
          <Plus className="mr-2 h-4 w-4" />
          {action.label}
        </Button>
      )}
    </div>
  )
}
