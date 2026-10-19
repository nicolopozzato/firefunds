import type { QueryClient } from '@tanstack/react-query'

/**
 * True when the server has a newer build than the one this page runs: the
 * entry script in index.html changes name with every `npm run build`.
 */
export async function isNewBuildAvailable(): Promise<boolean> {
  if (import.meta.env.DEV) return false

  const running = document.querySelector<HTMLScriptElement>('script[type="module"][src]')?.src
  try {
    const response = await fetch('/', { cache: 'no-store' })
    if (!response.ok) return false
    const page = new DOMParser().parseFromString(await response.text(), 'text/html')
    const latest = page.querySelector('script[type="module"][src]')?.getAttribute('src')
    return !!running && !!latest && new URL(latest, window.location.origin).href !== running
  } catch {
    return false
  }
}

/**
 * What a manual refresh does: loads the new build if there is one, otherwise
 * refetches the data on screen and marks the rest stale, so other pages
 * refetch when visited. Takes at least half a second, so the spinner is seen.
 */
export async function refreshApp(queryClient: QueryClient): Promise<void> {
  if (await isNewBuildAvailable()) {
    window.location.reload()
    // The page is going away: keep spinning until it does.
    return new Promise<void>(() => {})
  }

  await Promise.all([
    queryClient.invalidateQueries(),
    new Promise((resolve) => setTimeout(resolve, 500)),
  ])
}
