import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import {
  addDays,
  addMonths,
  addYears,
  differenceInCalendarDays,
  endOfMonth,
  endOfYear,
  getYear,
  isSameDay,
  isSameMonth,
  startOfMonth,
  startOfYear,
  subDays,
  subYears,
} from 'date-fns'
import { transactionsApi } from '@/lib/transactions-api'

/**
 * `currentMonth`, the `lastNDays`, `currentYear` and `previousYear` follow
 * today (recomputed on every load); `month` and `year` are a given calendar
 * month or year reached with the arrows; `custom` is any range.
 */
export type TimeframePreset =
  | 'currentMonth'
  | 'month'
  | 'last7Days'
  | 'last30Days'
  | 'last60Days'
  | 'currentYear'
  | 'previousYear'
  | 'year'
  | 'all'
  | 'custom'

/** How the arrows move a period: a whole month, a whole year, or a window as long as the current one. */
export type TimeframeUnit = 'month' | 'year' | 'window' | 'none'

export interface Timeframe {
  preset: TimeframePreset
  from: Date
  to: Date
}

interface StoredTimeframe {
  preset: TimeframePreset | 'previousMonth' | 'nextMonth'
  from: string
  to: string
}

const STORAGE_KEY = 'firefunds-timeframe'

const ALL_TIME_FROM = new Date(2000, 0, 1)

/** Presets that follow today: recomputed when the app loads, not restored as stored. */
const ROLLING_PRESETS: TimeframePreset[] = ['currentMonth', 'last7Days', 'last30Days', 'last60Days', 'currentYear', 'previousYear']

export function unitOf(preset: TimeframePreset): TimeframeUnit {
  switch (preset) {
    case 'currentMonth':
    case 'month':
      return 'month'
    case 'currentYear':
    case 'previousYear':
    case 'year':
      return 'year'
    case 'all':
      return 'none'
    default:
      return 'window'
  }
}

export function rangeForPreset(preset: TimeframePreset, reference: Date = new Date()): { from: Date; to: Date } {
  const today = reference

  switch (preset) {
    case 'last7Days':
      return { from: subDays(today, 6), to: today }
    case 'last30Days':
      return { from: subDays(today, 29), to: today }
    case 'last60Days':
      return { from: subDays(today, 59), to: today }
    case 'currentYear':
      return { from: startOfYear(today), to: today }
    case 'previousYear': {
      const previous = subYears(today, 1)
      return { from: startOfYear(previous), to: endOfYear(previous) }
    }
    case 'all':
      return { from: ALL_TIME_FROM, to: today }
    default:
      return { from: startOfMonth(today), to: endOfMonth(today) }
  }
}

/**
 * The preset that today gives exactly these days, if any ("last 60 days" for
 * the last 60 days picked by hand or reached with the arrows), else `custom`.
 */
export function presetForRange(from: Date, to: Date, today: Date = new Date()): TimeframePreset {
  const match = ROLLING_PRESETS.find((preset) => {
    const range = rangeForPreset(preset, today)
    return isSameDay(range.from, from) && isSameDay(range.to, to)
  })
  return match ?? 'custom'
}

/**
 * The period before (-1) or after (+1) this one: the whole previous/next month
 * or year, or for any other range the window of the same length right before
 * or after it. Landing on the days of a preset (this month, last 60 days,
 * this year…) gives back that preset, so it keeps following today.
 */
export function shiftTimeframe(timeframe: Timeframe, direction: -1 | 1, today: Date = new Date()): Timeframe {
  switch (unitOf(timeframe.preset)) {
    case 'month': {
      const month = addMonths(startOfMonth(timeframe.from), direction)
      return {
        preset: isSameMonth(month, today) ? 'currentMonth' : 'month',
        from: startOfMonth(month),
        to: endOfMonth(month),
      }
    }
    case 'year': {
      const year = getYear(timeframe.from) + direction
      if (year === getYear(today)) return { preset: 'currentYear', ...rangeForPreset('currentYear', today) }
      if (year === getYear(today) - 1) return { preset: 'previousYear', ...rangeForPreset('previousYear', today) }
      const start = addYears(startOfYear(timeframe.from), direction)
      return { preset: 'year', from: start, to: endOfYear(start) }
    }
    case 'window': {
      const days = (differenceInCalendarDays(timeframe.to, timeframe.from) + 1) * direction
      const from = addDays(timeframe.from, days)
      const to = addDays(timeframe.to, days)
      return { preset: presetForRange(from, to, today), from, to }
    }
    case 'none':
      return timeframe
  }
}

function loadStoredTimeframe(): Timeframe {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const stored: StoredTimeframe = JSON.parse(raw)
      // Saved by an older version: a given month.
      const preset = stored.preset === 'previousMonth' || stored.preset === 'nextMonth' ? 'month' : stored.preset

      if (ROLLING_PRESETS.includes(preset)) {
        return { preset, ...rangeForPreset(preset) }
      }
      return { preset, from: new Date(stored.from), to: new Date(stored.to) }
    }
  } catch {
    // Fall through to default
  }

  const { from, to } = rangeForPreset('currentMonth')
  return { preset: 'currentMonth', from, to }
}

interface TimeframeContextType {
  timeframe: Timeframe
  setPreset: (preset: TimeframePreset) => void | Promise<void>
  setCustomRange: (from: Date, to: Date) => void
  /** To the previous (-1) or next (+1) period; nothing for "all". */
  shift: (direction: -1 | 1) => void
}

const TimeframeContext = createContext<TimeframeContextType | undefined>(undefined)

export function TimeframeProvider({ children }: { children: ReactNode }) {
  const [timeframe, setTimeframe] = useState<Timeframe>(() => loadStoredTimeframe())

  useEffect(() => {
    const stored: StoredTimeframe = {
      preset: timeframe.preset,
      from: timeframe.from.toISOString(),
      to: timeframe.to.toISOString(),
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(stored))
    } catch {
      // not remembered, no harm done
    }
  }, [timeframe])

  const setPreset = async (preset: TimeframePreset) => {
    if (preset === 'custom') {
      setTimeframe((current) => ({ ...current, preset: 'custom' }))
      return
    }

    if (preset === 'all') {
      try {
        const { earliest, latest } = await transactionsApi.dateRange()
        setTimeframe({
          preset: 'all',
          from: earliest !== null ? new Date(earliest) : ALL_TIME_FROM,
          to: latest !== null ? new Date(latest) : new Date(),
        })
      } catch {
        const { from, to } = rangeForPreset('all')
        setTimeframe({ preset: 'all', from, to })
      }
      return
    }

    const { from, to } = rangeForPreset(preset)
    setTimeframe({ preset, from, to })
  }

  const setCustomRange = (from: Date, to: Date) => {
    setTimeframe({ preset: presetForRange(from, to), from, to })
  }

  const shift = (direction: -1 | 1) => {
    setTimeframe((current) => shiftTimeframe(current, direction))
  }

  return (
    <TimeframeContext.Provider value={{ timeframe, setPreset, setCustomRange, shift }}>
      {children}
    </TimeframeContext.Provider>
  )
}

export function useTimeframe() {
  const context = useContext(TimeframeContext)
  if (context === undefined) {
    throw new Error('useTimeframe must be used within a TimeframeProvider')
  }
  return context
}
