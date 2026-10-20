import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MoreHorizontal, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'
import { cn } from '@/lib/utils'

export interface PageAction {
  label: string
  icon: LucideIcon
  onClick: () => void
  /** Red, and last in the phone menu (e.g. delete). */
  destructive?: boolean
  /** Phones: an icon of its own beside the title instead of in the menu (e.g. edit). */
  primary?: boolean
}

/**
 * A detail page's actions (edit, clone, delete…), for PageHeader. Larger
 * screens: a row of buttons. Phones, as their apps do: the primary one as an
 * icon beside the title, the others behind "⋯" in a panel from the bottom,
 * destructive ones last.
 */
export default function PageActions({ actions }: { actions: PageAction[] }) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [menuOpen, setMenuOpen] = useState(false)

  if (!isMobile) {
    return (
      <>
        {actions.map((action) => (
          <Button
            key={action.label}
            variant="outline"
            className={cn(action.destructive && 'text-destructive hover:bg-destructive/10 hover:text-destructive')}
            onClick={action.onClick}
          >
            <action.icon className="mr-2 h-4 w-4" />
            {action.label}
          </Button>
        ))}
      </>
    )
  }

  const primary = actions.filter((action) => action.primary)
  const menu = [
    ...actions.filter((action) => !action.primary && !action.destructive),
    ...actions.filter((action) => !action.primary && action.destructive),
  ]

  const run = (action: PageAction) => {
    setMenuOpen(false)
    action.onClick()
  }

  return (
    <>
      {primary.map((action) => (
        <Button key={action.label} variant="ghost" size="icon" aria-label={action.label} title={action.label} onClick={action.onClick}>
          <action.icon className="h-5 w-5" />
        </Button>
      ))}
      {menu.length > 0 && (
        <>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t('common.moreActions')}
            title={t('common.moreActions')}
            onClick={() => setMenuOpen(true)}
          >
            <MoreHorizontal className="h-5 w-5" />
          </Button>
          <Drawer open={menuOpen} onOpenChange={setMenuOpen}>
            <DrawerContent>
              <DrawerHeader className="sr-only">
                <DrawerTitle>{t('common.moreActions')}</DrawerTitle>
                <DrawerDescription>{t('common.moreActions')}</DrawerDescription>
              </DrawerHeader>
              <div className="px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)] pt-2">
                <div className="divide-y overflow-hidden rounded-2xl border bg-card">
                  {menu.map((action) => (
                    <button
                      key={action.label}
                      type="button"
                      onClick={() => run(action)}
                      className={cn(
                        'flex w-full items-center gap-3 px-4 py-3.5 text-left text-sm font-medium transition-colors active:bg-accent',
                        action.destructive && 'text-destructive'
                      )}
                    >
                      <action.icon className="h-5 w-5" />
                      {action.label}
                    </button>
                  ))}
                </div>
              </div>
            </DrawerContent>
          </Drawer>
        </>
      )}
    </>
  )
}
