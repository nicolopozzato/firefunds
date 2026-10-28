import { useMemo, useState, type ReactNode } from 'react'
import { niceTicks, smoothPath, useContainerWidth, axisLeft, moneyTickLabels } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'

const PADDING = { top: 16, right: 16, bottom: 28, left: 64 }

export interface MonthlySeries {
  id: string
  color: string
  /** One per month; null where there is no value (a month still to come). */
  values: (number | null)[]
  dashed?: boolean
}

interface MonthlyLineChartProps {
  /** One label per month, under the axis. */
  labels: string[]
  series: MonthlySeries[]
  currency: string | null
  emptyLabel: string
  tooltip: (index: number) => ReactNode
  height?: number
}

/** Lines over the twelve months of a year, e.g. this year's net worth against last year's. */
export default function MonthlyLineChart({ labels, series, currency, emptyLabel, tooltip, height = 280 }: MonthlyLineChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(720)

  const chart = useMemo(() => {
    const values = series.flatMap((s) => s.values.filter((value): value is number => value !== null))
    if (values.length === 0) return null

    const ticks = niceTicks(Math.min(...values), Math.max(...values))
    const tickLabels = moneyTickLabels(ticks, currency)
    const left = axisLeft(tickLabels, PADDING.left)
    const min = ticks[0]
    const max = ticks[ticks.length - 1]
    const span = max - min || 1

    const innerWidth = width - left - PADDING.right
    const innerHeight = height - PADDING.top - PADDING.bottom
    const slot = innerWidth / labels.length
    const xFor = (index: number) => left + slot * index + slot / 2
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight

    const lines = series.map((s) => {
      const coords = s.values.flatMap((value, index) => (value === null ? [] : [{ x: xFor(index), y: yFor(value) }]))
      return { ...s, path: smoothPath(coords), coords }
    })

    return { ticks, tickLabels, left, slot, xFor, yFor, lines, innerHeight }
  }, [series, labels.length, width, height, currency])

  const hoveredX = chart && hoveredIndex !== null ? chart.xFor(hoveredIndex) : 0

  return (
    <div ref={containerRef} className="relative" style={{ height }}>
      {!chart ? (
        <div className="flex h-full items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
      ) : (
        <>
          <svg viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" {...chartTouch}>
            {chart.ticks.map((tick, tickIndex) => (
              <g key={tick}>
                <line x1={chart.left} x2={width - PADDING.right} y1={chart.yFor(tick)} y2={chart.yFor(tick)} stroke="hsl(var(--border))" strokeWidth={1} strokeDasharray="3 3" />
                <text x={chart.left - 8} y={chart.yFor(tick)} dy="0.32em" fontSize={10} textAnchor="end" fill="hsl(var(--muted-foreground))">
                  {chart.tickLabels[tickIndex]}
                </text>
              </g>
            ))}

            {hoveredIndex !== null && (
              <line x1={hoveredX} x2={hoveredX} y1={PADDING.top} y2={PADDING.top + chart.innerHeight} stroke="hsl(var(--border))" strokeWidth={1} />
            )}

            {chart.lines.map((line) => (
              <g key={line.id}>
                <path
                  d={line.path}
                  fill="none"
                  stroke={line.color}
                  strokeWidth={line.dashed ? 1.5 : 2.5}
                  strokeDasharray={line.dashed ? '5 4' : undefined}
                  strokeLinejoin="round"
                  strokeLinecap="round"
                />
                {line.coords.map((point, index) => (
                  <circle key={index} cx={point.x} cy={point.y} r={line.dashed ? 2 : 3} fill={line.color} />
                ))}
              </g>
            ))}

            {hoveredIndex !== null &&
              chart.lines.map((line) => {
                const value = line.values[hoveredIndex]
                return value === null ? null : (
                  <circle key={`hover-${line.id}`} cx={hoveredX} cy={chart.yFor(value)} r={5} fill={line.color} stroke="hsl(var(--card))" strokeWidth={2} className="pointer-events-none" />
                )
              })}

            {labels.map((_, index) => (
              <rect
                key={`hit-${index}`}
                x={chart.xFor(index) - chart.slot / 2}
                y={0}
                width={chart.slot}
                height={height}
                fill="transparent"
                onPointerEnter={() => setHoveredIndex(index)}
                onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
              />
            ))}

            {labels.map((label, index) => (
              <text key={`label-${index}`} x={chart.xFor(index)} y={height - 8} fontSize={10} textAnchor="middle" fill="hsl(var(--muted-foreground))">
                {label}
              </text>
            ))}
          </svg>

          {hoveredIndex !== null && (
            <div
              className="pointer-events-none absolute z-10 flex flex-col gap-0.5 chart-tooltip"
              style={{ left: hoveredX, top: PADDING.top, transform: tooltipTransform(hoveredX, width) }}
            >
              {tooltip(hoveredIndex)}
            </div>
          )}
        </>
      )}
    </div>
  )
}
