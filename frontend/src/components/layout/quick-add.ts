import { useTranslation } from 'react-i18next'
import {
  ArrowDownCircle,
  ArrowUpCircle,
  Banknote,
  CalendarClock,
  Landmark,
  Layers,
  LineChart,
  Repeat,
  ShoppingCart,
  Tag,
  Tags,
  type LucideIcon,
} from 'lucide-react'

export interface QuickAddAction {
  label: string
  href: string
  icon: LucideIcon
  /** The icon's colour: what the transaction means. */
  tone?: string
  /** The same colour, faint: the background of the phone's big tile. */
  tile?: string
}

/**
 * What the "+" adds, the same on every screen (the header's menu, the phone's
 * panel): the kinds of transaction first, then the rest, less often added.
 */
export function useQuickAddActions(): { transactions: QuickAddAction[]; others: QuickAddAction[] } {
  const { t } = useTranslation()

  return {
    transactions: [
      { label: t('quickAdd.withdrawal'), href: '/transactions/new?type=withdrawal', icon: ArrowUpCircle, tone: 'text-negative', tile: 'bg-negative/10' },
      { label: t('quickAdd.deposit'), href: '/transactions/new?type=deposit', icon: ArrowDownCircle, tone: 'text-positive', tile: 'bg-positive/10' },
      { label: t('quickAdd.transfer'), href: '/transactions/new?type=transfer', icon: Repeat, tone: 'text-info', tile: 'bg-info/10' },
      { label: t('quickAdd.trade'), href: '/transactions/investments/new', icon: LineChart, tone: 'text-invest', tile: 'bg-invest/10' },
    ],
    others: [
      { label: t('navigation.assetAccounts'), href: '/accounts/asset/new', icon: Landmark },
      { label: t('navigation.expenseAccounts'), href: '/accounts/expense/new', icon: ShoppingCart },
      { label: t('navigation.revenueAccounts'), href: '/accounts/revenue/new', icon: Banknote },
      { label: t('quickAdd.recurring'), href: '/transactions/recurring/new', icon: CalendarClock },
      { label: t('quickAdd.category'), href: '/categories/new', icon: Tag },
      { label: t('quickAdd.tag'), href: '/tags/new', icon: Tags },
      { label: t('quickAdd.instrument'), href: '/investments/instruments/new', icon: Layers },
    ],
  }
}
