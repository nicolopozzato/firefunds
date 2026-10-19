import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatDateTime, formatTime24h } from '@/lib/utils'

export default function DateWithTime({ date }: { date: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span>{formatDateTime(date)}</span>
      </TooltipTrigger>
      <TooltipContent>{formatTime24h(date)}</TooltipContent>
    </Tooltip>
  )
}
