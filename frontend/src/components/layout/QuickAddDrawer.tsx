import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useQuickAddActions } from '@/components/layout/quick-add'

interface QuickAddDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Phone version of the header's "+" menu (the same list, quick-add.ts),
 * opened from the bottom bar: the four kinds of transaction as big tiles,
 * then the less frequent things to add.
 */
export default function QuickAddDrawer({ open, onOpenChange }: QuickAddDrawerProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const go = (href: string) => {
    onOpenChange(false)
    navigate(href)
  }

  const { transactions, others } = useQuickAddActions()

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
                className="flex flex-col items-start gap-3 surface p-4 text-left text-sm font-medium transition-colors active:bg-accent"
              >
                <span className={cn('flex h-10 w-10 items-center justify-center rounded-xl', action.tile, action.tone)}>
                  <action.icon className="h-5 w-5" />
                </span>
                {action.label}
              </button>
            ))}
          </div>

          <p className="group-label mb-1.5 mt-5 px-1">
            {t('quickAdd.other')}
          </p>
          <div className="divide-y overflow-hidden surface">
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
