import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowDownCircle,
  ArrowUpCircle,
  Banknote,
  ChevronRight,
  Landmark,
  Layers,
  LineChart,
  Repeat,
  ShoppingCart,
  Tag,
  type LucideIcon,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'

interface QuickAddDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

interface Action {
  label: string
  href: string
  icon: LucideIcon
  /** Icon colours of a big tile. */
  tone?: string
}

/**
 * Phone version of the header's "+" menu, opened from the bottom bar: the
 * four kinds of transaction as big tiles, then the less frequent things to add.
 */
export default function QuickAddDrawer({ open, onOpenChange }: QuickAddDrawerProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const go = (href: string) => {
    onOpenChange(false)
    navigate(href)
  }

  const transactions: Action[] = [
    { label: t('quickAdd.withdrawal'), href: '/transactions/new?type=withdrawal', icon: ArrowUpCircle, tone: 'bg-negative/10 text-negative' },
    { label: t('quickAdd.deposit'), href: '/transactions/new?type=deposit', icon: ArrowDownCircle, tone: 'bg-positive/10 text-positive' },
    { label: t('quickAdd.transfer'), href: '/transactions/new?type=transfer', icon: Repeat, tone: 'bg-info/10 text-info' },
    { label: t('quickAdd.trade'), href: '/transactions/investments/new', icon: LineChart, tone: 'bg-invest/10 text-invest' },
  ]

  const others: Action[] = [
    { label: t('navigation.assetAccounts'), href: '/accounts/asset/new', icon: Landmark },
    { label: t('navigation.expenseAccounts'), href: '/accounts/expense/new', icon: ShoppingCart },
    { label: t('navigation.revenueAccounts'), href: '/accounts/revenue/new', icon: Banknote },
    { label: t('quickAdd.category'), href: '/categories/new', icon: Tag },
    { label: t('quickAdd.instrument'), href: '/investments/instruments/new', icon: Layers },
  ]

  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent>
        <DrawerHeader>
          <DrawerTitle>{t('quickAdd.title')}</DrawerTitle>
          <DrawerDescription className="sr-only">{t('quickAdd.description')}</DrawerDescription>
        </DrawerHeader>

        <div className="overflow-y-auto px-4 pb-4">
          <div className="grid grid-cols-2 gap-3">
            {transactions.map((action) => (
              <button
                key={action.href}
                type="button"
                onClick={() => go(action.href)}
                className="flex flex-col items-start gap-3 rounded-2xl border bg-card p-4 text-left text-sm font-medium transition-colors active:bg-accent"
              >
                <span className={cn('flex h-10 w-10 items-center justify-center rounded-xl', action.tone)}>
                  <action.icon className="h-5 w-5" />
                </span>
                {action.label}
              </button>
            ))}
          </div>

          <p className="mb-2 mt-5 px-1 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            {t('quickAdd.other')}
          </p>
          <div className="divide-y overflow-hidden rounded-2xl border bg-card">
            {others.map((action) => (
              <button
                key={action.href}
                type="button"
                onClick={() => go(action.href)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm transition-colors active:bg-accent"
              >
                <action.icon className="h-5 w-5 text-muted-foreground" />
                <span className="flex-1">{action.label}</span>
                <ChevronRight className="h-4 w-4 text-muted-foreground/60" />
              </button>
            ))}
          </div>
        </div>
      </DrawerContent>
    </Drawer>
  )
}
