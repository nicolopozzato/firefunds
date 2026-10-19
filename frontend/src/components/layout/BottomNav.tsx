import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightLeft, House, Menu, Plus, TrendingUp, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import QuickAddDrawer from './QuickAddDrawer'
import { TAB_ROOT, isFormPage, isTabPage, tabOf, type MobileTab } from './mobile-nav'

interface TabButtonProps {
  label: string
  icon: LucideIcon
  active: boolean
  onClick: () => void
}

function TabButton({ label, icon: Icon, active, onClick }: TabButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'flex flex-col items-center justify-center gap-1 pt-2 text-[11px] font-medium transition-colors',
        active ? 'text-primary' : 'text-muted-foreground active:text-foreground'
      )}
    >
      <Icon className="h-6 w-6" strokeWidth={active ? 2.25 : 1.75} />
      {label}
    </button>
  )
}

/**
 * Phone navigation: four tabs and, in the middle, the button that adds a
 * transaction. A tab reopens the sub-page last seen in it (e.g. Withdrawals);
 * tapping the tab already open goes back to its first page. Form pages hide
 * it: their Save bar sits at the bottom instead.
 */
export default function BottomNav() {
  const { t } = useTranslation()
  const location = useLocation()
  const navigate = useNavigate()
  const [quickAddOpen, setQuickAddOpen] = useState(false)
  const lastPageOfTab = useRef<Partial<Record<MobileTab, string>>>({})

  const currentTab = tabOf(location.pathname)

  useEffect(() => {
    if (isTabPage(location.pathname)) {
      lastPageOfTab.current[tabOf(location.pathname)] = location.pathname + location.search
    }
  }, [location.pathname, location.search])

  const openTab = (tab: MobileTab) => {
    navigate(tab === currentTab ? TAB_ROOT[tab] : lastPageOfTab.current[tab] ?? TAB_ROOT[tab])
  }

  const tabs: { tab: MobileTab; label: string; icon: LucideIcon }[] = [
    { tab: 'home', label: t('navigation.home'), icon: House },
    { tab: 'transactions', label: t('navigation.transactions'), icon: ArrowRightLeft },
    { tab: 'investments', label: t('navigation.investments'), icon: TrendingUp },
    { tab: 'more', label: t('navigation.more'), icon: Menu },
  ]

  const button = (index: number) => {
    const { tab, label, icon } = tabs[index]
    return <TabButton label={label} icon={icon} active={currentTab === tab} onClick={() => openTab(tab)} />
  }

  return (
    <>
      <nav
        aria-label={t('navigation.main')}
        className={cn(
          'glass-bar glass-bar-bottom fixed inset-x-0 bottom-0 z-40 pb-[env(safe-area-inset-bottom)] md:hidden',
          // Hidden, not unmounted, so the tabs keep the sub-page they last showed.
          isFormPage(location.pathname) && 'hidden'
        )}
      >
        <div className="grid h-16 grid-cols-5">
          {button(0)}
          {button(1)}
          <div className="flex items-center justify-center">
            <button
              type="button"
              onClick={() => setQuickAddOpen(true)}
              aria-label={t('quickAdd.title')}
              className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-lg shadow-primary/30 transition-transform active:scale-95"
            >
              <Plus className="h-6 w-6" strokeWidth={2.5} />
            </button>
          </div>
          {button(2)}
          {button(3)}
        </div>
      </nav>

      <QuickAddDrawer open={quickAddOpen} onOpenChange={setQuickAddOpen} />
    </>
  )
}
