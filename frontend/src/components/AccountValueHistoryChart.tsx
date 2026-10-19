import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format, isSameDay } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { cn, formatMoney, parseDate } from '@/lib/utils'
import { niceTicks, smoothPath, splitAtToday, useContainerWidth, axisLeft, moneyTickLabels, todayLabelAnchor } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'
import type { AccountValueHistoryPoint } from '@/types/account'

const TODAY_COLOR = 'hsl(var(--series-8))'
const PADDING = { top: 16, right: 16, bottom: 28, left: 56 }

/** The tooltip at the hovered point, kept inside the card (tooltipTransform). */
function tooltipStyle(x: number, y: number, width: number) {
  return { left: x, top: y, transform: tooltipTransform(x, width, y) }
}

interface SeriesConfig {
  key: 'available' | 'invested' | 'total'
  label: string
  color: string
}

interface AccountValueHistoryChartProps {
  title: string
  points: AccountValueHistoryPoint[]
  currency?: string | null
  emptyLabel: string
}

export default function AccountValueHistoryChart({ title, points, currency, emptyLabel }: AccountValueHistoryChartProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(720)

  const seriesConfigs: SeriesConfig[] = useMemo(
    () => [
      { key: 'available', label: t('accountDetail.available'), color: 'hsl(var(--series-1))' },
      { key: 'invested', label: t('accountDetail.investments'), color: 'hsl(var(--series-2))' },
      { key: 'total', label: t('accountDetail.total'), color: 'hsl(var(--series-3))' },
    ],
    [t]
  )

  const [hiddenSeries, setHiddenSeries] = useState<Set<string>>(new Set())
  const visibleSeries = seriesConfigs.filter((s) => !hiddenSeries.has(s.key))

  const toggleSeries = (key: string) => {
    setHiddenSeries((current) => {
      const next = new Set(current)
      if (next.has(key)) {
        next.delete(key)
      } else {
        // Never let every series be hidden at once - toggling the last visible one back on.
        if (next.size < seriesConfigs.length - 1) {
          next.add(key)
        }
      }
      return next
    })
  }

  const height = 320

  const chart = useMemo(() => {
    if (points.length === 0 || visibleSeries.length === 0) return null

    const allValues = visibleSeries.flatMap((s) => points.map((p) => Number(p[s.key])))
    const dataMin = Math.min(...allValues)
    const dataMax = Math.max(...allValues)

    const ticks = niceTicks(dataMin, dataMax)
    const tickLabels = moneyTickLabels(ticks, currency)
    const left = axisLeft(tickLabels, PADDING.left)
    const min = Math.min(dataMin, ticks[0])
    const max = Math.max(dataMax, ticks[ticks.length - 1])
    const span = max - min || 1

    const innerWidth = width - left - PADDING.right
    const innerHeight = height - PADDING.top - PADDING.bottom

    const stepX = points.length > 1 ? innerWidth / (points.length - 1) : 0
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight

    const today = new Date()
    const todayIndex = points.findIndex((p) => isSameDay(parseDate(p.date), today))
    const todayX = todayIndex >= 0 ? left + todayIndex * stepX : null

    const series = visibleSeries.map((s) => {
      const coords = points.map((p, index) => ({
        x: left + index * stepX,
        y: yFor(Number(p[s.key])),
        value: Number(p[s.key]),
        date: p.date,
      }))
      const { solid, dashed } = splitAtToday(coords, todayIndex)
      return {
        ...s,
        coords,
        solidPath: smoothPath(solid),
        dashedPath: dashed.length > 1 ? smoothPath(dashed) : null,
      }
    })

    return { left, tickLabels, series, innerHeight, ticks, yFor, todayX, stepX }
  }, [points, width, visibleSeries, currency])

  if (points.length === 0) {
    return (
      <div className="rounded-xl border bg-card p-4">
        <h3 className="mb-3 text-sm font-semibold text-foreground">{title}</h3>
        <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
      </div>
    )
  }

  const hovered = hoveredIndex !== null ? points[hoveredIndex] : null
  const labelEvery = Math.max(1, Math.ceil(points.length / 6))

  return (
    <div className="rounded-xl border bg-card p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-foreground">{title}</h3>
        <div className="flex flex-wrap gap-3">
          {seriesConfigs.map((s) => {
            const isHidden = hiddenSeries.has(s.key)
            return (
              <button
                key={s.key}
                type="button"
                onClick={() => toggleSeries(s.key)}
                className={cn(
                  'flex items-center gap-1.5 text-xs transition-opacity',
                  isHidden ? 'text-muted-foreground opacity-50' : 'text-foreground'
                )}
                aria-pressed={!isHidden}
              >
                <span
                  className="h-2.5 w-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: isHidden ? 'hsl(var(--muted-foreground))' : s.color }}
                />
                {s.label}
              </button>
            )
          })}
        </div>
      </div>

      {!chart ? (
        <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
      ) : (
        <div ref={containerRef} className="relative">
          <svg viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" {...chartTouch} aria-label={title}>
            {chart.ticks.map((tick, tickIndex) => (
              <g key={tick}>
                <line
                  x1={chart.left}
                  x2={width - PADDING.right}
                  y1={chart.yFor(tick)}
                  y2={chart.yFor(tick)}
                  stroke="hsl(var(--border))"
                  strokeWidth={1}
                  strokeDasharray="3 3"
                />
                <text x={chart.left - 8} y={chart.yFor(tick)} dy="0.32em" fontSize={10} textAnchor="end" fill="hsl(var(--muted-foreground))">
                  {chart.tickLabels[tickIndex]}
                </text>
              </g>
            ))}

            {chart.series.map((s) => (
              <g key={s.key}>
                <path d={s.solidPath} fill="none" stroke={s.color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                {s.dashedPath && (
                  <path
                    d={s.dashedPath}
                    fill="none"
                    stroke={s.color}
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    strokeDasharray="5 4"
                    opacity={0.7}
                  />
                )}
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
                <text x={chart.todayX} y={PADDING.top - 4} fontSize={10} textAnchor={todayLabelAnchor(chart.todayX, width)} fill={TODAY_COLOR}>
                  {t('charts.today')}
                </text>
              </g>
            )}

            {chart.series.map((s) =>
              s.coords.map((c, index) => (
                <circle
                  key={`${s.key}-${c.date}`}
                  cx={c.x}
                  cy={c.y}
                  r={hoveredIndex === index ? 4 : 2.5}
                  fill={s.color}
                  stroke="hsl(var(--card))"
                  strokeWidth={1.5}
                  className="pointer-events-none transition-[r] duration-100"
                />
              ))
            )}

            {points.map((p, index) => (
              <g key={p.date}>
                <rect
                  x={chart.left + index * chart.stepX - chart.stepX / 2}
                  y={0}
                  width={Math.max(chart.stepX, width / points.length)}
                  height={height}
                  fill="transparent"
                  onPointerEnter={() => setHoveredIndex(index)}
                  onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
                />
                {hoveredIndex === index && (
                  <line
                    x1={chart.left + index * chart.stepX}
                    y1={PADDING.top}
                    x2={chart.left + index * chart.stepX}
                    y2={PADDING.top + chart.innerHeight}
                    stroke="hsl(var(--border))"
                    strokeWidth={1}
                  />
                )}
              </g>
            ))}

            {points.map((p, index) =>
              index % labelEvery === 0 ? (
                <text
                  key={`label-${p.date}`}
                  x={chart.left + index * chart.stepX}
                  y={height - 8}
                  fontSize={10}
                  textAnchor="middle"
                  fill="hsl(var(--muted-foreground))"
                >
                  {format(parseDate(p.date), 'd MMM', { locale })}
                </text>
              ) : null
            )}
          </svg>

          {hovered && chart && (
            <div
              className="pointer-events-none absolute flex flex-col items-center gap-0.5 rounded-md bg-background/95 px-2 py-1 text-center shadow-sm"
              style={tooltipStyle(chart.left + (hoveredIndex ?? 0) * chart.stepX, Math.min(...chart.series.map((s) => s.coords[hoveredIndex ?? 0].y)), width)}
            >
              <span className="whitespace-nowrap text-xs text-muted-foreground">
                {format(parseDate(hovered.date), 'd MMMM yyyy', { locale })}
              </span>
              {chart.series.map((s) => (
                <span key={s.key} className="flex items-center gap-1.5 whitespace-nowrap text-xs">
                  <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: s.color }} />
                  <span className="text-muted-foreground">{s.label}:</span>
                  <span className="font-semibold text-foreground">{formatMoney(s.coords[hoveredIndex ?? 0].value, currency)}</span>
                </span>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
