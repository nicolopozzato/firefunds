import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MoreHorizontal, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
 * A detail page's actions (edit, clone, delete…), for PageHeader. The primary
 * one is always in sight, the others behind "⋯", destructive ones last.
 * Larger screens: a button and a menu. Phones, as their apps do: an icon
 * beside the title, and the menu in a panel from the bottom.
 */
export default function PageActions({ actions }: { actions: PageAction[] }) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [menuOpen, setMenuOpen] = useState(false)

  const primary = actions.filter((action) => action.primary)
  const others = actions.filter((action) => !action.primary && !action.destructive)
  const destructive = actions.filter((action) => !action.primary && action.destructive)
  const menu = [...others, ...destructive]

  if (!isMobile) {
    return (
      <>
        {menu.length > 0 && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="icon" aria-label={t('common.moreActions')} title={t('common.moreActions')}>
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
              {others.map((action) => (
                <DropdownMenuItem key={action.label} onSelect={action.onClick}>
                  <action.icon className="mr-2 h-4 w-4" />
                  {action.label}
                </DropdownMenuItem>
              ))}
              {others.length > 0 && destructive.length > 0 && <DropdownMenuSeparator />}
              {destructive.map((action) => (
                <DropdownMenuItem key={action.label} onSelect={action.onClick} className="text-destructive focus:text-destructive">
                  <action.icon className="mr-2 h-4 w-4" />
                  {action.label}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
        {primary.map((action) => (
          <Button key={action.label} onClick={action.onClick}>
            <action.icon className="mr-2 h-4 w-4" />
            {action.label}
          </Button>
        ))}
      </>
    )
  }

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
                <div className="divide-y overflow-hidden surface">
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
