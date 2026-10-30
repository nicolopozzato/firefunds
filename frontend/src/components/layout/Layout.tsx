import { ReactNode, useEffect, useLayoutEffect, useRef } from 'react'
import { useLocation, useNavigate, useNavigationType } from 'react-router-dom'
import Sidebar from './Sidebar'
import Header from './Header'
import MobileTopBar from './MobileTopBar'
import BottomNav from './BottomNav'
import ExchangeRateWarning from './ExchangeRateWarning'
import { UpdateDialog } from '@/components/UpdateNotice'
import PullToRefresh from './PullToRefresh'
import { useIsMobile } from '@/hooks/use-media-query'
import { useHideAmounts } from '@/hooks/use-hide-amounts'

/** "0.1.0" -> "0.1": major.minor is enough to tell versions apart at a glance. */
const APP_VERSION = __APP_VERSION__.split('.').slice(0, 2).join('.')

/** How far down each visited page was scrolled, by history entry, to restore it on Back. */
const scrollPositions = new Map<string, number>()

/**
 * The page scrolls inside <main>, which the browser does not restore by itself:
 * a new page starts at the top, and Back returns to where the previous one was
 * left (retrying for a moment while its data comes back from the cache).
 */
function useScrollRestoration(main: React.RefObject<HTMLElement>) {
  const location = useLocation()
  const navigationType = useNavigationType()

  useLayoutEffect(() => {
    const element = main.current
    if (!element) return
    const target = navigationType === 'POP' ? scrollPositions.get(location.key) ?? 0 : 0
    element.scrollTop = target

    let frames = 0
    let request = 0
    const retry = () => {
      if (element.scrollTop >= target || ++frames > 30) return
      element.scrollTop = target
      request = requestAnimationFrame(retry)
    }
    request = requestAnimationFrame(retry)

    const remember = () => scrollPositions.set(location.key, element.scrollTop)
    element.addEventListener('scroll', remember, { passive: true })
    return () => {
      cancelAnimationFrame(request)
      element.removeEventListener('scroll', remember)
    }
  }, [main, location.key, navigationType])
}

interface LayoutProps {
  children: ReactNode
}

/**
 * Larger screens: sidebar on the left, header on top. Phones (below `md`):
 * a compact top bar and a navigation bar at the bottom, app style.
 */
/** A push notification tapped while the app is open (public/sw.js): its page, inside the app. */
function useNotificationTaps() {
  const navigate = useNavigate()
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return
    const onMessage = (event: MessageEvent) => {
      if (event.data?.type === 'navigate' && typeof event.data.url === 'string') navigate(event.data.url)
    }
    navigator.serviceWorker.addEventListener('message', onMessage)
    return () => navigator.serviceWorker.removeEventListener('message', onMessage)
  }, [navigate])
}

export default function Layout({ children }: LayoutProps) {
  const mainRef = useRef<HTMLElement>(null)
  useScrollRestoration(mainRef)
  useNotificationTaps()
  const isMobile = useIsMobile()
  const location = useLocation()
  // The amounts are formatted while rendering: hiding or showing them draws the page again.
  const [amountsHidden] = useHideAmounts()

  return (
    <div className="flex h-[100dvh] overflow-hidden bg-background">
      {!isMobile && <Sidebar />}

      <div className="relative flex min-w-0 flex-1 flex-col overflow-hidden">
        {isMobile ? <MobileTopBar /> : <Header />}

        {/* Positions the pull-to-refresh indicator just under the top bar, hidden above the page at rest. */}
        <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden">
          <PullToRefresh scrollContainer={mainRef} />
          <main ref={mainRef} className="flex-1 overflow-y-auto overscroll-contain">
            {/* At least the window's height, so on a short page the signature sits at the bottom of the window. */}
            {/* Phones: room at the bottom for the navigation bar (and the home indicator under it), and at the top
                for the top bar, which lies over the page (3.5rem high, under the status bar). */}
            <div className="mx-auto flex min-h-full max-w-[1600px] flex-col px-4 pb-[calc(env(safe-area-inset-bottom)+6rem)] pt-[calc(env(safe-area-inset-top)+4.5rem)] md:p-6">
              <ExchangeRateWarning />
              {/* Keyed by the page too: each page that opens fades in (page-enter). */}
              <div key={`${location.pathname}:${amountsHidden ? 'hidden' : 'shown'}`} className="page-enter">
                {children}
              </div>
              {/* Signature at the bottom of every page: also leaves some air under the last table. On phones it is on the "More" page. */}
              <footer className="mt-auto hidden pt-10 text-xs text-muted-foreground/60 md:block">
                FireFunds <span className="text-[10px]">v{APP_VERSION}</span>
              </footer>
            </div>
          </main>
        </div>
      </div>

      {isMobile && <BottomNav />}
      <UpdateDialog />
    </div>
  )
}
