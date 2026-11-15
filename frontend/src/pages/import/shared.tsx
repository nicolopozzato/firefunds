import { useTranslation } from 'react-i18next'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'

export type Step = 'file' | 'columns' | 'match' | 'review' | 'done'

/** The steps this file goes through (a Firefly export has no columns to choose, a bank's statement may have nothing to match). */
export function Steps({ steps, step }: { steps: Step[]; step: Step }) {
  const { t } = useTranslation()
  const current = steps.indexOf(step)
  return (
    <ol className="flex flex-wrap items-center gap-2 text-sm">
      {steps.map((name, i) => (
        <li key={name} className="flex items-center gap-2">
          <span
            className={cn(
              'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
              i <= current ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
            )}
          >
            {i + 1}
          </span>
          <span className={cn(i === current ? 'font-medium' : 'text-muted-foreground', i !== current && 'max-sm:hidden')}>{t(`csvImport.step_${name}`)}</span>
          {i < steps.length - 1 && <span className="mx-1 h-px w-6 bg-border" aria-hidden />}
        </li>
      ))}
    </ol>
  )
}

export function SelectField({ value, onChange, options, label }: { value: string; onChange: (value: string) => void; options: { value: string; label: string }[]; label?: string }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger aria-label={label}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
