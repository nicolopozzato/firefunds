import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { cn, formatMoney, formatShortDate } from '@/lib/utils'
import type { CashFlowFlow, CashFlowItem, CashFlowLayer, CashFlowPeriod } from '@/types/report'

interface CashFlowPeriodDialogProps {
  period: CashFlowPeriod | null
  title: string
  currency: string | null
  onClose: () => void
}

const SOURCES: { source: CashFlowLayer; title: string }[] = [
  { source: 'entered', title: 'reports.cashFlow.fromEntered' },
  { source: 'recurring', title: 'reports.cashFlow.fromRecurring' },
  { source: 'estimated', title: 'reports.cashFlow.fromEstimated' },
]

const FLOW_CLASS: Record<CashFlowFlow, string> = {
  income: 'text-positive',
  expense: 'text-negative',
  invested: 'text-invest',
  transfer: 'text-info',
}

function signed(item: CashFlowItem, currency: string | null): string {
  const amount = formatMoney(item.amount, currency)
  if (item.flow === 'income') return `+${amount}`
  if (item.flow === 'transfer') return amount
  return `−${amount}`
}

/** Where an item leads: the transaction, the recurrence, the category. */
function itemLink(item: CashFlowItem): string | null {
  if (item.transactionId) return `/transactions/${item.transactionId}`
  if (item.recurringId) return `/transactions/recurring/${item.recurringId}`
  if (item.categoryId) return `/categories/${item.categoryId}`
  return null
}

function ItemRow({ item, currency }: { item: CashFlowItem; currency: string | null }) {
  const { t } = useTranslation()
  const link = itemLink(item)
  const label = item.label ?? (item.source === 'estimated' ? t('reports.noCategory') : '—')
  const where = item.toAccountName ? `${item.accountName} → ${item.toAccountName}` : item.accountName
  const meta = [item.day ? formatShortDate(item.day) : t('reports.cashFlow.spread'), where].filter(Boolean).join(' · ')

  return (
    <li className="flex items-center gap-3 py-2">
      <div className="min-w-0 flex-1">
        {link ? (
          <Link to={link} className="block truncate text-sm font-medium text-link">
            {label}
          </Link>
        ) : (
          <span className="block truncate text-sm font-medium">{label}</span>
        )}
        <span className="block truncate text-xs text-muted-foreground">{meta}</span>
      </div>
      <span className={cn('shrink-0 text-sm font-semibold tabular-nums', FLOW_CLASS[item.flow])}>{signed(item, currency)}</span>
    </li>
  )
}

/** What a column of the cash flow report expects, by where it comes from. A panel from the bottom on phones. */
export default function CashFlowPeriodDialog({ period, title, currency, onClose }: CashFlowPeriodDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()

  const description = period
    ? `${t('reports.cashFlow.netWorth')}: ${formatMoney(period.netWorth, currency)} · ${t('reports.cashFlow.cash')}: ${formatMoney(period.balance, currency)}`
    : ''
  const groups = period ? SOURCES.map((group) => ({ ...group, items: period.items.filter((item) => item.source === group.source) })).filter((group) => group.items.length > 0) : []
  const content =
    groups.length === 0 ? (
      <p className="py-8 text-center text-sm text-muted-foreground">{t('reports.cashFlow.nothing')}</p>
    ) : (
      <div className="space-y-4">
        {groups.map((group) => (
          <section key={group.source}>
            <h4 className="group-label">{t(group.title)}</h4>
            <ul className="divide-y">
              {group.items.map((item, index) => (
                <ItemRow key={`${item.source}-${item.transactionId ?? item.recurringId ?? item.categoryId ?? item.flow}-${index}`} item={item} currency={currency} />
              ))}
            </ul>
          </section>
        ))}
      </div>
    )

  if (isMobile) {
    return (
      <Drawer open={period !== null} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent className="max-h-[85dvh]">
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription>{description}</DrawerDescription>
          </DrawerHeader>
          <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-6">{content}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={period !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="flex max-h-[85vh] max-w-xl flex-col gap-3 overflow-hidden">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <div className="min-h-0 flex-1 overflow-y-auto pr-1">{content}</div>
      </DialogContent>
    </Dialog>
  )
}
