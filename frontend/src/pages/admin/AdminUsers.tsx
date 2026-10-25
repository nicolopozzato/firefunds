import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { KeyRound, MoreHorizontal, Pencil, Plus, UserCheck, UserX, type LucideIcon } from 'lucide-react'
import AdminTabs from '@/components/layout/AdminTabs'
import AdminUserDialog, { type AdminUserDialogState } from '@/components/AdminUserDialog'
import PageHeader from '@/components/PageHeader'
import { MobileListCard, MobileListRow } from '@/components/MobileList'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import DateWithTime from '@/components/DateWithTime'
import { useAuth } from '@/contexts/AuthContext'
import { useAdminUserMutations, useAdminUsers } from '@/hooks/use-admin'
import { useIsMobile } from '@/hooks/use-media-query'
import { handleApiError } from '@/lib/error-handler'
import { cn, formatDateTime } from '@/lib/utils'
import type { AdminUser } from '@/types/admin'

interface UserAction {
  label: string
  icon: LucideIcon
  onClick: () => void
  destructive?: boolean
}

/**
 * Everyone who can sign in, the disabled ones last (the server's order). An
 * administrator adds users, edits them, sets their password, disables and
 * enables them; nobody is ever deleted. Their own password and account are
 * changed from the profile, so those actions aren't offered on their own row.
 */
