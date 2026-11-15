import { useEffect, useRef } from 'react'

/** The panels open now, the latest last. */
const open: { id: number; close: () => void }[] = []
let nextId = 1
let listening = false

function sheetOf(state: unknown): number | undefined {
  return (state as { sheet?: number } | null)?.sheet
}

function onPopState(event: PopStateEvent) {
  const sheet = sheetOf(event.state)
  const index = sheet === undefined ? -1 : open.findIndex((entry) => entry.id === sheet)
  // An entry left behind by a panel that closed by leading to another page: skip it.
  if (sheet !== undefined && index === -1) {
    window.history.back()
    return
  }
  for (const entry of open.slice(index + 1).reverse()) entry.close()
}

/**
 * Back (Android's button, iOS's swipe, the browser's) closes an open panel
 * instead of leaving the page under it, as apps do. Opening adds an entry to
 * the history with the same address; closing it any other way takes it off.
 */
export function useBackToClose(isOpen: boolean, close: () => void) {
  const closeRef = useRef(close)
  const entry = useRef<{ id: number; timer?: number } | null>(null)

  useEffect(() => {
    closeRef.current = close
  })

  useEffect(() => {
    if (!isOpen) return
    if (!listening) {
      window.addEventListener('popstate', onPopState)
      listening = true
    }
    let current = entry.current
    if (current?.timer !== undefined) {
      // Closed and opened again at once (React's strict mode): keep the entry.
      window.clearTimeout(current.timer)
      current.timer = undefined
    } else {
      current = { id: nextId++ }
      entry.current = current
      window.history.pushState({ ...window.history.state, sheet: current.id }, '')
    }
    const item = { id: current.id, close: () => closeRef.current() }
    open.push(item)

    return () => {
      open.splice(open.indexOf(item), 1)
      const closing = current
      closing.timer = window.setTimeout(() => {
        entry.current = null
        // Still on the panel's entry: closed by a tap, not by back or by a link to another page.
        if (sheetOf(window.history.state) === closing.id) window.history.back()
      }, 0)
    }
  }, [isOpen])
}
