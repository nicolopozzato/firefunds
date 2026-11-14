import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

/** The colour of a choice: what it means (money out, in, moved), or the accent. */
const TONES = {
  primary: { selected: 'border-primary bg-primary/5 ring-1 ring-primary', icon: 'bg-primary/10 text-primary' },
  negative: { selected: 'border-negative bg-negative/5 ring-1 ring-negative', icon: 'bg-negative/10 text-negative' },
  positive: { selected: 'border-positive bg-positive/5 ring-1 ring-positive', icon: 'bg-positive/10 text-positive' },
  info: { selected: 'border-info bg-info/5 ring-1 ring-info', icon: 'bg-info/10 text-info' },
  invest: { selected: 'border-invest bg-invest/5 ring-1 ring-invest', icon: 'bg-invest/10 text-invest' },
}

export interface ChoiceCardOption<T extends string> {
  value: T
  label: string
  /** Phones, where three share a row: a shorter label. */
  shortLabel?: string
  /** A line under the label, larger screens only. */
  description?: string
  icon: LucideIcon
  tone?: keyof typeof TONES
}

interface ChoiceCardsProps<T extends string> {
  /** The group's accessible name (not shown). */
  label: string
  options: ChoiceCardOption<T>[]
  value: T | null
  onChange: (value: T) => void
}

/** The kind of what is being entered (a withdrawal or a deposit, a buy or a sell, a kind of account), as cards side by side. */
export default function ChoiceCards<T extends string>({ label, options, value, onChange }: ChoiceCardsProps<T>) {
  // Four side by side leave no room beside the icon: the label goes under it, two by two on phones.
  const stacked = options.length === 4

  return (
    <div
      role="radiogroup"
      aria-label={label}
      className={cn('grid gap-2 sm:gap-3', options.length === 3 ? 'grid-cols-3' : stacked ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2')}
    >
      {options.map((option) => {
        const tone = TONES[option.tone ?? 'primary']
        const selected = option.value === value
        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onChange(option.value)}
            className={cn(
              'surface surface-interactive flex min-w-0 flex-col items-center gap-2 px-2 py-3 text-center hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              !stacked && 'sm:flex-row sm:gap-3 sm:px-4 sm:text-left',
              selected && tone.selected
            )}
          >
            <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', selected ? tone.icon : 'bg-muted text-muted-foreground')}>
              <option.icon className="h-5 w-5" />
            </span>
            <span className={cn('min-w-0', stacked && 'w-full')}>
              <span className="block truncate text-sm font-semibold">
                {option.shortLabel ? (
                  <>
                    <span className="sm:hidden">{option.shortLabel}</span>
                    <span className="hidden sm:inline">{option.label}</span>
                  </>
                ) : (
                  option.label
                )}
              </span>
              {option.description && <span className="hidden text-xs text-muted-foreground sm:block">{option.description}</span>}
            </span>
          </button>
        )
      })}
    </div>
  )
}
