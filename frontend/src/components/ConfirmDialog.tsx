import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useIsMobile } from '@/hooks/use-media-query'

interface ConfirmDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: ReactNode
  description?: ReactNode
  /** The red button: "Delete" unless said otherwise. */
  confirmLabel?: ReactNode
  onConfirm: () => void
  pending?: boolean
  /** Between the text and the buttons, e.g. a choice about what goes with it. */
  children?: ReactNode
}

/**
 * Asks before something that can't be taken back (deleting, mostly): a dialog
 * in the middle of larger screens, a panel from the bottom on phones, like the
 * other editors there.
 */
export default function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel, onConfirm, pending, children }: ConfirmDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()

  const cancel = (
    <Button variant="outline" onClick={() => onOpenChange(false)}>
      {t('common.cancel')}
    </Button>
  )
  const confirm = (
    <Button variant="destructive" onClick={onConfirm} disabled={pending}>
      {confirmLabel ?? t('common.delete')}
    </Button>
  )

  if (isMobile) {
    return (
      <Drawer open={open} onOpenChange={onOpenChange}>
        <DrawerContent>
          <DrawerHeader className="text-left">
            <DrawerTitle>{title}</DrawerTitle>
            {description ? <DrawerDescription>{description}</DrawerDescription> : <DrawerDescription className="sr-only">{title}</DrawerDescription>}
          </DrawerHeader>
          {children && <div className="px-4">{children}</div>}
          <DrawerFooter className="pb-[calc(env(safe-area-inset-bottom)+1rem)] [&>button]:h-11">
            {confirm}
            {cancel}
          </DrawerFooter>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? <DialogDescription>{description}</DialogDescription> : <DialogDescription className="sr-only">{title}</DialogDescription>}
        </DialogHeader>
        {children}
        <DialogFooter>
          {cancel}
          {confirm}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
