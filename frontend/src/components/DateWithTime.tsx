import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatDateTime, formatShortDate, formatTime24h } from '@/lib/utils'

/** A day in a table: short, on one line; the whole date and the time on hover. */
export default function DateWithTime({ date }: { date: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="whitespace-nowrap">{formatShortDate(date)}</span>
      </TooltipTrigger>
      <TooltipContent>
        {formatDateTime(date)} · {formatTime24h(date)}
      </TooltipContent>
    </Tooltip>
  )
}
