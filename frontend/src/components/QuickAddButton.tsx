import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useQuickAddActions } from '@/components/layout/quick-add'
import { cn } from '@/lib/utils'

/** The header's "+": the same things to add as the phone's panel (QuickAddDrawer). */
export default function QuickAddButton() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { transactions, others } = useQuickAddActions()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button size="icon" className="rounded-full" aria-label={t('quickAdd.title')} title={t('quickAdd.title')}>
          <Plus className="h-5 w-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">{t('quickAdd.transaction')}</DropdownMenuLabel>
        {transactions.map((action) => (
          <DropdownMenuItem key={action.href} onClick={() => navigate(action.href)}>
            <action.icon className={cn('mr-2 h-4 w-4', action.tone)} />
            {action.label}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">{t('quickAdd.other')}</DropdownMenuLabel>
        {others.map((action) => (
          <DropdownMenuItem key={action.href} onClick={() => navigate(action.href)}>
            <action.icon className="mr-2 h-4 w-4 text-muted-foreground" />
            {action.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
