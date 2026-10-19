import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { it, enUS } from 'date-fns/locale'
import { formatMoney } from '@/lib/utils'
import { niceTicks, useContainerWidth, axisLeft, moneyTickLabels } from '@/lib/chart'
import { onMouseLeave, useChartTouch } from '@/lib/chart-touch'
import { tooltipTransform } from '@/lib/chart-tooltip'
import type { MonthlyInvestmentFlow } from '@/types/investment'

const PUT_IN_COLOR = 'hsl(var(--series-1))'
const TAKEN_OUT_COLOR = 'hsl(var(--series-2))'

const HEIGHT = 240
const PADDING = { top: 16, right: 8, bottom: 28, left: 64 }
/** Corner radius of a bar's data end; the end on the zero line stays square. */
const RADIUS = 4
/** Surface gap between adjacent bars, on each side. */
const GAP = 2
const MAX_BAR_WIDTH = 48

interface MonthlyFlowsChartProps {
  title: string
  flows: MonthlyInvestmentFlow[]
  currency?: string | null
  emptyLabel: string
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

/**
 * Net money put into investments month by month (BUYs minus SELLs): above
 * the line what went in, below it what came back out.
 */
export default function MonthlyFlowsChart({ title, flows, currency, emptyLabel }: MonthlyFlowsChartProps) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'it' ? it : enUS
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const chartTouch = useChartTouch(() => setHoveredIndex(null))
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(480)

  const hasData = flows.some((f) => Number(f.bought) !== 0 || Number(f.sold) !== 0)

  const chart = useMemo(() => {
    if (!hasData) return null

    const values = flows.map((f) => Number(f.net))
    const ticks = niceTicks(Math.min(0, ...values), Math.max(0, ...values))
    const tickLabels = moneyTickLabels(ticks, currency)
    const left = axisLeft(tickLabels, PADDING.left)
    const min = ticks[0]
    const max = ticks[ticks.length - 1]
    const span = max - min || 1

    const innerWidth = width - left - PADDING.right
    const innerHeight = HEIGHT - PADDING.top - PADDING.bottom
    const slot = innerWidth / flows.length
    const barWidth = Math.max(1, Math.min(MAX_BAR_WIDTH, slot - GAP * 2))
    const yFor = (value: number) => PADDING.top + innerHeight - ((value - min) / span) * innerHeight
    const zeroY = yFor(0)

    const bars = flows.map((f, index) => {
      const net = Number(f.net)
      const x = left + index * slot + (slot - barWidth) / 2
      return {
        flow: f,
        x,
        centerX: x + barWidth / 2,
        y: yFor(net),
        path: net !== 0 ? barPath(x, barWidth, zeroY, yFor(net)) : null,
        color: net >= 0 ? PUT_IN_COLOR : TAKEN_OUT_COLOR,
      }
    })

    return { left, tickLabels, bars, ticks, yFor, zeroY, slot, barWidth }
  }, [flows, hasData, width, currency])

  const monthLabel = (month: string, pattern: string) => format(new Date(`${month}-01T00:00:00`), pattern, { locale })
  const labelEvery = Math.max(1, Math.ceil(flows.length / 8))
  const hovered = chart && hoveredIndex !== null ? chart.bars[hoveredIndex] : null

  return (
    <div className="rounded-xl border bg-card p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-foreground">{title}</h3>
        {chart && (
          <div className="flex flex-wrap gap-3">
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: PUT_IN_COLOR }} />
              {t('investmentsOverview.flowPutIn')}
            </span>
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: TAKEN_OUT_COLOR }} />
              {t('investmentsOverview.flowTakenOut')}
            </span>
          </div>
        )}
      </div>

      <div ref={containerRef} className="relative" style={{ height: HEIGHT }}>
        {!chart ? (
          <div className="flex h-full items-center justify-center text-sm text-muted-foreground">{emptyLabel}</div>
        ) : (
          <>
            <svg viewBox={`0 0 ${width} ${HEIGHT}`} width={width} height={HEIGHT} role="img" {...chartTouch} aria-label={title}>
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

              {chart.bars.map((bar, index) =>
                bar.path ? (
                  <path
                    key={bar.flow.month}
                    d={bar.path}
                    fill={bar.color}
                    opacity={hoveredIndex === null || hoveredIndex === index ? 1 : 0.5}
                    className="pointer-events-none transition-opacity duration-100"
                  />
                ) : null
              )}

              {chart.bars.map((bar, index) => (
                <rect
                  key={`hit-${bar.flow.month}`}
                  x={chart.left + index * chart.slot}
                  y={0}
                  width={chart.slot}
                  height={HEIGHT}
                  fill="transparent"
                  onPointerEnter={() => setHoveredIndex(index)}
                  onPointerLeave={onMouseLeave(() => setHoveredIndex((current) => (current === index ? null : current)))}
                />
              ))}

              {chart.bars.map((bar, index) =>
                index % labelEvery === 0 ? (
                  <text
                    key={`label-${bar.flow.month}`}
                    x={bar.centerX}
                    y={HEIGHT - 8}
                    fontSize={10}
                    textAnchor="middle"
                    fill="hsl(var(--muted-foreground))"
                  >
                    {monthLabel(bar.flow.month, 'MMM yy')}
                  </text>
                ) : null
              )}
            </svg>

            {hovered && (
              <div
                className="pointer-events-none absolute z-10 flex flex-col gap-0.5 rounded-md bg-background/95 px-2 py-1 shadow-sm"
                style={{ left: hovered.centerX, top: PADDING.top, transform: tooltipTransform(hovered.centerX, width) }}
              >
                <span className="whitespace-nowrap text-xs text-muted-foreground">{monthLabel(hovered.flow.month, 'MMMM yyyy')}</span>
                <span className="whitespace-nowrap text-xs">
                  <span className="text-muted-foreground">{t('investmentsOverview.flowBought')}:</span>{' '}
                  <span className="font-semibold text-foreground">{formatMoney(hovered.flow.bought, currency)}</span>
                </span>
                <span className="whitespace-nowrap text-xs">
                  <span className="text-muted-foreground">{t('investmentsOverview.flowSold')}:</span>{' '}
                  <span className="font-semibold text-foreground">{formatMoney(hovered.flow.sold, currency)}</span>
                </span>
                <span className="flex items-center gap-1.5 whitespace-nowrap text-xs">
                  <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: hovered.color }} />
                  <span className="text-muted-foreground">{t('investmentsOverview.flowNet')}:</span>
                  <span className="font-semibold text-foreground">{formatMoney(hovered.flow.net, currency)}</span>
                </span>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
