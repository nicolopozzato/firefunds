import { useEffect, useSyncExternalStore } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import {
  applyThemePreference,
  getThemeMode,
  getThemePreference,
  onSystemModeChange,
  subscribeThemeMode,
  type Density,
} from '@/lib/theme'

/** The mode on screen (light or dark), re-rendering when it changes. */
export function useThemeMode() {
  return useSyncExternalStore(subscribeThemeMode, getThemeMode)
}

/**
 * Applies the user's theme preference and density
 * (Preferences) once known; signed out (login page), the last ones used on
 * this device. "system" follows the device live, e.g. when the phone
 * switches to dark at sunset.
 */
export function useApplyThemePreference(): void {
  const { user } = useAuth()
  const preference = user?.theme ?? getThemePreference()
  const density = user?.density as Density | undefined

  useEffect(() => {
    applyThemePreference(preference, { density })
    if (preference !== 'system') return
    return onSystemModeChange(() => applyThemePreference('system'))
  }, [preference, density])
}
