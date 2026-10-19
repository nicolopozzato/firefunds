import { Fragment, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import type { DateRange } from 'react-day-picker'
import { cn } from '@/lib/utils'
import { formatReportRange } from '@/lib/report-format'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import {
  REPORT_PRESET_GROUPS,
  isRollingPreset,
  rangeForReportPreset,
  type ReportPreset,
  type ReportSpan,
} from '@/lib/report-period'

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** What the button says: the month or the year when the range is one, otherwise its dates. */
function spanLabel(span: ReportSpan, locale: typeof it): string {
  switch (span.preset) {
    case 'currentMonth':
    case 'previousMonth':
    case 'month':
      return capitalize(format(span.from, 'LLLL yyyy', { locale }))
    case 'currentYear':
    case 'previousYear':
    case 'year':
      return format(span.from, 'yyyy')
    default:
      return formatReportRange(span.from, span.to, locale)
  }
}

/** The concrete range next to a menu entry, muted: "ott 2026", "2025"… */
function presetHint(preset: ReportPreset, locale: typeof it): string | null {
  if (!isRollingPreset(preset)) return null
  const range = rangeForReportPreset(preset)
  switch (preset) {
    case 'currentMonth':
    case 'previousMonth':
      return format(range.from, 'LLL yyyy', { locale })
    case 'currentYear':
    case 'previousYear':
      return format(range.from, 'yyyy')
    default:
      return `${format(range.from, 'LLL yy', { locale })} – ${format(range.to, 'LLL yy', { locale })}`
  }
}

interface ReportPeriodPickerProps {
  span: ReportSpan
  /** A preset picked from the menu (`all` is resolved by the caller). */
  onPreset: (preset: ReportPreset) => void
  onRange: (from: Date, to: Date) => void
  onShift: (direction: -1 | 1) => void
}

/**
 * The report's range, apart from the app's period: the same control
 * (arrows, presets, calendar), with presets that suit a report.
 */
export default function ReportPeriodPicker({ span, onPreset, onRange, onShift }: ReportPeriodPickerProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const isMobile = useIsMobile()
  const [open, setOpen] = useState(false)
  const [showCalendar, setShowCalendar] = useState(false)
  const [draftRange, setDraftRange] = useState<DateRange | undefined>({ from: span.from, to: span.to })

  const openMenu = (next: boolean) => {
    setOpen(next)
    if (next) {
      setShowCalendar(span.preset === 'custom')
      setDraftRange({ from: span.from, to: span.to })
    }
  }

  const handlePreset = (preset: ReportPreset) => {
    if (preset === 'custom') {
      setDraftRange({ from: span.from, to: span.to })
      setShowCalendar(true)
      return
    }
    onPreset(preset)
    setOpen(false)
  }

  const applyCustomRange = () => {
    if (draftRange?.from && draftRange?.to) onRange(draftRange.from, draftRange.to)
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
            disabled={span.preset === 'all'}
            onClick={() => onShift(direction)}
            className={cn('h-9 w-8 text-muted-foreground hover:text-foreground', direction < 0 ? 'rounded-r-none' : 'rounded-l-none')}
          >
            <Icon className="h-4 w-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>{label}</TooltipContent>
      </Tooltip>
    )
  }

  const activePreset = showCalendar ? 'custom' : span.preset
  const presetList = (
    <div className="flex flex-col p-1.5 md:w-60">
      {REPORT_PRESET_GROUPS.map((group, index) => (
        <Fragment key={group[0]}>
          {index > 0 && <div className="mx-2 my-1.5 border-t" />}
          {group.map((preset) => {
            const hint = presetHint(preset, locale)
            return (
              <button
                key={preset}
                type="button"
                onClick={() => handlePreset(preset)}
                className={cn(
                  'flex items-center justify-between gap-3 rounded-lg px-3 py-3 text-left text-base transition-colors hover:bg-accent md:py-2 md:text-sm',
                  activePreset === preset && 'bg-accent font-medium text-primary'
                )}
              >
                {t(`reports.presets.${preset}`)}
                {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
              </button>
            )
          })}
        </Fragment>
      ))}
    </div>
  )

  const customRange = showCalendar && (
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
          {draftRange?.from && draftRange?.to ? formatReportRange(draftRange.from, draftRange.to, locale) : t('timeframe.selectRange')}
        </span>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => setOpen(false)}>
            {t('common.cancel')}
          </Button>
          <Button size="sm" disabled={!draftRange?.from || !draftRange?.to} onClick={applyCustomRange}>
            {t('timeframe.apply')}
          </Button>
        </div>
      </div>
    </div>
  )

  const trigger = (
    <Button
      variant="ghost"
      onClick={isMobile ? () => openMenu(true) : undefined}
      className="h-9 min-w-0 gap-2 rounded-none border-x border-input px-2 font-normal text-foreground md:px-3"
    >
      <CalendarDays className="hidden h-4 w-4 text-muted-foreground min-[400px]:block" />
      <span className="truncate tabular-nums">{spanLabel(span, locale)}</span>
      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    </Button>
  )

  return (
    <div className="flex min-w-0 items-center rounded-md border border-input bg-background">
      {arrow(-1)}
      {isMobile ? (
        <>
          {trigger}
          <Drawer open={open} onOpenChange={openMenu}>
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
        <Popover open={open} onOpenChange={openMenu}>
          <PopoverTrigger asChild>{trigger}</PopoverTrigger>
          <PopoverContent className="w-auto overflow-hidden rounded-xl p-0" align="start">
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
