import { useEffect, useRef, type PointerEvent } from 'react'

/**
 * Lets a finger slide along a time chart to move its tooltip, as the mouse
 * does. Spread what it returns on the chart's <svg>:
 * - `touch-action: pan-y`: a vertical swipe still scrolls the page, a sideways
 *   one stays with the chart (otherwise the browser takes it for a scroll and
 *   cancels the touch);
 * - a touch is captured by the element it started on, so the other days' hit
 *   areas would never get `pointerenter`: the capture is released.
 * When the finger lifts the last day touched stays shown, to be read (see
 * onMouseLeave); touching anywhere outside the chart calls `dismiss`.
 */
export function useChartTouch(dismiss: () => void) {
  const ref = useRef<SVGSVGElement>(null)
  const dismissRef = useRef(dismiss)
  dismissRef.current = dismiss

  useEffect(() => {
    const onPointerDown = (event: globalThis.PointerEvent) => {
      if (event.pointerType !== 'touch') return
      if (ref.current && event.target instanceof Node && ref.current.contains(event.target)) return
      dismissRef.current()
    }
    document.addEventListener('pointerdown', onPointerDown, { passive: true })
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [])

  return {
    ref,
    style: { touchAction: 'pan-y' as const },
    onPointerDown: (event: PointerEvent<SVGSVGElement>) => {
      const target = event.target as Element
      if (target.hasPointerCapture?.(event.pointerId)) {
        target.releasePointerCapture(event.pointerId)
      }
    },
  }
}

/**
 * A `pointerleave` handler that only reacts to a mouse: when a finger lifts,
 * the last day touched stays shown, so its value can be read (a touch outside
 * the chart hides it, see useChartTouch).
 */
export function onMouseLeave(handler: () => void) {
  return (event: PointerEvent) => {
    if (event.pointerType !== 'touch') handler()
  }
}
