import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Drawer, DrawerContent, DrawerDescription, DrawerHeader, DrawerTitle } from '@/components/ui/drawer'
import { useAdminUserMutations } from '@/hooks/use-admin'
import { useIsMobile } from '@/hooks/use-media-query'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import type { AdminUser } from '@/types/admin'

/** What the dialog is open for; null: closed. */
export type AdminUserDialogState =
  | { kind: 'new' }
  | { kind: 'edit'; user: AdminUser }
  | { kind: 'password'; user: AdminUser }
  | { kind: 'disable'; user: AdminUser }
  | null

interface AdminUserDialogProps {
  state: AdminUserDialogState
  /** The only active administrator: the role can't be taken away from them. */
  isLastAdmin: (user: AdminUser) => boolean
  onClose: () => void
}

const fullName = (user: AdminUser) => `${user.firstName} ${user.lastName}`

/**
 * Creates a user, edits one, sets their password or confirms disabling them:
 * a dialog, a panel from the bottom on phones.
 */
export default function AdminUserDialog({ state, isLastAdmin, onClose }: AdminUserDialogProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const isOpen = state !== null

  let title = ''
  let description: string | undefined
  let body: ReactNode = null
  if (state?.kind === 'new') {
    title = t('admin.users.new')
    body = <UserForm onDone={onClose} />
  } else if (state?.kind === 'edit') {
    title = t('admin.users.editTitle')
    body = <UserForm key={state.user.id} user={state.user} lockAdmin={isLastAdmin(state.user)} onDone={onClose} />
  } else if (state?.kind === 'password') {
    title = t('admin.users.passwordTitle')
    description = `${fullName(state.user)} · ${t('admin.users.resetPasswordHint')}`
    body = <PasswordForm key={state.user.id} user={state.user} onDone={onClose} />
  } else if (state?.kind === 'disable') {
    title = t('admin.users.disableTitle', { name: fullName(state.user) })
    description = t('admin.users.disableConfirm')
    body = <DisableConfirm key={state.user.id} user={state.user} onDone={onClose} />
  }

  if (isMobile) {
    return (
      <Drawer open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>{title}</DrawerTitle>
            <DrawerDescription className={description ? undefined : 'sr-only'}>{description ?? title}</DrawerDescription>
          </DrawerHeader>
          <div className="px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">{body}</div>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className={description ? undefined : 'sr-only'}>{description ?? title}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  )
}

function Buttons({ onCancel, busy, children }: { onCancel: () => void; busy: boolean; children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
      <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
        {t('common.cancel')}
      </Button>
      {children}
    </div>
  )
}

function SwitchRow({ id, label, hint, checked, disabled, onChange }: {
  id: string
  label: string
  hint?: string
  checked: boolean
  disabled?: boolean
  onChange: (checked: boolean) => void
}) {
  return (
    <div className="flex items-center justify-between gap-4">
      <div className="space-y-0.5">
        <Label htmlFor={id}>{label}</Label>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </div>
      <Switch id={id} checked={checked} disabled={disabled} onCheckedChange={onChange} />
    </div>
  )
}

/** New user (with a password), or the name, email and role of one. */
function UserForm({ user, lockAdmin = false, onDone }: { user?: AdminUser; lockAdmin?: boolean; onDone: () => void }) {
  const { t } = useTranslation()
  const { user: me, refreshUser } = useAuth()
  const { create, update } = useAdminUserMutations()
  const [firstName, setFirstName] = useState(user?.firstName ?? '')
  const [lastName, setLastName] = useState(user?.lastName ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [isAdmin, setIsAdmin] = useState(user?.isAdmin ?? false)
  const [password, setPassword] = useState('')
  const [mustChangePassword, setMustChangePassword] = useState(true)
  const busy = create.isPending || update.isPending

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const fields = { firstName: firstName.trim(), lastName: lastName.trim(), email: email.trim(), isAdmin }
    try {
      if (user) {
        await update.mutateAsync({ id: user.id, data: fields })
        // Their own name, email or role: the header and the menu follow.
        if (user.id === me?.id) await refreshUser()
        toast.success(t('admin.users.saved'))
      } else {
        await create.mutateAsync({ ...fields, password, mustChangePassword })
        toast.success(t('admin.users.created'))
      }
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="user-first-name">{t('common.firstName')}</Label>
          <Input id="user-first-name" required minLength={2} maxLength={100} autoComplete="off" value={firstName} onChange={(e) => setFirstName(e.target.value)} />
        </div>
        <div className="min-w-0 space-y-1.5">
          <Label htmlFor="user-last-name">{t('common.lastName')}</Label>
          <Input id="user-last-name" required minLength={2} maxLength={100} autoComplete="off" value={lastName} onChange={(e) => setLastName(e.target.value)} />
        </div>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="user-email">{t('common.email')}</Label>
        <Input id="user-email" type="email" required autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
      </div>
      {!user && (
        <div className="space-y-1.5">
          <Label htmlFor="user-password">{t('common.password')}</Label>
          <Input id="user-password" type="text" required minLength={8} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
      )}
      {!user && (
        <SwitchRow
          id="user-must-change"
          label={t('admin.users.mustChangePassword')}
          checked={mustChangePassword}
          onChange={setMustChangePassword}
        />
      )}
      <SwitchRow
        id="user-admin"
        label={t('admin.users.isAdmin')}
        hint={lockAdmin ? t('admin.users.lastAdmin') : undefined}
        checked={isAdmin}
        disabled={lockAdmin}
        onChange={setIsAdmin}
      />
      <Buttons onCancel={onDone} busy={busy}>
        <Button type="submit" disabled={busy}>
          {user ? t('common.save') : t('admin.users.create')}
        </Button>
      </Buttons>
    </form>
  )
}

function PasswordForm({ user, onDone }: { user: AdminUser; onDone: () => void }) {
  const { t } = useTranslation()
  const { setPassword: mutation } = useAdminUserMutations()
  const [password, setPassword] = useState('')
  const [mustChangePassword, setMustChangePassword] = useState(true)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    try {
      await mutation.mutateAsync({ id: user.id, data: { password, mustChangePassword } })
      toast.success(t('admin.users.passwordSet'))
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="space-y-1.5">
        <Label htmlFor="user-new-password">{t('profile.newPassword')}</Label>
        <Input id="user-new-password" type="text" required minLength={8} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
      </div>
      <SwitchRow
        id="user-reset-must-change"
        label={t('admin.users.mustChangePassword')}
        checked={mustChangePassword}
        onChange={setMustChangePassword}
      />
      <Buttons onCancel={onDone} busy={mutation.isPending}>
        <Button type="submit" disabled={mutation.isPending}>
          {t('admin.users.resetPassword')}
        </Button>
      </Buttons>
    </form>
  )
}

function DisableConfirm({ user, onDone }: { user: AdminUser; onDone: () => void }) {
  const { t } = useTranslation()
  const { setActive } = useAdminUserMutations()

  const confirm = async () => {
    try {
      await setActive.mutateAsync({ id: user.id, active: false })
      toast.success(t('admin.users.disabledDone'))
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <Buttons onCancel={onDone} busy={setActive.isPending}>
      <Button variant="destructive" onClick={() => void confirm()} disabled={setActive.isPending}>
        {t('admin.users.disable')}
      </Button>
    </Buttons>
  )
}
