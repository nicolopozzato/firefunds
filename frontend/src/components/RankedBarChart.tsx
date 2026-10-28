import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formatMoney, formatPercent } from '@/lib/utils'
import { niceTicks, useContainerWidth } from '@/lib/chart'
import { useIsMobile } from '@/hooks/use-media-query'
import type { DistributionEntry } from '@/types/transaction'

/** Height of the bars' area; the labels below add their own. */
const PLOT_HEIGHT = 200
const PADDING = { top: 12, right: 8, left: 64 }
/** Corner radius of a bar's data end; the end on the zero line stays square. */
const RADIUS = 4
/** Surface gap between adjacent bars, on each side. */
const GAP = 2
const MAX_BAR_WIDTH = 48
/** Rough width of a 10px label character. */
const CHAR_WIDTH = 6
/** Labels are cut past this, the full name stays in the tooltip. */
const MAX_LABEL_CHARS = 22
/** Below this distance between bars, slanted labels would overlap: only every few bars gets one. */
const MIN_LABEL_SPACING = 12
const SLANT = Math.SQRT1_2

interface Bar {
  key: string
  label: string
  value: number
}

interface RankedBarChartProps {
  title: string
  entries: DistributionEntry[]
  color: string
  currency?: string | null
  emptyLabel: string
  /** Label of the entry with no id: "Uncategorized" for categories (default), "no account" for accounts. */
  nullLabel?: string
}

function barPath(x: number, width: number, zeroY: number, y: number): string {
  const r = Math.min(RADIUS, width / 2, zeroY - y)
  return `M${x},${zeroY} V${y + r} Q${x},${y} ${x + r},${y} H${x + width - r} Q${x + width},${y} ${x + width},${y + r} V${zeroY} Z`
}

/** Phones: rows shown before "show all". */
const MOBILE_ROWS = 8

/**
 * Phones: one row per entry, name and amount over a horizontal bar, so long
 * names stay readable without slanting them.
 */
function RankedBarList({ title, bars, total, color, currency, emptyLabel }: {
  title: string
  bars: Bar[]
  total: number
  color: string
  currency?: string | null
  emptyLabel: string
}) {
  const { t } = useTranslation()
  const [expanded, setExpanded] = useState(false)
  const max = bars[0]?.value ?? 0
  const shown = expanded ? bars : bars.slice(0, MOBILE_ROWS)

  return (
    <div className="surface p-4 sm:p-5">
      <h3 className="mb-3 section-title">{title}</h3>
      {bars.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">{emptyLabel}</p>
      ) : (
        <ul className="space-y-3">
          {shown.map((bar) => (
            <li key={bar.key}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate">{bar.label}</span>
                <span className="shrink-0 tabular-nums">
                  {formatMoney(bar.value, currency)}
                  <span className="ml-1.5 text-xs text-muted-foreground">{formatPercent((bar.value / total) * 100, 0)}</span>
                </span>
              </div>
              <div className="mt-1 h-2 overflow-hidden rounded-full bg-muted">
                <div className="chart-grow-x h-full rounded-full" style={{ width: `${(bar.value / max) * 100}%`, backgroundColor: color }} />
              </div>
            </li>
          ))}
        </ul>
      )}
      {bars.length > MOBILE_ROWS && (
        <button type="button" onClick={() => setExpanded((current) => !current)} className="mt-3 text-sm font-medium text-primary">
          {expanded ? t('charts.showLess') : t('charts.showAll', { count: bars.length })}
        </button>
      )}
    </div>
  )
}

function truncate(label: string): string {
  return label.length <= MAX_LABEL_CHARS ? label : `${label.slice(0, MAX_LABEL_CHARS - 1)}…`
}

/**
 * One series of amounts, every entry a bar, largest first (e.g. the period's
 * expenses by category). Labels slant at 45°; phones get horizontal bars.
 */
