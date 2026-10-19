import { Fragment, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format, isSameYear } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { useTimeframe, rangeForPreset, unitOf, type Timeframe, type TimeframePreset } from '@/contexts/TimeframeContext'
import type { DateRange } from 'react-day-picker'

/** The menu, in groups divided by a line. */
const PRESET_GROUPS: TimeframePreset[][] = [
  ['currentMonth'],
  ['last7Days', 'last30Days', 'last60Days'],
  ['currentYear', 'previousYear'],
  ['all', 'custom'],
]

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** "1 – 15 ott 2026", or "15 dic 2025 – 15 gen 2026" across two years. */
function formatRange(from: Date, to: Date, locale: typeof it): string {
  const start = format(from, isSameYear(from, to) ? 'd MMM' : 'd MMM yyyy', { locale })
  return `${start} – ${format(to, 'd MMM yyyy', { locale })}`
}

/** What the button says: the month or the year when the period is one, otherwise its dates. */
function timeframeLabel(timeframe: Timeframe, locale: typeof it, allLabel: string): string {
  switch (unitOf(timeframe.preset)) {
    case 'month':
      return capitalize(format(timeframe.from, 'LLLL yyyy', { locale }))
    case 'year':
      return format(timeframe.from, 'yyyy')
    case 'none':
      return allLabel
    default:
      return formatRange(timeframe.from, timeframe.to, locale)
  }
}

/** The concrete period next to a menu entry, muted: "ott 2026", "2025"… */
function presetHint(preset: TimeframePreset, locale: typeof it): string | null {
  const range = rangeForPreset(preset)
  switch (preset) {
    case 'currentMonth':
      return format(range.from, 'LLL yyyy', { locale })
    case 'currentYear':
    case 'previousYear':
      return format(range.from, 'yyyy')
    default:
      return null
  }
}

export default function TimeframeSelector() {
  const { t, i18n } = useTranslation()
  const { timeframe, setPreset, setCustomRange, shift } = useTimeframe()
  const [open, setOpen] = useState(false)
  const [draftRange, setDraftRange] = useState<DateRange | undefined>({
    from: timeframe.from,
    to: timeframe.to,
  })

  const isMobile = useIsMobile()
  const locale = i18n.language === 'it' ? it : enUS
  const canShift = unitOf(timeframe.preset) !== 'none'

  const handlePresetClick = (preset: TimeframePreset) => {
    if (preset === 'custom') {
      setDraftRange({ from: timeframe.from, to: timeframe.to })
      setPreset('custom')
      return
    }
    setPreset(preset)
    setOpen(false)
  }

  const handleApplyCustomRange = () => {
    if (draftRange?.from && draftRange?.to) {
      setCustomRange(draftRange.from, draftRange.to)
    }
    setOpen(false)
  }

  const arrow = (direction: -1 | 1) => {
    const label = t(direction < 0 ? 'timeframe.previousPeriod' : 'timeframe.nextPeriod')
    const Icon = direction < 0 ? ChevronLeft : ChevronRight

    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            aria-label={label}
            disabled={!canShift}
            onClick={() => shift(direction)}
            className={cn('h-9 w-8 text-muted-foreground hover:text-foreground', direction < 0 ? 'rounded-r-none' : 'rounded-l-none')}
          >
            <Icon className="h-4 w-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>{label}</TooltipContent>
      </Tooltip>
    )
  }

  const presetList = (
    <div className="flex flex-col p-1.5 md:w-56">
      {PRESET_GROUPS.map((group, index) => (
        <Fragment key={group[0]}>
          {index > 0 && <div className="mx-2 my-1.5 border-t" />}
          {group.map((preset) => {
            const hint = presetHint(preset, locale)
            const isActive = timeframe.preset === preset
            return (
              <button
                key={preset}
                type="button"
                onClick={() => handlePresetClick(preset)}
                className={cn(
                  'flex items-center justify-between gap-3 rounded-lg px-3 py-3 text-left text-base transition-colors hover:bg-accent md:py-2 md:text-sm',
                  isActive && 'bg-accent font-medium text-primary'
                )}
              >
                {t(`timeframe.${preset}`)}
                {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
              </button>
            )
          })}
        </Fragment>
      ))}
    </div>
  )

  const customRange = timeframe.preset === 'custom' && (
    <div className="flex flex-col border-t md:border-l md:border-t-0">
      <Calendar
        mode="range"
        numberOfMonths={isMobile ? 1 : 2}
        defaultMonth={draftRange?.from}
        selected={draftRange}
        onSelect={setDraftRange}
        locale={locale}
        className="mx-auto"
      />
      <div className="flex items-center justify-between gap-2 border-t p-3">
        <span className="text-sm text-muted-foreground">
          {draftRange?.from && draftRange?.to
            ? formatRange(draftRange.from, draftRange.to, locale)
            : t('timeframe.selectRange')}
        </span>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => setOpen(false)}>
            {t('common.cancel')}
          </Button>
          <Button size="sm" disabled={!draftRange?.from || !draftRange?.to} onClick={handleApplyCustomRange}>
            {t('timeframe.apply')}
          </Button>
        </div>
      </div>
    </div>
  )

  const trigger = (
    <Button
      variant="ghost"
      onClick={isMobile ? () => setOpen(true) : undefined}
      className="h-9 min-w-0 gap-2 rounded-none border-x border-input px-2 font-normal text-foreground md:px-3"
    >
      <CalendarDays className="hidden h-4 w-4 text-muted-foreground min-[400px]:block" />
      <span className="truncate tabular-nums">{timeframeLabel(timeframe, locale, t('timeframe.all'))}</span>
      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    </Button>
  )

  return (
    <div className="flex min-w-0 items-center rounded-md border border-input bg-background">
      {arrow(-1)}

      {isMobile ? (
        <>
          {trigger}
          {/* Phones: the same menu as a panel from the bottom, the calendar one month wide. */}
          <Drawer open={open} onOpenChange={setOpen}>
            <DrawerContent>
              <DrawerHeader>
                <DrawerTitle>{t('timeframe.title')}</DrawerTitle>
                <DrawerDescription className="sr-only">{t('timeframe.title')}</DrawerDescription>
              </DrawerHeader>
              <div className="overflow-y-auto pb-2">
                {presetList}
                {customRange}
              </div>
            </DrawerContent>
          </Drawer>
        </>
      ) : (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>{trigger}</PopoverTrigger>
          <PopoverContent className="w-auto overflow-hidden rounded-xl p-0" align="center">
            <div className="flex">
              {presetList}
              {customRange}
            </div>
          </PopoverContent>
        </Popover>
      )}

      {arrow(1)}
    </div>
  )
}
