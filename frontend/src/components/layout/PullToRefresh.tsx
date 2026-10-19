import { useEffect, useRef, useState, type RefObject } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { RefreshCw } from 'lucide-react'
import { refreshApp } from '@/lib/app-refresh'
import { cn } from '@/lib/utils'

/** How far (px) the indicator must come down to refresh on release. */
const THRESHOLD = 70
const MAX_PULL = 110
/** The indicator moves half as much as the finger. */
const RESISTANCE = 0.5
/** Finger travel before a touch counts as a pull (or as something else). */
const SLOP = 10
/** Indicator height plus its top margin: fully hidden above the page at rest. */
const HIDDEN_OFFSET = 44

/** True if the touch started inside something already scrolled down within the page (e.g. a scrolled panel). */
function insideScrolled(target: EventTarget | null, container: HTMLElement): boolean {
  for (let element = target instanceof Element ? target : null; element && element !== container; element = element.parentElement) {
    if (element.scrollTop > 0) return true
  }
  return false
}

interface PullToRefreshProps {
  /** The element the page scrolls in (`<main>`): the pull starts at its top. */
  scrollContainer: RefObject<HTMLElement>
}

/**
 * Pull down from the top of the page to refresh, as in mobile apps: an
 * installed PWA has no reload button (`refreshApp` for what it does). Touch
 * only. The page stays still; a round indicator follows the finger and spins
 * while refreshing. Drawers and dialogs are portaled outside the page, so a
 * pull inside them doesn't count.
 */
export default function PullToRefresh({ scrollContainer }: PullToRefreshProps) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [pull, setPull] = useState(0)
  const [dragging, setDragging] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const refreshingRef = useRef(false)

  useEffect(() => {
    const element = scrollContainer.current
    if (!element) return

    let startX = 0
    let startY = 0
    let tracking = false // a touch that may still become a pull
    let pulling = false
    let distance = 0

    const onStart = (event: TouchEvent) => {
      pulling = false
      tracking = !refreshingRef.current
        && event.touches.length === 1
        && element.scrollTop <= 0
        && !insideScrolled(event.target, element)
      if (tracking) {
        startX = event.touches[0].clientX
        startY = event.touches[0].clientY
      }
    }

    const onMove = (event: TouchEvent) => {
      if (!tracking) return
      const dx = event.touches[0].clientX - startX
      const dy = event.touches[0].clientY - startY

      if (!pulling) {
        // Only a clearly vertical move down is a pull: sideways swipes (the
        // pies row) and scrolling up are left to the page.
        if (dy > SLOP && dy > Math.abs(dx) * 1.5) {
          pulling = true
          setDragging(true)
        } else {
          if (Math.abs(dx) > SLOP || dy < -SLOP) tracking = false
          return
        }
      }

      event.preventDefault() // no rubber band (iOS) while pulling
      distance = Math.max(0, Math.min(MAX_PULL, (dy - SLOP) * RESISTANCE))
      setPull(distance)
    }

    const onEnd = () => {
      tracking = false
      if (!pulling) return
      pulling = false
      setDragging(false)

      if (distance >= THRESHOLD) {
        refreshingRef.current = true
        setRefreshing(true)
        setPull(THRESHOLD)
        refreshApp(queryClient).finally(() => {
          refreshingRef.current = false
          setRefreshing(false)
          setPull(0)
        })
      } else {
        setPull(0)
      }
      distance = 0
    }

    element.addEventListener('touchstart', onStart, { passive: true })
    element.addEventListener('touchmove', onMove, { passive: false })
    element.addEventListener('touchend', onEnd)
    element.addEventListener('touchcancel', onEnd)
    return () => {
      element.removeEventListener('touchstart', onStart)
      element.removeEventListener('touchmove', onMove)
      element.removeEventListener('touchend', onEnd)
      element.removeEventListener('touchcancel', onEnd)
    }
  }, [scrollContainer, queryClient])

  const progress = Math.min(1, pull / THRESHOLD)

  return (
    <div
      role={refreshing ? 'status' : undefined}
      aria-label={refreshing ? t('common.refreshing') : undefined}
      aria-hidden={!refreshing}
      className={cn(
        // Phones: just under the top bar, which lies over the page.
        'pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-center max-md:top-[calc(env(safe-area-inset-top)+3.5rem)]',
        !dragging && 'transition-[transform,opacity] duration-200',
      )}
      style={{ transform: `translateY(${pull - HIDDEN_OFFSET}px)`, opacity: refreshing ? 1 : progress }}
    >
      <div className="mt-2 flex h-9 w-9 items-center justify-center rounded-full border bg-background shadow-md">
        <RefreshCw
          className={cn('h-4 w-4', progress >= 1 ? 'text-primary' : 'text-muted-foreground', refreshing && 'animate-spin')}
          style={refreshing ? undefined : { transform: `rotate(${pull * 3}deg)` }}
        />
      </div>
    </div>
  )
}
