import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format, isSameDay } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { cn, formatMoney, moneyColorClass } from '@/lib/utils'
import { niceTicks, smoothPath, splitAtToday, useContainerWidth, axisLeft, moneyTickLabels } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'
import type { PositionHistoryPoint } from '@/types/investment'

const VALUE_COLOR = 'hsl(var(--series-1))'
const COST_BASIS_COLOR = 'hsl(var(--series-2))'
const TODAY_COLOR = 'hsl(var(--series-8))'

const DEFAULT_HEIGHT = 176
const PADDING = { top: 16, right: 8, bottom: 22, left: 52 }
/** Below this horizontal spacing the per-day dots would merge into a thick line, so only the hovered one is drawn. */
const MIN_STEP_FOR_DOTS = 6

interface PositionMiniChartProps {
  history: PositionHistoryPoint[]
  currency?: string | null
  /** In pixels: the compact default fits a position card. */
  height?: number
}

/**
 * Value vs. invested capital of one open position (or of a whole portfolio)
 * over the selected range — a compact sibling of AccountValueHistoryChart
 * (same curve, per-day dots, hover tooltip, "today" marker), sized by default
 * to fit inside a position card.
 */
export default function PositionMiniChart({ history, currency, height = DEFAULT_HEIGHT }: PositionMiniChartProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(320)

  // Days before the position was opened are all zeros: they'd only flatten the chart against the axis.
  const points = useMemo(() => {
    const firstHeld = history.findIndex((p) => Number(p.value) !== 0 || Number(p.costBasis) !== 0)
    return firstHeld < 0 ? [] : history.slice(firstHeld)
  }, [history])

  const series = useMemo(
    () => [
      { key: 'value' as const, label: t('accountDetail.positionValue'), color: VALUE_COLOR },
      { key: 'costBasis' as const, label: t('accountDetail.positionCostBasis'), color: COST_BASIS_COLOR },
    ],
    [t]
  )

  const chart = useMemo(() => {
    if (points.length < 2) return null

    const allValues = points.flatMap((p) => [Number(p.value), Number(p.costBasis)])
    const dataMin = Math.min(...allValues)
    const dataMax = Math.max(...allValues)

    const ticks = niceTicks(dataMin, dataMax, 3)
    const tickLabels = moneyTickLabels(ticks, currency)
    const left = axisLeft(tickLabels, PADDING.left)
    const min = Math.min(dataMin, ticks[0])
    const max = Math.max(dataMax, ticks[ticks.length - 1])
    const span = max - min || 1

    const innerWidth = width - left - PADDING.right
    const innerHeight = height - PADDING.top - PADDING.bottom
    const stepX = innerWidth / (points.length - 1)
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight

    const todayIndex = points.findIndex((p) => isSameDay(new Date(p.date), new Date()))
    const todayX = todayIndex >= 0 ? left + todayIndex * stepX : null

    const lines = series.map((s) => {
      const coords = points.map((p, index) => ({ x: left + index * stepX, y: yFor(Number(p[s.key])) }))
      const { solid, dashed } = splitAtToday(coords, todayIndex)
      return {
        ...s,
        coords,
        solidPath: smoothPath(solid),
        dashedPath: dashed.length > 1 ? smoothPath(dashed) : null,
      }
    })

    return { left, tickLabels, lines, ticks, yFor, stepX, innerHeight, todayX, showDots: stepX >= MIN_STEP_FOR_DOTS }
  }, [points, series, width, height, currency])

  const hovered = hoveredIndex !== null ? points[hoveredIndex] : null
  const labelEvery = Math.max(1, Math.ceil(points.length / 4))
  const hoveredX = chart && hoveredIndex !== null ? chart.left + hoveredIndex * chart.stepX : 0
  const hoveredGain = hovered ? Number(hovered.value) - Number(hovered.costBasis) : 0
  const hoveredY = chart && hoveredIndex !== null ? Math.min(...chart.lines.map((l) => l.coords[hoveredIndex].y)) : 0

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap justify-end gap-3">
        {series.map((s) => (
          <span key={s.key} className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.color }} />
            {s.label}
          </span>
        ))}
      </div>

      <div ref={containerRef} className="relative" style={{ height }}>
        {!chart ? (
          <div className="flex h-full items-center justify-center text-sm text-muted-foreground">{t('charts.noData')}</div>
        ) : (
          <>
            <svg viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" {...chartTouch} aria-label={t('accountDetail.positionValue')}>
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

              {chart.lines.map((line) => (
                <g key={line.key}>
                  <path d={line.solidPath} fill="none" stroke={line.color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                  {line.dashedPath && (
                    <path
                      d={line.dashedPath}
                      fill="none"
                      stroke={line.color}
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
                  <text x={chart.todayX} y={PADDING.top - 4} fontSize={10} textAnchor="middle" fill={TODAY_COLOR}>
                    {t('charts.today')}
                  </text>
                </g>
              )}

              {hoveredIndex !== null && (
                <line
                  x1={hoveredX}
                  x2={hoveredX}
                  y1={PADDING.top}
                  y2={PADDING.top + chart.innerHeight}
                  stroke="hsl(var(--border))"
                  strokeWidth={1}
                />
              )}

              {chart.lines.map((line) =>
                line.coords.map((c, index) =>
                  chart.showDots || hoveredIndex === index ? (
                    <circle
                      key={`${line.key}-${points[index].date}`}
                      cx={c.x}
                      cy={c.y}
                      r={hoveredIndex === index ? 4 : 2.5}
                      fill={line.color}
                      stroke="hsl(var(--card))"
                      strokeWidth={1.5}
                      className="pointer-events-none transition-[r] duration-100"
                    />
                  ) : null
                )
              )}

              {points.map((p, index) => (
                <rect
                  key={p.date}
                  x={chart.left + index * chart.stepX - chart.stepX / 2}
                  y={0}
                  width={chart.stepX}
                  height={height}
                  fill="transparent"
                  onPointerEnter={() => setHoveredIndex(index)}
                  onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
                />
              ))}

              {points.map((p, index) =>
                index % labelEvery === 0 ? (
                  <text
                    key={`label-${p.date}`}
                    x={chart.left + index * chart.stepX}
                    y={height - 6}
                    fontSize={10}
                    textAnchor="middle"
                    fill="hsl(var(--muted-foreground))"
                  >
                    {format(new Date(p.date), 'd MMM', { locale })}
                  </text>
                ) : null
              )}
            </svg>

            {hovered && hoveredIndex !== null && (
              <div
                className="pointer-events-none absolute z-10 flex flex-col gap-0.5 rounded-md bg-background/95 px-2 py-1 shadow-sm"
                style={{ left: hoveredX, top: hoveredY, transform: tooltipTransform(hoveredX, width, hoveredY) }}
              >
                <span className="whitespace-nowrap text-xs text-muted-foreground">
                  {format(new Date(hovered.date), 'd MMMM yyyy', { locale })}
                </span>
                {chart.lines.map((line) => (
                  <span key={line.key} className="flex items-center gap-1.5 whitespace-nowrap text-xs">
                    <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: line.color }} />
                    <span className="text-muted-foreground">{line.label}:</span>
                    <span className="font-semibold text-foreground">{formatMoney(hovered[line.key], currency)}</span>
                  </span>
                ))}
                <span className="whitespace-nowrap text-xs">
                  <span className="text-muted-foreground">{t('accountDetail.gain')}:</span>{' '}
                  <span className={cn('font-semibold', moneyColorClass(hoveredGain))}>
                    {hoveredGain >= 0 ? '+' : ''}
                    {formatMoney(hoveredGain, currency)}
                  </span>
                </span>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
