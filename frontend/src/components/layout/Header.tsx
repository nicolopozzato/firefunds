import { useLocation, useNavigate } from 'react-router-dom'
import { ArrowUpCircle, LogOut, Settings, ShieldCheck } from 'lucide-react'
import NotificationBell from './NotificationBell'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { getFullName } from '@/types/auth'
import TimeframeSelector from '@/components/TimeframeSelector'
import QuickAddButton from '@/components/QuickAddButton'
import HideAmountsButton from '@/components/HideAmountsButton'
import SearchBox from '@/components/layout/SearchBox'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { useAvailableUpdate } from '@/hooks/use-admin'

export default function Header() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const update = useAvailableUpdate()
  // The reports choose their own range: the app's period means nothing there.
  const showsPeriod = !useLocation().pathname.startsWith('/reports')

  const getInitials = () => {
    if (!user) return 'U'
    return `${user.firstName[0]}${user.lastName[0]}`.toUpperCase()
  }

  return (
    <header className="flex h-16 items-center justify-between border-b bg-background px-6">
      {/* The search box gives up width first, so on a tablet the buttons on the right stay on screen. */}
      <div className="flex min-w-0 flex-1 items-center gap-4 pr-3">
        <SearchBox className="w-full max-w-96" />
      </div>

      <div className="flex items-center gap-3">
        {showsPeriod && <TimeframeSelector />}
        <QuickAddButton />
        <HideAmountsButton />

        <NotificationBell />

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="relative h-10 w-10 rounded-full">
              <Avatar>
                <AvatarFallback className="bg-gradient-to-br from-[hsl(var(--series-2))] to-[hsl(var(--series-5))] text-white">
                  {getInitials()}
                </AvatarFallback>
              </Avatar>
              {update && <span className="absolute right-0.5 top-0.5 h-2.5 w-2.5 rounded-full bg-info ring-2 ring-background" />}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-64">
            {/* Who is signed in: opens their account (name, email, password). */}
            <DropdownMenuItem onClick={() => navigate('/preferences?tab=account')} className="flex-col items-start gap-1">
              <div className="flex items-center gap-2">
                <p className="text-sm font-medium">{user ? getFullName(user) : ''}</p>
                {user?.isAdmin && (
                  <Badge variant="secondary" className="text-xs">{t('common.admin')}</Badge>
                )}
              </div>
              <p className="text-xs text-muted-foreground">
                {user?.email}
              </p>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => navigate('/preferences')}>
              <Settings className="mr-2 h-4 w-4" />
              {t('preferences.title')}
            </DropdownMenuItem>
            {user?.isAdmin && (
              <DropdownMenuItem onClick={() => navigate('/admin/settings')}>
                <ShieldCheck className="mr-2 h-4 w-4" />
                {t('admin.title')}
              </DropdownMenuItem>
            )}
            {update && (
              <DropdownMenuItem asChild>
                <a href={update.latest.url} target="_blank" rel="noreferrer" className="text-info focus:text-info">
                  <ArrowUpCircle className="mr-2 h-4 w-4" />
                  {t('update.available', { version: update.latest.version })}
                </a>
              </DropdownMenuItem>
            )}

            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={logout} className="text-destructive focus:text-destructive">
              <LogOut className="mr-2 h-4 w-4" />
              {t('auth.logout')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  )
}
