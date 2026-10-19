/**
 * Phone navigation: the bottom bar's tabs, which tab a page belongs to, and
 * where the top bar's back arrow leads when there is no history to go back to.
 */

export type MobileTab = 'home' | 'transactions' | 'investments' | 'more'

export const TAB_ROOT: Record<MobileTab, string> = {
  home: '/dashboard',
  transactions: '/transactions',
  investments: '/investments/overview',
  more: '/more',
}

/** Pages reached straight from a tab (its own chips included): they show no back arrow. */
const TAB_PAGES = new Set([
  '/dashboard',
  '/transactions',
  '/transactions/withdrawal',
  '/transactions/deposit',
  '/transactions/transfers',
  '/transactions/investments',
  '/investments/overview',
  '/investments/instruments',
  '/more',
])

/** Lists opened from the "More" page: going back from them returns there. */
const MORE_PAGES = [
  '/accounts/asset',
  '/accounts/expense',
  '/accounts/revenue',
  '/categories',
  '/tags',
  '/reports/income-expenses',
  '/reports/net-worth',
  '/profile',
  '/preferences',
  '/admin/users',
  '/admin/settings',
  '/admin/currencies',
]

/** Pages whose figures follow the selected period: only there the top bar shows it. */
const PERIOD_PAGES = [
  /^\/dashboard$/,
  /^\/transactions(\/(withdrawal|deposit|transfers|investments))?$/,
  /^\/accounts\/(asset|expense|revenue)\/[^/]+$/,
  /^\/investments\/overview$/,
  /^\/search$/,
]

export function showsPeriod(pathname: string): boolean {
  // "/accounts/asset/new" looks like an account page: forms never show the period.
  return !pathname.endsWith('/new') && PERIOD_PAGES.some((pattern) => pattern.test(pathname))
}

export function tabOf(pathname: string): MobileTab {
  if (pathname.startsWith('/transactions') || pathname.startsWith('/search')) return 'transactions'
  if (pathname.startsWith('/investments')) return 'investments'
  if (pathname === '/' || pathname.startsWith('/dashboard')) return 'home'
  return 'more'
}

/** New and edit forms: full screen, their Save bar takes the bottom bar's place. */
export function isFormPage(pathname: string): boolean {
  return pathname.endsWith('/new') || pathname.endsWith('/edit')
}

export function isTabPage(pathname: string): boolean {
  return TAB_PAGES.has(pathname)
}

/** The page one level up: a form goes back to what it edits, a detail page to its list. */
export function parentPath(pathname: string): string {
  if (MORE_PAGES.includes(pathname)) return TAB_ROOT.more
  if (pathname.startsWith('/search')) return TAB_ROOT.transactions
  const segments = pathname.split('/').filter(Boolean)
  segments.pop()
  return segments.length > 0 ? `/${segments.join('/')}` : TAB_ROOT.home
}
