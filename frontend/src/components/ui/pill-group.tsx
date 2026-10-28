import { useRef } from 'react'
import { useSelectedInView } from '@/hooks/use-selected-in-view'
import { cn } from '@/lib/utils'

export interface PillOption<T extends string> {
  value: T
  label: string
}

interface PillGroupProps<T extends string> {
  /** Shown before the pills and used as the group's accessible name. */
  label: string
  options: PillOption<T>[]
  value: T
  onChange: (value: T) => void
}

/**
 * A single choice among a few options with a label before it (e.g. a list
 * filter): the same segments as SegmentedControl.
 * When they don't fit (phones), the pills scroll sideways and the selected one
 * is kept in view.
 */
export default function PillGroup<T extends string>({ label, options, value, onChange }: PillGroupProps<T>) {
  const scrollerRef = useRef<HTMLDivElement>(null)
  useSelectedInView(scrollerRef, value)

  return (
    <div className="flex min-w-0 max-w-full items-center gap-2">
      <span className="shrink-0 text-sm text-muted-foreground">{label}</span>
      <div ref={scrollerRef} className="min-w-0 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <div role="radiogroup" aria-label={label} className="inline-flex items-center gap-1 rounded-lg border bg-muted/30 p-1">
          {options.map((option) => {
            const isActive = option.value === value
            return (
              <button
                key={option.value}
                type="button"
                role="radio"
                aria-checked={isActive}
                onClick={() => onChange(option.value)}
                className={cn(
                  'whitespace-nowrap rounded-md px-2.5 py-1 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  isActive
                    ? 'bg-background text-foreground shadow-sm dark:bg-accent'
                    : 'text-muted-foreground hover:text-foreground'
                )}
              >
                {option.label}
              </button>
            )
          })}
        </div>
      </div>
    </div>
  )
}
