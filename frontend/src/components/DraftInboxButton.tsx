import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Inbox } from 'lucide-react'
import { usePendingDraftCount } from '@/hooks/use-drafts'
import { cn } from '@/lib/utils'

/** Phones: "To review" beside the transactions' title, with how many wait (the sidebar has it on larger screens). */
export default function DraftInboxButton({ className }: { className?: string }) {
  const { t } = useTranslation()
  const { data: pending = 0 } = usePendingDraftCount()

  return (
    <Link
      to="/transactions/drafts"
      className={cn('relative inline-flex h-10 w-10 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground md:hidden', className)}
      aria-label={pending > 0 ? t('drafts.titleWaiting', { count: pending }) : t('drafts.title')}
    >
      <Inbox className="h-5 w-5" />
      {pending > 0 && (
        <span className="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground tabular-nums">
          {pending > 9 ? '9+' : pending}
        </span>
      )}
    </Link>
  )
}
