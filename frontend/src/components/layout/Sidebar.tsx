import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { LucideIcon } from 'lucide-react'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import {
  LayoutDashboard,
  Wallet,
  WalletCards,
  ArrowRightLeft,
  ArrowDownCircle,
  ArrowUpCircle,
  Repeat,
  CalendarClock,
  Inbox,
  FileUp,
  ListOrdered,
  Tag,
  Tags,
  FolderTree,
  DollarSign,
  ShoppingCart,
  Banknote,
  TrendingUp,
  PieChart,
  Layers,
  LineChart,
  FileBarChart,
  BarChart3,
  Landmark,
  ChevronLeft,
} from 'lucide-react'
import CollapsibleNavSection from './CollapsibleNavSection'
import { usePendingDraftCount } from '@/hooks/use-drafts'

const COLLAPSED_KEY = 'firefunds.sidebarCollapsed'
/** With no choice saved yet, narrower screens (tablets) start with the menu reduced to icons. */
const COLLAPSED_BY_DEFAULT_BELOW_PX = 1280

function loadCollapsed(): boolean {
  try {
    const stored = localStorage.getItem(COLLAPSED_KEY)
    if (stored !== null) return stored === '1'
  } catch {
    // storage unavailable: fall back to the screen width
  }
  return window.innerWidth < COLLAPSED_BY_DEFAULT_BELOW_PX
}

function saveCollapsed(collapsed: boolean): void {
  try {
    localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0')
  } catch {
    // not remembered, no harm done
  }
}

/** Collapsed sidebar: the entry's name shows in a tooltip next to its icon. */
function WithTooltip({ label, show, children }: { label: string; show: boolean; children: JSX.Element }) {
  if (!show) return children
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent side="right" sideOffset={10}>
        {label}
      </TooltipContent>
    </Tooltip>
  )
}

