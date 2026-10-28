import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { authApi } from '@/lib/auth-api'
import { handleApiError } from '@/lib/error-handler'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * Shown instead of the app while the password is one an administrator set
 * to be changed (`User.mustChangePassword`): the server answers nothing else
 * until then. The current password isn't asked, the user just typed it.
 */
export default function ChooseNewPassword() {
  const { t } = useTranslation()
  const { user, logout, refreshUser } = useAuth()
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    if (newPassword !== confirmPassword) {
      setError(t('profile.passwordMismatch'))
      return
    }

    setSaving(true)
    try {
      await authApi.changePassword({ newPassword })
      // Every session was revoked, this one included: sign straight back in.
      await authApi.login({ email: user?.email ?? '', password: newPassword })
      await refreshUser()
    } catch (err) {
      setError(handleApiError(err))
      setSaving(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md shadow-lg">
        <CardHeader className="space-y-1">
          <CardTitle className="page-title text-center">{t('auth.newPasswordTitle')}</CardTitle>
          <CardDescription className="text-center">{t('auth.newPasswordDescription')}</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && (
              <div className="p-3 text-sm text-negative bg-negative/10 border border-negative/30 rounded-md">{error}</div>
            )}

            {/* For password managers: whose password this is. */}
            <input type="email" autoComplete="username" value={user?.email ?? ''} readOnly hidden />

            <div className="space-y-2">
              <Label htmlFor="newPassword">{t('profile.newPassword')}</Label>
              <Input
                id="newPassword"
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                autoFocus
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="confirmPassword">{t('profile.confirmNewPassword')}</Label>
              <Input
                id="confirmPassword"
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
              />
            </div>

            <Button type="submit" className="w-full" disabled={saving}>
              {saving ? t('common.loading') : t('auth.newPasswordSubmit')}
            </Button>

            <div className="text-center text-sm">
              <button type="button" className="text-muted-foreground underline hover:text-foreground" onClick={() => void logout()}>
                {t('auth.logout')}
              </button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
