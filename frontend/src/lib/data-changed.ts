import type { QueryClient } from '@tanstack/react-query'

/** Queries no change to the user's own data can affect. */
const UNAFFECTED = new Set(['currencies', 'admin'])

/**
 * What every change to the user's data calls once it is saved: a transaction,
 * a trade, an account, a category or tag, an instrument or its prices, a
 * preference. Each touches more than its own list (balances, positions, the
 * dashboard, reports, names shown on other rows), so rather than each change
 * listing what it reaches, everything is refetched: the queries on screen
 * now, the others when next shown.
 */
export function dataChanged(queryClient: QueryClient): Promise<void> {
  return queryClient.invalidateQueries({
    predicate: (query) => !UNAFFECTED.has(String(query.queryKey[0])),
  })
}
