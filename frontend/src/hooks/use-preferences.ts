import { useAuth } from '@/contexts/AuthContext'
import { authApi } from '@/lib/auth-api'
import type { DashboardSection, UpdatePreferencesRequest } from '@/types/auth'

/** The dashboard's sections in their default order (User::DASHBOARD_SECTIONS). */
export const DEFAULT_DASHBOARD_SECTIONS: DashboardSection[] = [
  { key: 'summary', visible: true },
  { key: 'trend', visible: true },
  { key: 'expensesByCategory', visible: true },
  { key: 'expenseAccounts', visible: true },
  { key: 'revenueAccounts', visible: true },
  { key: 'accounts', visible: true },
]

/** Saves some preferences (the others stay as they are), then reloads the user. */
export function useSavePreferences() {
  const { refreshUser } = useAuth()

  return async (changes: UpdatePreferencesRequest) => {
    await authApi.updatePreferences(changes)
    await refreshUser()
  }
}
