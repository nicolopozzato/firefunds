import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, ArrowUpCircle, ArrowDownCircle, Repeat, Landmark, ShoppingCart, Banknote, Tag } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

export default function QuickAddButton() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button size="icon" className="rounded-full">
          <Plus className="h-5 w-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
          {t('quickAdd.transaction')}
        </DropdownMenuLabel>
        <DropdownMenuItem onClick={() => navigate('/transactions/new?type=withdrawal')}>
          <ArrowUpCircle className="mr-2 h-4 w-4 text-negative" />
          {t('navigation.withdrawalTransactions')}
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => navigate('/transactions/new?type=deposit')}>
          <ArrowDownCircle className="mr-2 h-4 w-4 text-positive" />
          {t('navigation.depositTransactions')}
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => navigate('/transactions/new?type=transfer')}>
          <Repeat className="mr-2 h-4 w-4 text-info" />
          {t('navigation.transferTransactions')}
        </DropdownMenuItem>

        <DropdownMenuSeparator />
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
          {t('quickAdd.account')}
        </DropdownMenuLabel>
        <DropdownMenuItem onClick={() => navigate('/accounts/asset/new')}>
          <Landmark className="mr-2 h-4 w-4" />
          {t('navigation.assetAccounts')}
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => navigate('/accounts/expense/new')}>
          <ShoppingCart className="mr-2 h-4 w-4" />
          {t('navigation.expenseAccounts')}
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => navigate('/accounts/revenue/new')}>
          <Banknote className="mr-2 h-4 w-4" />
          {t('navigation.revenueAccounts')}
        </DropdownMenuItem>

        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => navigate('/categories/new')}>
          <Tag className="mr-2 h-4 w-4" />
          {t('quickAdd.category')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
