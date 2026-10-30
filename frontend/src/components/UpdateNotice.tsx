import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowUpCircle, ChevronRight } from 'lucide-react'
import { useAvailableUpdate } from '@/hooks/use-admin'
import { useIsMobile } from '@/hooks/use-media-query'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'

const UPGRADE_COMMAND = 'docker compose pull && docker compose up -d'
/** The newest version whose popup this device has shown already. */
const SEEN_KEY = 'update-seen'

function readSeen(): string | null {
  try {
    return localStorage.getItem(SEEN_KEY)
  } catch {
    return null
  }
}

/** A row on top of the phone's "More" page while a newer FireFunds is out (administrators only): opens its release notes. */
export function UpdateRow() {
  const { t } = useTranslation()
  const update = useAvailableUpdate()
  if (!update) return null

  return (
    <a
      href={update.latest.url}
      target="_blank"
      rel="noreferrer"
      className="flex items-center gap-3 surface px-4 py-3 transition-colors active:bg-accent"
    >
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-info/10 text-info">
        <ArrowUpCircle className="h-5 w-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-medium">{t('update.available', { version: update.latest.version })}</span>
        <span className="block text-xs text-muted-foreground">
          {t('update.current', { version: update.current })} · {t('update.releaseNotes')}
        </span>
      </span>
      <ChevronRight className="h-4 w-4 text-muted-foreground/60" />
    </a>
  )
}

/**
 * Tells an administrator once per version, on each device, that a newer
 * FireFunds is out: a dialog on larger screens, a panel from the bottom on phones.
 */
export function UpdateDialog() {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const update = useAvailableUpdate()
  const [seen, setSeen] = useState(readSeen)

  if (!update || seen === update.latest.version) return null

  const close = () => {
    try {
      localStorage.setItem(SEEN_KEY, update.latest.version)
    } catch {
      // Private mode: it shows again next time.
    }
    setSeen(update.latest.version)
  }
  const onOpenChange = (open: boolean) => {
    if (!open) close()
  }

  const title = t('update.available', { version: update.latest.version })
  const body = (
    <div className="space-y-2 text-sm">
      <p className="text-muted-foreground">{t('update.howTo')}</p>
      <code className="block overflow-x-auto whitespace-nowrap rounded-md bg-muted px-3 py-2 font-mono text-xs">{UPGRADE_COMMAND}</code>
    </div>
  )
  const later = (
    <Button variant="outline" onClick={close}>
      {t('update.later')}
    </Button>
  )
  const notes = (
    <Button asChild onClick={close}>
      <a href={update.latest.url} target="_blank" rel="noreferrer">
        {t('update.releaseNotes')}
      </a>
    </Button>
  )

  if (isMobile) {
    return (
      <Drawer open onOpenChange={onOpenChange}>
        <DrawerContent>
          <DrawerHeader className="text-left">
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription>{t('update.current', { version: update.current })}</DrawerDescription>
          </DrawerHeader>
          <div className="px-4">{body}</div>
          <DrawerFooter className="pb-[calc(env(safe-area-inset-bottom)+1rem)] [&>*]:h-11">
            {notes}
            {later}
          </DrawerFooter>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{t('update.current', { version: update.current })}</DialogDescription>
        </DialogHeader>
        {body}
        <DialogFooter>
          {later}
          {notes}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
