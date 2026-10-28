import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

/** How wide a field gets on larger screens: as wide as what it holds. Phones: always the whole width. */
const WIDTHS = {
  /** A percentage, a short code. */
  xs: 'sm:max-w-[9rem]',
  /** An amount, a date, a quantity. */
  sm: 'sm:max-w-[15rem]',
  /** A ticker, an ISIN, a name. */
  md: 'sm:max-w-sm',
  full: '',
}

interface FieldProps {
  label: ReactNode
  htmlFor?: string
  /** A line under the field: what to type, or what it means. */
  hint?: ReactNode
  /** Replaces the hint, in red. */
  error?: ReactNode
  optional?: boolean
  width?: keyof typeof WIDTHS
  /** Small actions on the right of the label's line (e.g. quick dates): never a row of their own. */
  labelAside?: ReactNode
  className?: string
  children: ReactNode
}

/** A form field: its label, the control, and a hint or an error under it, always in the same places. */
export default function Field({ label, htmlFor, hint, error, optional, width = 'full', labelAside, className, children }: FieldProps) {
  const { t } = useTranslation()

  return (
    <div className={cn('min-w-0 space-y-2', className)}>
      <div className={cn('flex items-baseline justify-between gap-3', WIDTHS[width])}>
        <Label htmlFor={htmlFor}>
          {label}
          {optional && <span className="ml-1.5 font-normal text-muted-foreground">{t('common.optional')}</span>}
        </Label>
        {labelAside}
      </div>
      <div className={WIDTHS[width]}>{children}</div>
      {error ? (
        <p className="text-xs text-destructive">{error}</p>
      ) : (
        hint && <p className="text-xs text-muted-foreground">{hint}</p>
      )}
    </div>
  )
}
