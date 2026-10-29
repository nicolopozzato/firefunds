import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatDistanceToNow } from 'date-fns'
import { AlertTriangle, Bell, BellRing, CalendarClock, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useDismissNotification, useNotifications, useReadAllNotifications, useReadNotification } from '@/hooks/use-notifications'
import { useIsMobile } from '@/hooks/use-media-query'
import { usePushHere } from '@/hooks/use-push-here'
import { describeNotification } from '@/lib/notifications'
import { dateLocale } from '@/lib/report-format'
import { cn } from '@/lib/utils'
import type { AppNotification } from '@/types/notification'

const INVITE_DISMISSED_KEY = 'push-invite-dismissed'

/**
 * The bell: what the app has to tell the user (a recurring transaction not
 * entered, a problem on the server for administrators). A dot while
 * something is unread; each opens the page that deals with it. On top, until
 * turned on or put off, an invite to receive them on this device too.
 */
export default function NotificationBell({ className }: { className?: string }) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [open, setOpen] = useState(false)
  const { data: notifications } = useNotifications()
  const readAll = useReadAllNotifications()
  const unread = (notifications ?? []).filter((notification) => !notification.read).length

  const trigger = (
    <Button
      variant="ghost"
      size="icon"
      className={cn('relative', className)}
      aria-label={unread > 0 ? t('notifications.titleUnread', { count: unread }) : t('notifications.title')}
      onClick={isMobile ? () => setOpen(true) : undefined}
    >
      <Bell className="h-5 w-5" />
      {unread > 0 && (
        <span className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground tabular-nums">
          {unread > 9 ? '9+' : unread}
        </span>
      )}
    </Button>
  )

  const header = (
    <div className="flex items-center justify-between gap-3">
      <span className="font-semibold">{t('notifications.title')}</span>
      {unread > 0 && (
        <button type="button" className="text-xs font-medium text-primary hover:text-primary/80" onClick={() => readAll.mutate()}>
          {t('notifications.readAll')}
        </button>
      )}
    </div>
  )

  const list = (
    <>
      <PushInvite />
      <NotificationList notifications={notifications ?? []} onOpen={() => setOpen(false)} />
    </>
  )

  if (isMobile) {
    return (
      <>
        {trigger}
        <Drawer open={open} onOpenChange={setOpen}>
          <DrawerContent>
            <DrawerHeader className="text-left">
              <DrawerTitle asChild>{header}</DrawerTitle>
              <DrawerDescription className="sr-only">{t('notifications.title')}</DrawerDescription>
            </DrawerHeader>
            <div className="max-h-[70vh] overflow-y-auto px-2 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{list}</div>
          </DrawerContent>
        </Drawer>
      </>
    )
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent align="end" className="w-96 p-0">
        <div className="border-b px-4 py-3">{header}</div>
        <div className="max-h-[28rem] overflow-y-auto p-1.5">{list}</div>
      </PopoverContent>
    </Popover>
  )
}

function PushInvite() {
  const { t } = useTranslation()
  const push = usePushHere()
  const [dismissed, setDismissed] = useState(() => {
    try {
      return localStorage.getItem(INVITE_DISMISSED_KEY) === '1'
    } catch {
      return false
    }
  })

  if (dismissed || push.support !== 'ok' || !push.known || push.on) return null

  const dismiss = () => {
    try {
      localStorage.setItem(INVITE_DISMISSED_KEY, '1')
    } catch {
      // Not remembered on this device: the invite shows again next time.
    }
    setDismissed(true)
  }

  return (
    <div className="mb-1.5 flex items-center gap-3 rounded-md bg-primary/5 px-3 py-2.5">
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
        <BellRing className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-medium">{t('notifications.pushInvite')}</span>
        <span className="block text-xs text-muted-foreground">{t('notifications.pushInviteHint')}</span>
      </span>
      <Button size="sm" disabled={push.busy} onClick={() => void push.enable()}>
        {t('pushPrefs.turnOn')}
      </Button>
      <button
        type="button"
        aria-label={t('notifications.pushInviteLater')}
        title={t('notifications.pushInviteLater')}
        onClick={dismiss}
        className="-mr-1 rounded p-1 text-muted-foreground hover:text-foreground"
      >
        <X className="h-3.5 w-3.5" />
      </button>
    </div>
  )
}

function NotificationList({ notifications, onOpen }: { notifications: AppNotification[]; onOpen: () => void }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const read = useReadNotification()
  const dismiss = useDismissNotification()

  if (notifications.length === 0) {
    return <p className="px-3 py-8 text-center text-sm text-muted-foreground">{t('notifications.empty')}</p>
  }

  return (
    <ul className="space-y-0.5">
      {notifications.map((notification) => {
        const { title, body, link } = describeNotification(notification, t)
        const Icon = notification.type === 'recurring_missing' ? CalendarClock : AlertTriangle
        return (
          <li key={notification.id} className="group relative">
            <button
              type="button"
              onClick={() => {
                if (!notification.read) read.mutate(notification.id)
                onOpen()
                navigate(link)
              }}
              className="flex w-full items-start gap-3 rounded-md px-3 py-2.5 pr-9 text-left transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span
                className={cn(
                  'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                  notification.type === 'recurring_missing' ? 'bg-info/10 text-info' : 'bg-warning/10 text-warning'
                )}
              >
                <Icon className="h-4 w-4" />
              </span>
              <span className="min-w-0 flex-1">
                <span className={cn('block text-sm', !notification.read && 'font-semibold')}>{title}</span>
                {body && <span className="block truncate text-xs text-muted-foreground">{body}</span>}
                <span className="mt-0.5 block text-xs text-muted-foreground">
                  {formatDistanceToNow(new Date(notification.updatedAt), { addSuffix: true, locale: dateLocale(i18n.language) })}
                </span>
              </span>
              {!notification.read && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden />}
            </button>
            <button
              type="button"
              aria-label={t('notifications.dismiss')}
              title={t('notifications.dismiss')}
              onClick={() => dismiss.mutate(notification.id)}
              className="absolute right-1.5 top-2 rounded p-1 text-muted-foreground opacity-0 transition-opacity hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100 max-md:opacity-100"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </li>
        )
      })}
    </ul>
  )
}
