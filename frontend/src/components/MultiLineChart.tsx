import { useMemo, useState, type PointerEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { format, isSameDay, parseISO } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { cn } from '@/lib/utils'
import { niceTicks, smoothPath, useContainerWidth } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import type { DatedValue } from '@/types/dashboard'

const TODAY_COLOR = 'hsl(var(--series-8))'
const PADDING = { top: 16, right: 16, bottom: 28, left: 64 }

export interface LineSeries {
  id: string
  label: string
  color: string
  /** May start later than the first day (e.g. no price yet): the line starts there. */
  points: DatedValue[]
  /** Shown on the right of the series' legend entry. */
  detail?: ReactNode
}

interface MultiLineChartProps {
  /** Every day of the x axis, Y-m-d, oldest first. */
  days: string[]
  series: LineSeries[]
  /** Tooltip values, and axis ticks unless formatTick is given. */
  formatValue: (value: number) => string
  formatTick?: (value: number) => string
  emptyLabel: string
  height?: number
  /** Draws a solid line at this value, e.g. 0 for a performance in percent. */
  baseline?: number
  /** Columns of the legend on wide screens (one on phones; 3 drops to 2 on tablets). */
  legendColumns?: 1 | 2 | 3
}

/**
 * Several series over the same days, one axis. Clicking a legend entry hides
 * or shows its line; the axis follows the lines shown.
 */
export default function MultiLineChart({
  days,
  series,
  formatValue,
  formatTick = formatValue,
  emptyLabel,
  height = 300,
  baseline,
  legendColumns = 2,
}: MultiLineChartProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const [hiddenIds, setHiddenIds] = useState<Set<string>>(new Set())
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(720)

  const visible = useMemo(() => series.filter((s) => !hiddenIds.has(s.id) && s.points.length > 0), [series, hiddenIds])

  const chart = useMemo(() => {
    if (days.length === 0 || visible.length === 0) return null

    const indexOf = new Map(days.map((day, index) => [day, index]))
    const values = visible.flatMap((s) => s.points.map((p) => Number(p.value)))
    if (baseline !== undefined) values.push(baseline)
    const dataMin = Math.min(...values)
    const dataMax = Math.max(...values)

    const ticks = niceTicks(dataMin, dataMax)
    const min = Math.min(dataMin, ticks[0])
    const max = Math.max(dataMax, ticks[ticks.length - 1])
    const span = max - min || 1

    const innerWidth = width - PADDING.left - PADDING.right
    const innerHeight = height - PADDING.top - PADDING.bottom
    const stepX = days.length > 1 ? innerWidth / (days.length - 1) : 0
    const xFor = (index: number) => PADDING.left + index * stepX
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight

    const lines = visible.map((s) => {
      const byIndex = new Map<number, number>()
      const coords = s.points.flatMap((p) => {
        const index = indexOf.get(p.date)
        if (index === undefined) return []
        byIndex.set(index, Number(p.value))
        return [{ x: xFor(index), y: yFor(Number(p.value)) }]
      })
      return { ...s, path: smoothPath(coords), byIndex }
    })

    const todayIndex = days.findIndex((day) => isSameDay(parseISO(day), new Date()))

    // Over a long period the lines have a point every few days: hovering snaps to the nearest one.
    const pointIndexes = [...new Set(lines.flatMap((line) => [...line.byIndex.keys()]))].sort((a, b) => a - b)
    const nearestIndex = (offsetX: number): number | null => {
      if (pointIndexes.length === 0) return null
      const target = stepX > 0 ? offsetX / stepX : 0
      let low = 0
      let high = pointIndexes.length - 1
      while (low < high) {
        const middle = (low + high) >> 1
        if (pointIndexes[middle] < target) low = middle + 1
        else high = middle
      }
      const previous = pointIndexes[Math.max(0, low - 1)]
      return Math.abs(previous - target) < Math.abs(pointIndexes[low] - target) ? previous : pointIndexes[low]
    }

    return { lines, ticks, xFor, yFor, nearestIndex, innerHeight, todayX: todayIndex >= 0 ? xFor(todayIndex) : null }
  }, [days, visible, width, height, baseline])

  /** The day nearest to the pointer's x on the plot area. */
  const showNearest = (event: PointerEvent<SVGRectElement>) => {
    if (!chart) return
    const box = event.currentTarget.getBoundingClientRect()
    setHoveredIndex(chart.nearestIndex(event.clientX - box.left))
  }

  const toggle = (id: string) =>
    setHiddenIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const dayFormat = days.length > 120 ? 'MMM yyyy' : 'd MMM'
  // At most six dates under the axis, fewer when they wouldn't fit (phones): about 64px for "set 2026", 44px for "15 set".
  const fittingLabels = Math.max(2, Math.floor((width - PADDING.left - PADDING.right) / (dayFormat === 'MMM yyyy' ? 64 : 44)))
  const labelEvery = Math.max(1, Math.ceil(days.length / Math.min(6, fittingLabels)))
  const hoveredX = hoveredIndex !== null && chart ? chart.xFor(hoveredIndex) : 0
  const hoveredValues =
    hoveredIndex !== null && chart
      ? chart.lines.flatMap((line) => {
          const value = line.byIndex.get(hoveredIndex)
          return value === undefined ? [] : [{ line, value }]
        })
      : []

  return (
    <div className="space-y-3">
      <div ref={containerRef} className="relative">
        {!chart ? (
          <div className="flex items-center justify-center text-sm text-muted-foreground" style={{ height }}>
            {emptyLabel}
          </div>
        ) : (
          <>
            <svg viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" {...chartTouch} onPointerLeave={onMouseLeave(() => setHoveredIndex(null))}>
              {chart.ticks.map((tick) => (
                <g key={tick}>
                  <line
                    x1={PADDING.left}
                    x2={width - PADDING.right}
                    y1={chart.yFor(tick)}
                    y2={chart.yFor(tick)}
                    stroke="hsl(var(--border))"
                    strokeWidth={1}
                    strokeDasharray={tick === baseline ? undefined : '3 3'}
                  />
                  <text x={PADDING.left - 8} y={chart.yFor(tick)} dy="0.32em" fontSize={10} textAnchor="end" fill="hsl(var(--muted-foreground))">
                    {formatTick(tick)}
                  </text>
                </g>
              ))}

              {chart.todayX !== null && (
                <g>
                  <line
                    x1={chart.todayX}
                    x2={chart.todayX}
                    y1={PADDING.top}
                    y2={PADDING.top + chart.innerHeight}
                    stroke={TODAY_COLOR}
                    strokeWidth={1}
                    strokeDasharray="4 3"
                  />
                  <text x={chart.todayX} y={PADDING.top - 4} fontSize={10} textAnchor="middle" fill={TODAY_COLOR}>
                    {t('charts.today')}
                  </text>
                </g>
              )}

              {hoveredIndex !== null && (
                <line x1={hoveredX} x2={hoveredX} y1={PADDING.top} y2={PADDING.top + chart.innerHeight} stroke="hsl(var(--border))" strokeWidth={1} />
              )}

              {chart.lines.map((line) => (
                <path key={line.id} d={line.path} fill="none" stroke={line.color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
              ))}

              {hoveredValues.map(({ line, value }) => (
                <circle
                  key={line.id}
                  cx={hoveredX}
                  cy={chart.yFor(value)}
                  r={4}
                  fill={line.color}
                  stroke="hsl(var(--card))"
                  strokeWidth={2}
                  className="pointer-events-none"
                />
              ))}

              <rect
                x={PADDING.left}
                y={0}
                width={Math.max(width - PADDING.left - PADDING.right, 1)}
                height={height}
                fill="transparent"
                // Down as well as move: a tap shows its day without sliding.
                onPointerDown={showNearest}
                onPointerMove={showNearest}
              />

              {days.map((day, index) =>
                index % labelEvery === 0 ? (
                  <text key={`label-${day}`} x={chart.xFor(index)} y={height - 8} fontSize={10} textAnchor="middle" fill="hsl(var(--muted-foreground))">
                    {format(parseISO(day), dayFormat, { locale })}
                  </text>
                ) : null
              )}
            </svg>

            {hoveredIndex !== null && hoveredValues.length > 0 && (
              <div
                className="pointer-events-none absolute top-2 z-10 flex flex-col gap-0.5 rounded-md bg-background/95 px-2 py-1 shadow-sm"
                style={{ left: hoveredX, transform: hoveredX > width / 2 ? 'translateX(calc(-100% - 12px))' : 'translateX(12px)' }}
              >
                <span className="whitespace-nowrap text-xs text-muted-foreground">
                  {format(parseISO(days[hoveredIndex]), 'd MMMM yyyy', { locale })}
                </span>
                {[...hoveredValues]
                  .sort((a, b) => b.value - a.value)
                  .map(({ line, value }) => (
                    <span key={line.id} className="flex items-center gap-1.5 whitespace-nowrap text-xs">
                      <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: line.color }} />
                      <span className="text-muted-foreground">{line.label}:</span>
                      <span className="font-semibold tabular-nums text-foreground">{formatValue(value)}</span>
                    </span>
                  ))}
              </div>
            )}
          </>
        )}
      </div>

      {series.length > 0 && (
        <ul className={cn('grid gap-x-6 gap-y-1', legendColumns === 2 && 'sm:grid-cols-2', legendColumns === 3 && 'sm:grid-cols-2 lg:grid-cols-3')}>
          {series.map((s) => {
            const isHidden = hiddenIds.has(s.id)
            return (
              <li key={s.id}>
                <button
                  type="button"
                  aria-pressed={!isHidden}
                  onClick={() => toggle(s.id)}
                  className={cn(
                    'flex w-full items-center gap-2 rounded-md px-1 py-0.5 text-left text-sm transition-colors hover:bg-accent',
                    isHidden && 'opacity-50'
                  )}
                >
                  <span
                    className="h-2.5 w-2.5 shrink-0 rounded-full border-2"
                    style={{ backgroundColor: isHidden ? 'transparent' : s.color, borderColor: s.color }}
                    aria-hidden
                  />
                  <span className={cn('min-w-0 flex-1 truncate text-foreground', isHidden && 'line-through')}>{s.label}</span>
                  {s.detail && <span className="shrink-0 text-xs tabular-nums">{s.detail}</span>}
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
