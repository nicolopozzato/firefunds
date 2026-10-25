import { useSyncExternalStore } from 'react'
import { areAmountsHidden, setAmountsHidden } from '@/lib/utils'

/**
 * "Hide amounts": on this device only (in public on the phone, not at home
 * on the computer), remembered across starts. The formatters read the flag
 * (`lib/utils.ts`); the page is drawn again when it changes (Layout).
 */
const STORAGE_KEY = 'firefunds-hide-amounts'
const listeners = new Set<() => void>()

try {
  setAmountsHidden(localStorage.getItem(STORAGE_KEY) === '1')
} catch {
  // Shown: nothing remembered.
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function setHideAmounts(hidden: boolean): void {
  setAmountsHidden(hidden)
  try {
    localStorage.setItem(STORAGE_KEY, hidden ? '1' : '0')
  } catch {
    // Not remembered for the next start: it still applies now.
  }
  listeners.forEach((listener) => listener())
}

export function useHideAmounts(): [boolean, (hidden: boolean) => void] {
  return [useSyncExternalStore(subscribe, areAmountsHidden), setHideAmounts]
}
