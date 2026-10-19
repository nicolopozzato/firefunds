import { useLayoutEffect, type RefObject } from 'react'

/** How far each remembered row was scrolled sideways, by memory key. */
const scrollMemory = new Map<string, number>()

/** Room left beside the selected option when it is scrolled into view. */
const SCROLL_MARGIN = 16

/**
 * For a row of options that scrolls sideways when it doesn't fit (phones):
 * keeps the selected one (`aria-checked="true"`) fully in view, moving the row
 * as little as possible. With `memoryKey`, also remembers how far the row was
 * scrolled, for a row that is rebuilt on every choice (each option opens
 * another page), so it doesn't jump back to the start.
 */
export function useSelectedInView(scrollerRef: RefObject<HTMLElement>, value: string, memoryKey?: string): void {
  useLayoutEffect(() => {
    const scroller = scrollerRef.current
    if (!scroller) return

    if (memoryKey !== undefined) {
      scroller.scrollLeft = scrollMemory.get(memoryKey) ?? 0
    }

    const selected = scroller.querySelector<HTMLElement>('[aria-checked="true"]')
    if (selected) {
      const box = scroller.getBoundingClientRect()
      const selectedBox = selected.getBoundingClientRect()
      if (selectedBox.left < box.left) {
        scroller.scrollLeft -= box.left - selectedBox.left + SCROLL_MARGIN
      } else if (selectedBox.right > box.right) {
        scroller.scrollLeft += selectedBox.right - box.right + SCROLL_MARGIN
      }
    }

    if (memoryKey === undefined) return
    const remember = () => scrollMemory.set(memoryKey, scroller.scrollLeft)
    remember()
    scroller.addEventListener('scroll', remember, { passive: true })
    return () => scroller.removeEventListener('scroll', remember)
  }, [scrollerRef, value, memoryKey])
}
