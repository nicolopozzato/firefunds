import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import TransactionList from '@/components/TransactionList'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { useCategoryTransactions } from '@/hooks/use-transactions'
import { formatMoney } from '@/lib/utils'
import type { CategoryCell } from './CategoryTable'

interface CategoryTransactionsDialogProps {
  cell: CategoryCell | null
  currency: string | null
  onClose: () => void
}

/**
 * The transactions behind a report cell: one category's expenses or income
 * in one column (or the whole range). A panel from the bottom on phones.
 */
export default function CategoryTransactionsDialog({ cell, currency, onClose }: CategoryTransactionsDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [page, setPage] = useState(1)
  const [shownCell, setShownCell] = useState(cell)
  // Another cell starts again from its first page.
  if (cell !== shownCell) {
    setShownCell(cell)
    setPage(1)
  }

  const filter = cell
    ? { kind: cell.flow === 'expense' ? ('withdrawal' as const) : ('deposit' as const), categoryId: cell.categoryId ?? 'none', from: cell.from, to: cell.to }
    : null
  const { data, isLoading } = useCategoryTransactions(filter, page)

  const title = cell ? `${cell.categoryName} · ${cell.label}` : ''
  const description = cell ? t(cell.flow === 'expense' ? 'reports.cellExpenses' : 'reports.cellIncome', { amount: formatMoney(cell.total, currency) }) : ''
  const list = (
    <TransactionList data={data} isLoading={isLoading} onPageChange={setPage} empty={<p className="py-8 text-center text-sm text-muted-foreground">{t('charts.noData')}</p>} />
  )

  if (isMobile) {
    return (
      <Drawer open={cell !== null} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent className="h-[85dvh]">
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription>{description}</DrawerDescription>
          </DrawerHeader>
          <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-6">{list}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={cell !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="flex max-h-[85vh] max-w-5xl flex-col gap-3 overflow-hidden">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <div className="min-h-0 overflow-y-auto">{list}</div>
      </DialogContent>
    </Dialog>
  )
}
