import { useMemo, useState, type ReactNode } from 'react'
import { niceTicks, useContainerWidth, axisLeft, moneyTickLabels } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'

const PADDING = { top: 16, right: 8, bottom: 28, left: 64 }
/** Corner radius of a bar's data end; the end on the zero line stays square. */
const RADIUS = 3
/** Room left free on each side of a group of bars. */
const GROUP_GAP = 6
/** Surface gap between the bars of a group. */
const BAR_GAP = 2
const MAX_BAR_WIDTH = 28

export interface BarSeries {
  id: string
  label: string
  color: string
  /** One per group; null draws nothing (e.g. a month still to come). */
  values: (number | null)[]
}

interface GroupedBarChartProps {
  /** One label per group, under its bars. */
  labels: string[]
  series: BarSeries[]
  currency: string | null
  emptyLabel: string
  /** What the tooltip shows for a group. */
  tooltip: (index: number) => ReactNode
  height?: number
}

/** A bar from the zero line to `y`, rounded only on the end away from it. */
function barPath(x: number, width: number, zeroY: number, y: number): string {
  const height = Math.abs(zeroY - y)
  const r = Math.min(RADIUS, width / 2, height)
  if (y < zeroY) {
    return `M${x},${zeroY} V${y + r} Q${x},${y} ${x + r},${y} H${x + width - r} Q${x + width},${y} ${x + width},${y + r} V${zeroY} Z`
  }
  return `M${x},${zeroY} V${y - r} Q${x},${y} ${x + r},${y} H${x + width - r} Q${x + width},${y} ${x + width},${y - r} V${zeroY} Z`
}

/** The series' legend, for the card's header. */
export function BarLegend({ series }: { series: Pick<BarSeries, 'id' | 'label' | 'color'>[] }) {
  return (
    <div className="flex flex-wrap gap-3">
      {series.map((s) => (
        <span key={s.id} className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.color }} />
          {s.label}
        </span>
      ))}
    </div>
  )
}

/**
 * Bars side by side for each group (e.g. income and expenses of a month, or
 * this year's change against last year's), from a zero line: negative
 * values go below it.
 */
export default function GroupedBarChart({ labels, series, currency, emptyLabel, tooltip, height = 260 }: GroupedBarChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(720)

  const hasData = series.some((s) => s.values.some((value) => value !== null && value !== 0))

  const chart = useMemo(() => {
    if (!hasData || labels.length === 0) return null

    const values = series.flatMap((s) => s.values.filter((value): value is number => value !== null))
    const ticks = niceTicks(Math.min(0, ...values), Math.max(0, ...values))
    const tickLabels = moneyTickLabels(ticks, currency)
    const left = axisLeft(tickLabels, PADDING.left)
    const min = ticks[0]
    const max = ticks[ticks.length - 1]
    const span = max - min || 1

    const innerWidth = width - left - PADDING.right
    const innerHeight = height - PADDING.top - PADDING.bottom
    const slot = innerWidth / labels.length
    const barWidth = Math.max(1, Math.min(MAX_BAR_WIDTH, (slot - GROUP_GAP * 2 - BAR_GAP * (series.length - 1)) / series.length))
    const groupWidth = barWidth * series.length + BAR_GAP * (series.length - 1)
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight
    const zeroY = yFor(0)

    const groups = labels.map((label, index) => {
      const x = left + index * slot + (slot - groupWidth) / 2
      return {
        label,
        centerX: left + index * slot + slot / 2,
        bars: series.map((s, seriesIndex) => {
          const value = s.values[index]
          const barX = x + seriesIndex * (barWidth + BAR_GAP)
          return {
            id: s.id,
            color: s.color,
            path: value !== null && value !== 0 ? barPath(barX, barWidth, zeroY, yFor(value)) : null,
          }
        }),
      }
    })

    return { left, tickLabels, ticks, yFor, slot, groups }
  }, [hasData, labels, series, width, height, currency])

  // Labels under the bars: all of them while they fit, else every few.
  const labelEvery = chart ? Math.max(1, Math.ceil(labels.length / Math.max(1, Math.floor((width - chart.left) / 56)))) : 1
  const hovered = chart && hoveredIndex !== null ? chart.groups[hoveredIndex] : null

  return (
    <div ref={containerRef} className="relative" style={{ height }}>
      {!chart ? (
        <div className="flex h-full items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
      ) : (
        <>
          <svg viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" {...chartTouch}>
            {chart.ticks.map((tick, tickIndex) => (
              <g key={tick}>
                <line
                  x1={chart.left}
                  x2={width - PADDING.right}
                  y1={chart.yFor(tick)}
                  y2={chart.yFor(tick)}
                  stroke="hsl(var(--border))"
                  strokeWidth={1}
                  strokeDasharray={tick === 0 ? undefined : '3 3'}
                />
                <text x={chart.left - 8} y={chart.yFor(tick)} dy="0.32em" fontSize={10} textAnchor="end" fill="hsl(var(--muted-foreground))">
                  {chart.tickLabels[tickIndex]}
                </text>
              </g>
            ))}

            {hovered && (
              <rect
                x={hovered.centerX - chart.slot / 2}
                y={PADDING.top}
                width={chart.slot}
                height={height - PADDING.top - PADDING.bottom}
                fill="hsl(var(--muted))"
                opacity={0.6}
                className="pointer-events-none"
              />
            )}

            {chart.groups.map((group, index) =>
              group.bars.map((bar) =>
                bar.path ? (
                  <path
                    key={`${index}-${bar.id}`}
                    d={bar.path}
                    fill={bar.color}
                    opacity={hoveredIndex === null || hoveredIndex === index ? 1 : 0.55}
                    className="pointer-events-none transition-opacity duration-100"
                  />
                ) : null
              )
            )}

            {chart.groups.map((group, index) => (
              <rect
                key={`hit-${index}`}
                x={group.centerX - chart.slot / 2}
                y={0}
                width={chart.slot}
                height={height}
                fill="transparent"
                onPointerEnter={() => setHoveredIndex(index)}
                onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
              />
            ))}

            {chart.groups.map((group, index) =>
              index % labelEvery === 0 ? (
                <text key={`label-${index}`} x={group.centerX} y={height - 8} fontSize={10} textAnchor="middle" fill="hsl(var(--muted-foreground))">
                  {group.label}
                </text>
              ) : null
            )}
          </svg>

          {hovered && hoveredIndex !== null && (
            <div
              className="pointer-events-none absolute z-10 flex flex-col gap-0.5 rounded-md border bg-background/95 px-2.5 py-1.5 shadow-sm"
              style={{ left: hovered.centerX, top: PADDING.top, transform: tooltipTransform(hovered.centerX, width) }}
            >
              {tooltip(hoveredIndex)}
            </div>
          )}
        </>
      )}
    </div>
  )
}
