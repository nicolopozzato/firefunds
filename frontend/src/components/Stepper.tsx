import type { ReactNode } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface StepperProps {
  /** What is shown: a year, a month. */
  children: ReactNode
  onPrevious: () => void
  onNext: () => void
  previousLabel: string
  nextLabel: string
  previousDisabled?: boolean
  nextDisabled?: boolean
  className?: string
}

/** One period at a time with arrows either side, in one box: the same look as the period in the header. */
export default function Stepper({ children, onPrevious, onNext, previousLabel, nextLabel, previousDisabled, nextDisabled, className }: StepperProps) {
  const arrow = (direction: 'previous' | 'next') => (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className={cn('h-9 w-8 text-muted-foreground hover:text-foreground', direction === 'previous' ? 'rounded-r-none' : 'rounded-l-none')}
      aria-label={direction === 'previous' ? previousLabel : nextLabel}
      title={direction === 'previous' ? previousLabel : nextLabel}
      disabled={direction === 'previous' ? previousDisabled : nextDisabled}
      onClick={direction === 'previous' ? onPrevious : onNext}
    >
      {direction === 'previous' ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
    </Button>
  )

  return (
    <div className={cn('inline-flex items-center rounded-md border border-input bg-background', className)}>
      {arrow('previous')}
      <span className="flex h-9 min-w-24 items-center justify-center border-x border-input px-3 text-sm font-medium capitalize tabular-nums">{children}</span>
      {arrow('next')}
    </div>
  )
}
