import type { ReactNode } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

interface SettingRowProps {
  label: string
  /** A line under the label, when the name alone doesn't say enough. */
  hint?: string
  htmlFor?: string
  /** A control too wide to share the row on phones (a select, a group of options): it goes under the label there. */
  wide?: boolean
  children: ReactNode
}

/** One setting: what it is on the left, its control on the right. */
export default function SettingRow({ label, hint, htmlFor, wide, children }: SettingRowProps) {
  return (
    <div
      className={cn(
        'flex justify-between gap-4 py-3 first:pt-0 last:pb-0 sm:gap-6',
        wide ? 'flex-col gap-2 sm:flex-row sm:items-center' : 'items-center'
      )}
    >
      <div className="min-w-0 space-y-0.5">
        <Label htmlFor={htmlFor} className="text-sm font-medium">
          {label}
        </Label>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </div>
      <div className={cn('shrink-0', wide && 'sm:w-64')}>{children}</div>
    </div>
  )
}
