import { useEffect, useState } from 'react'
import { formatMoney, formatWholeMoney } from './utils'

/**
 * Categorical hues in fixed order (validated palette): a series keeps its slot, never cycled.
 * CSS variables (src/lib/theme.ts, SEMANTIC), so each has its light and dark shade.
 */
export const SERIES_COLORS = [
  'hsl(var(--series-1))', // blue
  'hsl(var(--series-2))', // orange
  'hsl(var(--series-3))', // aqua
  'hsl(var(--series-4))', // yellow
  'hsl(var(--series-5))', // magenta
  'hsl(var(--series-6))', // green
  'hsl(var(--series-7))', // violet
  'hsl(var(--series-8))', // red
]
/** Muted neutral for "other" and for series past the eighth: not a 9th hue. */
export const OTHER_COLOR = 'hsl(var(--series-other))'

/**
 * Monotone cubic interpolation (Fritsch-Carlson): smooths the line while
 * guaranteeing it never overshoots past the value of adjacent points, so
 * sharp balance jumps and flat stretches don't produce bumps or dips.
 */
export function smoothPath(coords: { x: number; y: number }[]): string {
  const n = coords.length
  if (n < 3) {
    return coords.map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x} ${c.y}`).join(' ')
  }

  const dx: number[] = []
  const slope: number[] = []
  for (let i = 0; i < n - 1; i++) {
    dx[i] = coords[i + 1].x - coords[i].x
    slope[i] = (coords[i + 1].y - coords[i].y) / dx[i]
  }

  const tangent: number[] = new Array(n).fill(0)
  tangent[0] = slope[0]
  tangent[n - 1] = slope[n - 2]
  for (let i = 1; i < n - 1; i++) {
    if (slope[i - 1] * slope[i] <= 0) {
      tangent[i] = 0
    } else {
      tangent[i] = (slope[i - 1] + slope[i]) / 2
    }
  }

  for (let i = 0; i < n - 1; i++) {
    if (slope[i] === 0) {
      tangent[i] = 0
      tangent[i + 1] = 0
      continue
    }
    const a = tangent[i] / slope[i]
    const b = tangent[i + 1] / slope[i]
    const h = Math.hypot(a, b)
    if (h > 3) {
      const t = 3 / h
      tangent[i] = t * a * slope[i]
      tangent[i + 1] = t * b * slope[i]
    }
  }

  let path = `M ${coords[0].x} ${coords[0].y}`
  for (let i = 0; i < n - 1; i++) {
    const p0 = coords[i]
    const p1 = coords[i + 1]
    const cp1x = p0.x + dx[i] / 3
    const cp1y = p0.y + (tangent[i] * dx[i]) / 3
    const cp2x = p1.x - dx[i] / 3
    const cp2y = p1.y - (tangent[i + 1] * dx[i]) / 3
    path += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p1.x} ${p1.y}`
  }
  return path
}

/**
 * Round tick values covering [min, max]: the first at or below min, the last
 * at or above max, so a chart whose axis runs from the first to the last
 * tick never draws past it.
 */
export function niceTicks(min: number, max: number, count = 4): number[] {
  if (min === max) return [min]
  const range = max - min
  const rawStep = range / count
  const magnitude = 10 ** Math.floor(Math.log10(rawStep))
  const residual = rawStep / magnitude
  const step = (residual > 5 ? 10 : residual > 2 ? 5 : residual > 1 ? 2 : 1) * magnitude

  const start = Math.floor(min / step) * step
  const ticks: number[] = [Math.round(start * 10000) / 10000]
  for (let v = start; v < max - step * 0.001; ) {
    v += step
    ticks.push(Math.round(v * 10000) / 10000)
  }
  return ticks
}

/**
 * Tracks the rendered width of a container element via ResizeObserver.
 * Returns a callback ref (not a ref object) so the observer follows the
 * element even when it mounts later or is replaced, e.g. a chart switching
 * from its "no data" placeholder back to the plot.
 */
export function useContainerWidth<T extends HTMLElement>(fallback: number) {
  const [element, setElement] = useState<T | null>(null)
  const [width, setWidth] = useState(fallback)

  useEffect(() => {
    if (!element) return

    const observer = new ResizeObserver((entries) => {
      const entry = entries[0]
      if (entry) setWidth(entry.contentRect.width)
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [element])

  return [setElement, width] as const
}

/** Splits a path's coords into a solid segment (up to and including today) and a dashed one (today onward), for series whose future is a projection rather than a fact. */
export function splitAtToday<T extends { x: number; y: number }>(coords: T[], todayIndex: number): { solid: T[]; dashed: T[] } {
  if (todayIndex < 0 || todayIndex >= coords.length - 1) {
    return { solid: coords, dashed: [] }
  }
  return {
    solid: coords.slice(0, todayIndex + 1),
    dashed: coords.slice(todayIndex),
  }
}

/**
 * Money labels for a value axis: without decimals when every tick is a whole
 * number ("7.550 €" instead of "7.550,00 €"), so they stay short on phones.
 */
export function moneyTickLabels(ticks: number[], currency: string | null | undefined): string[] {
  const whole = ticks.every((tick) => Number.isInteger(tick))
  return ticks.map((tick) =>
    whole
      ? formatWholeMoney(tick, currency)
      : formatMoney(tick, currency ?? null)
  )
}

/** Left margin of a chart: at least `minimum`, wider when the longest 10px axis label needs it. */
export function axisLeft(labels: string[], minimum: number): number {
  const longest = Math.max(0, ...labels.map((label) => label.length))
  return Math.max(minimum, Math.ceil(longest * 5.6) + 12)
}

/**
 * Where to anchor the "today" label above its line: centred, or flush with
 * the line near either edge so it isn't cut off ("toda").
 */
export function todayLabelAnchor(x: number, width: number, room = 24): 'start' | 'middle' | 'end' {
  if (x > width - room) return 'end'
  if (x < room) return 'start'
  return 'middle'
}
