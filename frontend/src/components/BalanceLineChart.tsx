import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format, isSameDay } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { formatMoney } from '@/lib/utils'
import { niceTicks, smoothPath, useContainerWidth, axisLeft, moneyTickLabels } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'
import type { BalancePoint } from '@/types/account'

const LINE_COLOR = 'hsl(var(--series-1))'
const FILL_COLOR = 'hsl(var(--series-1) / 0.12)'
const TODAY_COLOR = 'hsl(var(--series-8))'
const PADDING = { top: 16, right: 16, bottom: 28, left: 56 }

interface BalanceLineChartProps {
  title: string
  points: BalancePoint[]
  currency?: string | null
  emptyLabel: string
}

export default function BalanceLineChart({ title, points, currency, emptyLabel }: BalanceLineChartProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(720)

  const height = 320

  const chart = useMemo(() => {
    if (points.length === 0) return null

    const values = points.map((p) => Number(p.balance))
    const dataMin = Math.min(...values)
    const dataMax = Math.max(...values)

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

    const coords = values.map((value, index) => ({
      x: left + index * stepX,
      y: yFor(value),
      value,
      date: points[index].date,
    }))

    const linePath = smoothPath(coords)
    const areaPath = `${linePath} L ${coords[coords.length - 1].x} ${PADDING.top + innerHeight} L ${coords[0].x} ${PADDING.top + innerHeight} Z`

    const today = new Date()
    const todayIndex = points.findIndex((p) => isSameDay(new Date(p.date), today))
    const todayX = todayIndex >= 0 ? left + todayIndex * stepX : null

    return { left, tickLabels, coords, linePath, areaPath, innerHeight, ticks, yFor, todayX }
  }, [points, width, currency])

  if (!chart) {
    return (
      <div className="rounded-xl border bg-card p-4">
        <h3 className="mb-3 text-sm font-semibold text-foreground">{title}</h3>
        <div className="flex h-72 items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
      </div>
    )
  }

  const hovered = hoveredIndex !== null ? chart.coords[hoveredIndex] : null
  const labelEvery = Math.max(1, Math.ceil(points.length / 6))

  return (
    <div className="rounded-xl border bg-card p-4">
      <h3 className="mb-3 text-sm font-semibold text-foreground">{title}</h3>
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

          <path d={chart.areaPath} fill={FILL_COLOR} stroke="none" />
          <path d={chart.linePath} fill="none" stroke={LINE_COLOR} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />

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

          {chart.coords.map((c, index) => (
            <g key={c.date}>
              <rect
                x={c.x - (width / points.length) / 2}
                y={0}
                width={width / points.length}
                height={height}
                fill="transparent"
                onPointerEnter={() => setHoveredIndex(index)}
                onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
              />
              {hoveredIndex === index && (
                <line x1={c.x} y1={PADDING.top} x2={c.x} y2={PADDING.top + chart.innerHeight} stroke="hsl(var(--border))" strokeWidth={1} />
              )}
              <circle
                cx={c.x}
                cy={c.y}
                r={hoveredIndex === index ? 4 : 2.5}
                fill={LINE_COLOR}
                stroke="hsl(var(--card))"
                strokeWidth={1.5}
                className="pointer-events-none transition-[r] duration-100"
              />
            </g>
          ))}

          {chart.coords.map((c, index) =>
            index % labelEvery === 0 ? (
              <text key={`label-${c.date}`} x={c.x} y={height - 8} fontSize={10} textAnchor="middle" fill="hsl(var(--muted-foreground))">
                {format(new Date(c.date), 'd MMM', { locale })}
              </text>
            ) : null
          )}
        </svg>

        {hovered && (
          <div
            className="pointer-events-none absolute flex flex-col items-center rounded-md bg-background/95 px-2 py-1 text-center shadow-sm"
            style={{ left: hovered.x, top: hovered.y, transform: tooltipTransform(hovered.x, width, hovered.y) }}
          >
            <span className="whitespace-nowrap text-xs text-muted-foreground">
              {format(new Date(hovered.date), 'd MMMM yyyy', { locale })}
            </span>
            <span className="text-sm font-semibold text-foreground">{formatMoney(hovered.value, currency)}</span>
          </div>
        )}
      </div>
    </div>
  )
}
