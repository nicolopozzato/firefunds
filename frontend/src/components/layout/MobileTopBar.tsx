import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronLeft, DollarSign, Search } from 'lucide-react'
import { Button } from '@/components/ui/button'
import TimeframeSelector from '@/components/TimeframeSelector'
import SearchBox from './SearchBox'
import NotificationBell from './NotificationBell'
import HideAmountsButton from '@/components/HideAmountsButton'
import { isTabPage, parentPath, showsPeriod } from './mobile-nav'

/**
 * Phone header: the logo on a tab's own pages, a back arrow deeper down, then
 * the period (on the pages that follow it) and a magnifier that turns the bar
 * into the search box.
 */
export default function MobileTopBar() {
  const { t } = useTranslation()
  const location = useLocation()
  const navigate = useNavigate()
  const [searching, setSearching] = useState(false)

  const goBack = () => {
    // React Router numbers the entries of this tab's history: 0 means the app was opened on this page.
    const index = (window.history.state as { idx?: number } | null)?.idx ?? 0
    if (index > 0) navigate(-1)
    else navigate(parentPath(location.pathname), { replace: true })
  }

  return (
    // Over the page (Layout leaves room for it), so the content scrolls under the glass.
    <header className="glass-bar glass-bar-top absolute inset-x-0 top-0 z-30 pt-[env(safe-area-inset-top)] md:hidden">
      {searching ? (
        <div className="flex h-14 items-center gap-2 px-3">
          <SearchBox className="w-auto min-w-0 flex-1" autoFocus onDone={() => setSearching(false)} />
          <Button variant="ghost" className="px-2 text-primary" onClick={() => setSearching(false)}>
            {t('common.cancel')}
          </Button>
        </div>
      ) : (
        <div className="flex h-14 items-center gap-1 px-2">
          {isTabPage(location.pathname) ? (
            <div className="ml-2 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <DollarSign className="h-5 w-5" />
            </div>
          ) : (
            <Button variant="ghost" size="icon" className="h-10 w-10 shrink-0" onClick={goBack} aria-label={t('common.back')}>
              <ChevronLeft className="h-6 w-6" />
            </Button>
          )}

          <div className="flex min-w-0 flex-1 justify-center">
            {showsPeriod(location.pathname) && <TimeframeSelector />}
          </div>

          <HideAmountsButton className="h-10 w-9 shrink-0" />
          <NotificationBell className="h-10 w-9 shrink-0" />
          <Button
            variant="ghost"
            size="icon"
            className="h-10 w-9 shrink-0"
            onClick={() => setSearching(true)}
            aria-label={t('search.placeholder')}
          >
            <Search className="h-5 w-5" />
          </Button>
        </div>
      )}
    </header>
  )
}
