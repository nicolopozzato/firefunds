import { useSyncExternalStore } from 'react'

/** True while the media query matches; follows the window as it is resized or rotated. */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const list = window.matchMedia(query)
      list.addEventListener('change', onChange)
      return () => list.removeEventListener('change', onChange)
    },
    () => window.matchMedia(query).matches
  )
}

/**
 * Phone layout: below Tailwind's `md` breakpoint (768px) the app has a bottom
 * navigation bar instead of the sidebar. Keep in step with the `md:` classes.
 */
export function useIsMobile(): boolean {
  return useMediaQuery('(max-width: 767px)')
}
