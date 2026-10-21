import { useLocation, useNavigate } from 'react-router-dom'
import { Bell, LogOut, User as UserIcon, Settings, ShieldCheck, Users, Coins, DatabaseBackup } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { getFullName } from '@/types/auth'
import TimeframeSelector from '@/components/TimeframeSelector'
import QuickAddButton from '@/components/QuickAddButton'
import SearchBox from '@/components/layout/SearchBox'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'

export default function Header() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
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

        <Button variant="ghost" size="icon" className="relative">
          <Bell className="h-5 w-5" />
          <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-destructive" />
        </Button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="relative h-10 w-10 rounded-full">
              <Avatar>
                <AvatarFallback className="bg-gradient-to-br from-[hsl(var(--series-2))] to-[hsl(var(--series-5))] text-white">
                  {getInitials()}
                </AvatarFallback>
              </Avatar>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-64">
            <DropdownMenuLabel>
              <div className="flex flex-col space-y-1">
                <div className="flex items-center gap-2">
                  <p className="text-sm font-medium">{user ? getFullName(user) : ''}</p>
                  {user?.isAdmin && (
                    <Badge variant="secondary" className="text-xs">{t('common.admin')}</Badge>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {user?.email}
                </p>
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => navigate('/profile')}>
              <UserIcon className="mr-2 h-4 w-4" />
              {t('common.profile')}
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => navigate('/preferences')}>
              <Settings className="mr-2 h-4 w-4" />
              {t('preferences.title')}
            </DropdownMenuItem>

            {user?.isAdmin && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                  {t('admin.title')}
                </DropdownMenuLabel>
                <DropdownMenuItem onClick={() => navigate('/admin/users')}>
                  <Users className="mr-2 h-4 w-4" />
                  {t('admin.users.title')}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => navigate('/admin/settings')}>
                  <ShieldCheck className="mr-2 h-4 w-4" />
                  {t('admin.settings.title')}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => navigate('/admin/backups')}>
                  <DatabaseBackup className="mr-2 h-4 w-4" />
                  {t('admin.backups.title')}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => navigate('/admin/currencies')}>
                  <Coins className="mr-2 h-4 w-4" />
                  {t('admin.currencies.title')}
                </DropdownMenuItem>
              </>
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
