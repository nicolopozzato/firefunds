import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formatMoney } from '@/lib/utils'
import { OTHER_COLOR, SERIES_COLORS } from '@/lib/chart'
import type { DistributionEntry } from '@/types/transaction'

const MAX_SLICES = 7

interface Slice {
  id: string
  name: string | null
  value: number
  color: string
  startAngle: number
  endAngle: number
  isOther: boolean
  isUncategorized: boolean
}

function buildSlices(entries: DistributionEntry[]): Slice[] {
  const parsed = entries
    .map((entry) => ({ id: entry.id ?? '__uncategorized__', name: entry.name, value: Math.abs(Number(entry.total)) }))
    .filter((entry) => entry.value > 0)
    .sort((a, b) => b.value - a.value)

  const head = parsed.slice(0, MAX_SLICES)
  const tail = parsed.slice(MAX_SLICES)
  const otherTotal = tail.reduce((sum, entry) => sum + entry.value, 0)

  const withOther = otherTotal > 0 ? [...head, { id: '__other__', name: 'other', value: otherTotal }] : head

  const total = withOther.reduce((sum, entry) => sum + entry.value, 0)
  if (total === 0) return []

  let cursor = 0
  return withOther.map((entry, index) => {
    const fraction = entry.value / total
    const startAngle = cursor * 2 * Math.PI
    cursor += fraction
    const endAngle = cursor * 2 * Math.PI
    const isOther = entry.id === '__other__'
    const isUncategorized = entry.id === '__uncategorized__'
    return {
      id: entry.id,
      name: entry.name,
      value: entry.value,
      color: isOther ? OTHER_COLOR : SERIES_COLORS[index],
      startAngle,
      endAngle,
      isOther,
      isUncategorized,
    }
  })
}

function arcPath(cx: number, cy: number, r: number, startAngle: number, endAngle: number): string {
  const isFullCircle = endAngle - startAngle >= 2 * Math.PI - 0.0001
  if (isFullCircle) {
    return `M ${cx} ${cy - r} A ${r} ${r} 0 1 1 ${cx - 0.001} ${cy - r} Z`
  }

  const x1 = cx + r * Math.sin(startAngle)
  const y1 = cy - r * Math.cos(startAngle)
  const x2 = cx + r * Math.sin(endAngle)
  const y2 = cy - r * Math.cos(endAngle)
  const largeArc = endAngle - startAngle > Math.PI ? 1 : 0

  return `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`
}

interface PieChartProps {
  title: string
  entries: DistributionEntry[]
  currency?: string
  emptyLabel: string
  /** Label of the entry with no id: "Uncategorized" for categories (default), an em dash for "no account". */
  nullLabel?: string
}

export default function PieChart({ title, entries, currency, emptyLabel, nullLabel }: PieChartProps) {
  const { t } = useTranslation()
  const [hoveredId, setHoveredId] = useState<string | null>(null)

  const slices = useMemo(() => buildSlices(entries), [entries])
  const hovered = slices.find((slice) => slice.id === hoveredId) ?? null

  const labelFor = (slice: Slice) => {
    if (slice.isOther) return t('charts.other')
    if (slice.isUncategorized) return nullLabel ?? t('charts.uncategorized')
    return slice.name
  }

  const size = 200
  const radius = 90
  const center = size / 2

  return (
    <div className="rounded-xl border bg-card p-4">
      <h3 className="mb-3 text-sm font-semibold text-foreground">{title}</h3>

      {slices.length === 0 ? (
        <div className="flex h-24 items-center justify-center text-sm text-muted-foreground sm:h-48">{emptyLabel}</div>
      ) : (
        // Phones: a smaller pie next to its legend, so the card stays short.
        <div className="flex flex-row items-center gap-4 sm:items-start">
          <div className="relative shrink-0">
            <svg
              viewBox={`0 0 ${size} ${size}`}
              className="h-28 w-28 sm:h-[200px] sm:w-[200px]"
              role="img"
              aria-label={title}
            >
              {slices.map((slice) => {
                const isHovered = slice.id === hoveredId
                return (
                  <path
                    key={slice.id}
                    d={arcPath(center, center, isHovered ? radius + 3 : radius, slice.startAngle, slice.endAngle)}
                    fill={slice.color}
                    stroke="hsl(var(--card))"
                    strokeWidth={2}
                    tabIndex={0}
                    role="button"
                    aria-label={`${labelFor(slice)}: ${formatMoney(slice.value, currency)}`}
                    className="cursor-pointer outline-none transition-[d] duration-150 focus-visible:opacity-80"
                    onPointerEnter={() => setHoveredId(slice.id)}
                    onPointerLeave={() => setHoveredId((current) => (current === slice.id ? null : current))}
                    onFocus={() => setHoveredId(slice.id)}
                    onBlur={() => setHoveredId((current) => (current === slice.id ? null : current))}
                  />
                )
              })}
            </svg>

            {hovered && (
              <div className="pointer-events-none absolute left-1/2 top-1/2 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center rounded-md bg-background/95 px-2 py-1 text-center shadow-sm">
                <span className="max-w-[7rem] truncate text-xs text-muted-foreground">
                  {labelFor(hovered)}
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {formatMoney(hovered.value, currency)}
                </span>
              </div>
            )}
          </div>

          <ul className="min-w-0 flex-1 space-y-1.5">
            {slices.map((slice) => {
              return (
                <li
                  key={slice.id}
                  className="flex cursor-default items-center gap-2 rounded-md px-1 py-0.5 text-sm"
                  style={{ backgroundColor: slice.id === hoveredId ? 'hsl(var(--accent))' : undefined }}
                  onPointerEnter={() => setHoveredId(slice.id)}
                  onPointerLeave={() => setHoveredId((current) => (current === slice.id ? null : current))}
                >
                  <span
                    className="h-2.5 w-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: slice.color }}
                    aria-hidden
                  />
                  <span className="min-w-0 flex-1 truncate text-foreground">
                    {labelFor(slice)}
                  </span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">
                    {formatMoney(slice.value, currency)}
                  </span>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}
