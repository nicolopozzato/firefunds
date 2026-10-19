import { useEffect, useRef } from 'react'

/**
 * Fills an edit form from server data once per `key` (the record being
 * edited), not on every refetch of it: data refetched when the app comes back
 * from the background, or by pull to refresh, may have changed on the server
 * (e.g. an investment account's balance after a price fetch) and must not
 * overwrite what is being typed. `null` while there is nothing to fill from.
 */
export function useFormFill(key: string | null, fill: () => void): void {
  const filledFor = useRef<string | null>(null)

  // No dependency list: `fill` is a fresh closure every render, `key` decides.
  useEffect(() => {
    if (key === null || filledFor.current === key) return
    filledFor.current = key
    fill()
  })
}
