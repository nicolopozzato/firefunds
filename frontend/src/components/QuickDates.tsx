import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

/** `datetime-local` value: the given day, keeping the time already in `value` (now's when there is none). */
function onDay(value: string, daysAgo: number): string {
  const day = new Date()
  day.setDate(day.getDate() - daysAgo)
  const pad = (n: number) => String(n).padStart(2, '0')
  const time = value.slice(11, 16) || `${pad(day.getHours())}:${pad(day.getMinutes())}`
  return `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}T${time}`
}

/**
 * "Today · Yesterday" on the date field's label line: most transactions are
 * entered the same day or the next. Sets the day, keeps the time.
 */
export default function QuickDates({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const { t } = useTranslation()
  const options = [
    { label: t('quickDates.today'), daysAgo: 0 },
    { label: t('quickDates.yesterday'), daysAgo: 1 },
  ]

  return (
    <span className="flex items-center gap-1 text-xs">
      {options.map((option, index) => {
        const next = onDay(value, option.daysAgo)
        const current = value.slice(0, 10) === next.slice(0, 10)
        return (
          <span key={option.daysAgo} className="flex items-center gap-1">
            {index > 0 && <span className="text-muted-foreground/50" aria-hidden>·</span>}
            <button
              type="button"
              aria-pressed={current}
              onClick={() => onChange(next)}
              className={cn(
                'rounded px-1 font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                current ? 'text-foreground' : 'text-primary hover:text-primary/80'
              )}
            >
              {option.label}
            </button>
          </span>
        )
      })}
    </span>
  )
}