export default function AdminUsers() {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const { user: me } = useAuth()
  const { data: users, isLoading } = useAdminUsers()
  const { setActive } = useAdminUserMutations()
  const [dialog, setDialog] = useState<AdminUserDialogState>(null)
  const [menuFor, setMenuFor] = useState<AdminUser | null>(null)

  const activeAdmins = users?.filter((user) => user.isAdmin && user.isActive).length ?? 0
  const isLastAdmin = (user: AdminUser) => user.isAdmin && user.isActive && activeAdmins <= 1

  const enable = async (user: AdminUser) => {
    try {
      await setActive.mutateAsync({ id: user.id, active: true })
      toast.success(t('admin.users.enabledDone'))
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const actionsFor = (user: AdminUser): UserAction[] => {
    const actions: UserAction[] = [{ label: t('common.edit'), icon: Pencil, onClick: () => setDialog({ kind: 'edit', user }) }]
    if (user.id === me?.id) return actions

    actions.push({ label: t('admin.users.resetPassword'), icon: KeyRound, onClick: () => setDialog({ kind: 'password', user }) })
    if (!user.isActive) {
      actions.push({ label: t('admin.users.enable'), icon: UserCheck, onClick: () => void enable(user) })
    } else if (!isLastAdmin(user)) {
      actions.push({ label: t('admin.users.disable'), icon: UserX, destructive: true, onClick: () => setDialog({ kind: 'disable', user }) })
    }
    return actions
  }

  /** Disabled, or still to change the password an administrator gave; nothing when all is well. */
  const statusBadges = (user: AdminUser) => {
    if (!user.isActive) {
      return <Badge variant="outline" className="border-negative/40 text-negative">{t('admin.users.disabled')}</Badge>
    }
    if (user.mustChangePassword) {
      return <Badge variant="outline" className="border-warning/40 text-warning">{t('admin.users.passwordPending')}</Badge>
    }
    return null
  }

  const badges = (user: AdminUser) => (
    <>
      {user.id === me?.id && <Badge variant="outline">{t('admin.users.you')}</Badge>}
      {user.isAdmin && <Badge variant="secondary">{t('common.admin')}</Badge>}
      {statusBadges(user)}
    </>
  )

  const lastSeen = (user: AdminUser) =>
    user.lastSeenAt ? t('admin.users.lastSeenOn', { date: formatDateTime(user.lastSeenAt) }) : t('admin.users.neverSeen')

  const runFromMenu = (action: UserAction) => {
    setMenuFor(null)
    action.onClick()
  }

  return (
    <div className="space-y-6">
      <AdminTabs />
      <PageHeader
        title={t('admin.users.title')}
        description={t('admin.users.description')}
        actions={
          <Button onClick={() => setDialog({ kind: 'new' })}>
            <Plus className="mr-2 h-4 w-4" />
            {t('admin.users.new')}
          </Button>
        }
      />

      {isLoading ? (
        <div className="py-12 text-center text-sm text-muted-foreground">{t('common.loading')}</div>
      ) : isMobile ? (
        <MobileListCard>
          {users?.map((user) => (
            <MobileListRow
              key={user.id}
              title={<span className={cn(!user.isActive && 'text-muted-foreground')}>{user.firstName} {user.lastName}</span>}
              subtitle={user.email}
              extra={
                <>
                  <span className="block text-xs text-muted-foreground">{lastSeen(user)}</span>
                  <span className="mt-1 flex flex-wrap gap-1">{badges(user)}</span>
                </>
              }
              chevron
              onClick={() => setMenuFor(user)}
            />
          ))}
        </MobileListCard>
      ) : (
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('admin.users.name')}</TableHead>
                  <TableHead>{t('common.email')}</TableHead>
                  <TableHead>{t('admin.users.role')}</TableHead>
                  <TableHead>{t('admin.users.status')}</TableHead>
                  <TableHead>{t('admin.users.lastSeen')}</TableHead>
                  <TableHead>{t('admin.users.createdAt')}</TableHead>
                  <TableHead className="w-12" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {users?.map((user) => (
                  <TableRow key={user.id} className={cn(!user.isActive && 'text-muted-foreground')}>
                    <TableCell className="font-medium">
                      <span className="flex items-center gap-2">
                        {user.firstName} {user.lastName}
                        {user.id === me?.id && <Badge variant="outline">{t('admin.users.you')}</Badge>}
                      </span>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{user.email}</TableCell>
                    <TableCell>
                      <Badge variant={user.isAdmin ? 'secondary' : 'outline'}>
                        {user.isAdmin ? t('common.admin') : t('admin.users.regularUser')}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1">
                        {user.disabledAt ? (
                          <span title={t('admin.users.disabledOn', { date: formatDateTime(user.disabledAt) })}>{statusBadges(user)}</span>
                        ) : (
                          statusBadges(user) ?? <span className="text-sm text-muted-foreground">{t('admin.users.active')}</span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {user.lastSeenAt ? <DateWithTime date={user.lastSeenAt} /> : t('admin.users.never')}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      <DateWithTime date={user.createdAt} />
                    </TableCell>
                    <TableCell>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" aria-label={t('common.moreActions')}>
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          {actionsFor(user).map((action) => (
                            <DropdownMenuItem
                              key={action.label}
                              className={cn(action.destructive && 'text-destructive focus:text-destructive')}
                              onClick={action.onClick}
                            >
                              <action.icon className="mr-2 h-4 w-4" />
                              {action.label}
                            </DropdownMenuItem>
                          ))}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Phones: a user's actions in a panel from the bottom. */}
      <Drawer open={menuFor !== null} onOpenChange={(open) => !open && setMenuFor(null)}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{menuFor && `${menuFor.firstName} ${menuFor.lastName}`}</DrawerTitle>
            <DrawerDescription>
              {menuFor?.disabledAt ? t('admin.users.disabledOn', { date: formatDateTime(menuFor.disabledAt) }) : menuFor?.email}
            </DrawerDescription>
          </DrawerHeader>
          <div className="px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)] pt-2">
            <div className="divide-y overflow-hidden rounded-2xl border bg-card">
              {menuFor &&
                actionsFor(menuFor).map((action) => (
                  <button
                    key={action.label}
                    type="button"
                    onClick={() => runFromMenu(action)}
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

      <AdminUserDialog state={dialog} isLastAdmin={isLastAdmin} onClose={() => setDialog(null)} />
    </div>
  )
}
