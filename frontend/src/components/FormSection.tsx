import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

interface FormSectionProps {
  title: ReactNode
  /** Says "optional" beside the title. */
  optional?: boolean
  /** Something at the right of the title (e.g. a badge). */
  aside?: ReactNode
  className?: string
  contentClassName?: string
  children: ReactNode
}

/**
 * A group of a form's fields under a title (the account, a trade, a
 * transaction): a card on larger screens; on phones it sits on the page,
 * without a frame, so the sections read as one list with headings.
 */
export default function FormSection({ title, optional, aside, className, contentClassName, children }: FormSectionProps) {
  const { t } = useTranslation()

  return (
    <Card className={cn('max-md:border-0 max-md:bg-transparent max-md:shadow-none', className)}>
      <CardHeader className="max-md:px-0 max-md:pb-3 max-md:pt-2">
        <CardTitle className="flex items-center justify-between gap-3 text-base">
          <span>
            {title}
            {optional && <span className="ml-2 text-sm font-normal text-muted-foreground">{t('accountsCommon.optional')}</span>}
          </span>
          {aside}
        </CardTitle>
      </CardHeader>
      <CardContent className={cn('max-md:px-0 max-md:pb-0', contentClassName)}>{children}</CardContent>
    </Card>
  )
}