export default function RankedBarChart({ title, entries, color, currency, emptyLabel, nullLabel }: RankedBarChartProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [hoveredKey, setHoveredKey] = useState<string | null>(null)
  const [containerRef, width] = useContainerWidth<HTMLDivElement>(480)

  const bars = useMemo<Bar[]>(
    () =>
      entries
        .map((entry) => ({
          key: entry.id ?? '__none__',
          label: entry.id === null ? (nullLabel ?? t('charts.uncategorized')) : (entry.name ?? ''),
          value: Math.abs(Number(entry.total)),
        }))
        .filter((entry) => entry.value > 0)
        .sort((a, b) => b.value - a.value),
    [entries, nullLabel, t]
  )

  const total = bars.reduce((sum, bar) => sum + bar.value, 0)

  const chart = useMemo(() => {
    if (bars.length === 0) return null

    // A slanted label reaches down and left of its bar by its length times sin 45°.
    const labelReach = Math.max(...bars.map((bar) => truncate(bar.label).length)) * CHAR_WIDTH * SLANT
    const bottom = Math.ceil(labelReach) + 16
    const height = PADDING.top + PLOT_HEIGHT + bottom

    const ticks = niceTicks(0, Math.max(...bars.map((bar) => bar.value)))
    const max = ticks[ticks.length - 1] || 1
    // The first label slants left past the axis: widen the margin when it would leave the chart.
    const roughSlot = (width - PADDING.left - PADDING.right) / bars.length
    const left = Math.max(PADDING.left, Math.ceil(labelReach - roughSlot / 2) + 4)
    const innerWidth = width - left - PADDING.right
    const slot = innerWidth / bars.length
    const barWidth = Math.max(1, Math.min(MAX_BAR_WIDTH, slot - GAP * 2))
    const yFor = (value: number) => PADDING.top + PLOT_HEIGHT - (value / max) * PLOT_HEIGHT
    const zeroY = yFor(0)

    return {
      height,
      ticks,
      yFor,
      zeroY,
      slot,
      labelEvery: Math.max(1, Math.ceil(MIN_LABEL_SPACING / slot)),
      left,
      items: bars.map((bar, index) => {
        const x = left + index * slot + (slot - barWidth) / 2
        return { ...bar, x, centerX: x + barWidth / 2, y: yFor(bar.value), path: barPath(x, barWidth, zeroY, yFor(bar.value)) }
      }),
    }
  }, [bars, width])

  const hovered = chart?.items.find((item) => item.key === hoveredKey) ?? null

  if (isMobile) {
    return <RankedBarList title={title} bars={bars} total={total} color={color} currency={currency} emptyLabel={emptyLabel} />
  }

  return (
    <div className="surface p-4 sm:p-5">
      <h3 className="mb-3 section-title">{title}</h3>
      <div ref={containerRef} className="relative">
        {!chart ? (
          <div className="flex items-center justify-center text-sm text-muted-foreground" style={{ height: PADDING.top + PLOT_HEIGHT + 36 }}>
            {emptyLabel}
          </div>
        ) : (
          <>
            <svg viewBox={`0 0 ${width} ${chart.height}`} width={width} height={chart.height} role="img" aria-label={title}>
              {chart.ticks.map((tick) => (
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
                    {formatMoney(tick, currency)}
                  </text>
                </g>
              ))}

              {chart.items.map((item, index) => (
                <g key={item.key}>
                  <path d={item.path} fill={color} opacity={hoveredKey === null || hoveredKey === item.key ? 1 : 0.6} />
                  <rect
                    x={chart.left + index * chart.slot}
                    y={PADDING.top}
                    width={chart.slot}
                    height={chart.height - PADDING.top}
                    fill="transparent"
                    tabIndex={0}
                    aria-label={`${item.label}: ${formatMoney(item.value, currency)}`}
                    className="outline-none"
                    onPointerEnter={() => setHoveredKey(item.key)}
                    onPointerLeave={() => setHoveredKey((current) => (current === item.key ? null : current))}
                    onFocus={() => setHoveredKey(item.key)}
                    onBlur={() => setHoveredKey((current) => (current === item.key ? null : current))}
                  />
                  {(index % chart.labelEvery === 0 || hoveredKey === item.key) && (
                    <text
                      x={item.centerX}
                      y={chart.zeroY + 10}
                      fontSize={10}
                      textAnchor="end"
                      transform={`rotate(-45 ${item.centerX} ${chart.zeroY + 10})`}
                      fill={hoveredKey === item.key ? 'hsl(var(--foreground))' : 'hsl(var(--muted-foreground))'}
                      className="pointer-events-none"
                    >
                      {truncate(item.label)}
                    </text>
                  )}
                </g>
              ))}
            </svg>

            {hovered && (
              <div
                className="pointer-events-none absolute z-10 flex flex-col gap-0.5 chart-tooltip"
                style={{
                  left: hovered.centerX,
                  top: hovered.y,
                  transform: `translate(${hovered.centerX > width / 2 ? 'calc(-100% - 8px)' : '8px'}, -50%)`,
                }}
              >
                <span className="whitespace-nowrap text-xs text-muted-foreground">{hovered.label}</span>
                <span className="whitespace-nowrap text-sm font-semibold tabular-nums text-foreground">
                  {formatMoney(hovered.value, currency)}
                  <span className="ml-1.5 text-xs font-normal text-muted-foreground">{formatPercent((hovered.value / total) * 100, 1)}</span>
                </span>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
