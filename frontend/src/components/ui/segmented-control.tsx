import { useRef } from 'react'
import { useSelectedInView } from '@/hooks/use-selected-in-view'
import { cn } from '@/lib/utils'

export interface SegmentedOption<T extends string> {
  value: T
  label: string
}

interface SegmentedControlProps<T extends string> {
  /** The group's accessible name (not shown). */
  label: string
  options: SegmentedOption<T>[]
  value: T
  onChange: (value: T) => void
  /**
   * Across the whole width, compact (phones): the options share the row, and
   * it scrolls sideways only if they still don't fit.
   */
  fill?: boolean
  /** See useSelectedInView: for a control rebuilt on every choice. */
  scrollMemoryKey?: string
}

/**
 * A single choice among a few options, as segments in one box: the period of
 * an instrument's price chart, the transaction lists on phones.
 */
export default function SegmentedControl<T extends string>({ label, options, value, onChange, fill, scrollMemoryKey }: SegmentedControlProps<T>) {
  const scrollerRef = useRef<HTMLDivElement>(null)
  useSelectedInView(scrollerRef, value, scrollMemoryKey)

  return (
    <div ref={scrollerRef} className="max-w-full overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <div
        role="radiogroup"
        aria-label={label}
        className={cn(
          'items-center rounded-lg border bg-muted/30 p-1',
          fill ? 'flex w-full min-w-max gap-0.5' : 'inline-flex gap-1'
        )}
      >
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
                'whitespace-nowrap rounded-md py-1 font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                fill ? 'flex-auto px-1.5 text-xs' : 'px-2.5 text-sm',
                isActive ? 'bg-background text-foreground shadow-sm dark:bg-accent' : 'text-muted-foreground hover:text-foreground'
              )}
            >
              {option.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}