/** A top-level entry with no sub-pages (Dashboard). */
function TopLevelLink({
  name,
  href,
  icon: Icon,
  isActive,
  collapsed,
}: {
  name: string
  href: string
  icon: LucideIcon
  isActive: boolean
  collapsed: boolean
}) {
  return (
    <WithTooltip label={name} show={collapsed}>
      <Link
        to={href}
        aria-label={collapsed ? name : undefined}
        className={cn(
          'relative flex items-center gap-3 whitespace-nowrap rounded-xl py-2.5 text-sm font-medium transition-all duration-200',
          collapsed ? 'justify-center' : 'px-3',
          isActive ? 'bg-accent text-primary' : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'
        )}
      >
        {isActive && <div className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary" />}
        <Icon className="h-5 w-5" />
        {!collapsed && name}
      </Link>
    </WithTooltip>
  )
}

export default function Sidebar() {
  const { t } = useTranslation()
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(loadCollapsed)

  const toggleCollapsed = () => {
    setCollapsed((current) => {
      saveCollapsed(!current)
      return !current
    })
  }

  const isTransactionsSectionActive = location.pathname.startsWith('/transactions')
  const [isTransactionsOpen, setIsTransactionsOpen] = useState(isTransactionsSectionActive)

  const isAccountsSectionActive = location.pathname.startsWith('/accounts')
  const [isAccountsOpen, setIsAccountsOpen] = useState(isAccountsSectionActive)

  const isClassificationSectionActive =
    location.pathname.startsWith('/categories') || location.pathname.startsWith('/tags')
  const [isClassificationOpen, setIsClassificationOpen] = useState(isClassificationSectionActive)

  const isReportsSectionActive = location.pathname.startsWith('/reports')
  const [isReportsOpen, setIsReportsOpen] = useState(isReportsSectionActive)

  const isInvestmentsSectionActive = location.pathname.startsWith('/investments')
  const [isInvestmentsOpen, setIsInvestmentsOpen] = useState(isInvestmentsSectionActive)

  const { data: pendingDrafts } = usePendingDraftCount()

  const transactionLinks = [
    {
      name: t('navigation.allTransactions'),
      href: '/transactions',
      icon: ListOrdered,
      matchExact: true,
    },
    {
      name: t('navigation.withdrawalTransactions'),
      href: '/transactions/withdrawal',
      icon: ArrowUpCircle,
      matchExact: true,
    },
    {
      name: t('navigation.depositTransactions'),
      href: '/transactions/deposit',
      icon: ArrowDownCircle,
      matchExact: true,
    },
    {
      name: t('navigation.transferTransactions'),
      href: '/transactions/transfers',
      icon: Repeat,
      matchExact: true,
    },
    {
      name: t('navigation.investmentTransactions'),
      href: '/transactions/investments',
      icon: LineChart,
      matchExact: true,
    },
    {
      name: t('navigation.recurringTransactions'),
      href: '/transactions/recurring',
      icon: CalendarClock,
    },
    {
      name: t('navigation.drafts'),
      href: '/transactions/drafts',
      icon: Inbox,
      badge: pendingDrafts,
    },
    {
      name: t('navigation.importCsv'),
      href: '/transactions/import',
      icon: FileUp,
    },
  ]

  const accountLinks = [
    {
      name: t('navigation.assetAccounts'),
      href: '/accounts/asset',
      icon: Wallet,
    },
    {
      name: t('navigation.expenseAccounts'),
      href: '/accounts/expense',
      icon: ShoppingCart,
    },
    {
      name: t('navigation.revenueAccounts'),
      href: '/accounts/revenue',
      icon: Banknote,
    },
  ]

  const classificationLinks = [
    {
      name: t('navigation.categories'),
      href: '/categories',
      icon: Tag,
    },
    {
      name: t('navigation.tags'),
      href: '/tags',
      icon: Tags,
    },
  ]

  const investmentLinks = [
    {
      name: t('navigation.investmentsOverview'),
      href: '/investments/overview',
      icon: PieChart,
    },
    {
      name: t('navigation.investmentInstruments'),
      href: '/investments/instruments',
      icon: Layers,
    },
  ]

  const reportLinks = [
    {
      name: t('navigation.incomeExpensesReport'),
      href: '/reports/income-expenses',
      icon: BarChart3,
    },
    {
      name: t('navigation.netWorthReport'),
      href: '/reports/net-worth',
      icon: Landmark,
    },
  ]

  return (
    <div
      className={cn(
        'group/sidebar relative flex h-full shrink-0 flex-col border-r bg-muted/30 transition-[width] duration-200',
        collapsed ? 'w-[72px]' : 'w-64'
      )}
    >
      {/*
        Collapse toggle: a small round handle sitting on the right border, on
        the line under the logo. With a mouse it shows while the pointer is over
        the menu; on touch screens (no hover) it is always there.
      */}
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={toggleCollapsed}
            aria-label={collapsed ? t('navigation.expandMenu') : t('navigation.collapseMenu')}
            aria-expanded={!collapsed}
            className={cn(
              'absolute -right-3 top-16 z-20 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full border bg-background text-muted-foreground shadow-sm',
              'transition-[opacity,color,box-shadow] duration-200 hover:text-foreground hover:shadow-md',
              'focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              '[@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-hover/sidebar:opacity-100'
            )}
          >
            <ChevronLeft className={cn('h-3.5 w-3.5 transition-transform duration-200', collapsed && 'rotate-180')} />
          </button>
        </TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          {collapsed ? t('navigation.expandMenu') : t('navigation.collapseMenu')}
        </TooltipContent>
      </Tooltip>

      <div className={cn('flex h-16 shrink-0 items-center gap-3 border-b', collapsed ? 'justify-center' : 'px-6')}>
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <DollarSign className="h-5 w-5" />
        </div>
        {!collapsed && <span className="whitespace-nowrap text-base font-semibold tracking-tight text-foreground">FireFunds</span>}
      </div>

      <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-4">
        <TopLevelLink
          name={t('navigation.dashboard')}
          href="/dashboard"
          icon={LayoutDashboard}
          isActive={location.pathname === '/dashboard'}
          collapsed={collapsed}
        />

        <CollapsibleNavSection
          label={t('navigation.transactions')}
          icon={ArrowRightLeft}
          isOpen={isTransactionsOpen}
          isActive={isTransactionsSectionActive}
          onToggle={() => setIsTransactionsOpen((open) => !open)}
          links={transactionLinks}
          collapsed={collapsed}
        />

        <CollapsibleNavSection
          label={t('navigation.investments')}
          icon={TrendingUp}
          isOpen={isInvestmentsOpen}
          isActive={isInvestmentsSectionActive}
          onToggle={() => setIsInvestmentsOpen((open) => !open)}
          links={investmentLinks}
          collapsed={collapsed}
        />

        <CollapsibleNavSection
          label={t('navigation.accounts')}
          icon={WalletCards}
          isOpen={isAccountsOpen}
          isActive={isAccountsSectionActive}
          onToggle={() => setIsAccountsOpen((open) => !open)}
          links={accountLinks}
          collapsed={collapsed}
        />

        <CollapsibleNavSection
          label={t('navigation.classification')}
          icon={FolderTree}
          isOpen={isClassificationOpen}
          isActive={isClassificationSectionActive}
          onToggle={() => setIsClassificationOpen((open) => !open)}
          links={classificationLinks}
          collapsed={collapsed}
        />

        <CollapsibleNavSection
          label={t('navigation.reports')}
          icon={FileBarChart}
          isOpen={isReportsOpen}
          isActive={isReportsSectionActive}
          onToggle={() => setIsReportsOpen((open) => !open)}
          links={reportLinks}
          collapsed={collapsed}
        />
      </nav>
    </div>
  )
}
