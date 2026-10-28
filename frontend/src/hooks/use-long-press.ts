import { useRef, type MouseEvent, type PointerEvent } from 'react'

const DELAY_MS = 450
/** A finger moving more than this is scrolling, not pressing. */
const SLOP_PX = 10

/**
 * Pressing and holding an element (phones: how a row is selected, as in
 * mail and photo apps). Returns the handlers to spread on it; the click that
 * ends a long press is swallowed, so the row doesn't also open.
 */
export function useLongPress(onLongPress: (() => void) | undefined) {
  const timer = useRef<number | null>(null)
  const start = useRef<{ x: number; y: number } | null>(null)
  const fired = useRef(false)

  const cancel = () => {
    if (timer.current !== null) window.clearTimeout(timer.current)
    timer.current = null
    start.current = null
  }

  // The handlers stay even without onLongPress: the click ending a press must
  // still be swallowed after the press itself turned it off (e.g. selecting).
  return {
    onPointerDown: (event: PointerEvent) => {
      fired.current = false
      if (!onLongPress) return
      start.current = { x: event.clientX, y: event.clientY }
      timer.current = window.setTimeout(() => {
        fired.current = true
        timer.current = null
        navigator.vibrate?.(10)
        onLongPress()
      }, DELAY_MS)
    },
    onPointerMove: (event: PointerEvent) => {
      if (start.current && Math.hypot(event.clientX - start.current.x, event.clientY - start.current.y) > SLOP_PX) cancel()
    },
    onPointerUp: cancel,
    onPointerCancel: cancel,
    onPointerLeave: cancel,
    // Android opens the context menu on a long press: the press is ours.
    onContextMenu: (event: MouseEvent) => {
      if (onLongPress || fired.current) event.preventDefault()
    },
    onClickCapture: (event: MouseEvent) => {
      if (fired.current) {
        fired.current = false
        event.preventDefault()
        event.stopPropagation()
      }
    },
  }
}
