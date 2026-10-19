import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { format } from 'date-fns'
import { searchApi } from '@/lib/search-api'
import { transactionsApi } from '@/lib/transactions-api'
import { useTimeframe } from '@/contexts/TimeframeContext'
import type { SearchKind } from '@/types/search'

/** value, once it has stopped changing for `delay` ms. */
export function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}

/** Accounts and instruments to jump to while typing in the search box. */
export function useSearchSuggestions(q: string) {
  return useQuery({
    queryKey: ['search', 'suggestions', q],
    queryFn: () => searchApi.suggestions(q),
    enabled: q.length >= 2,
    placeholderData: (previousData) => previousData,
  })
}

/** The selected timeframe as from/to, or none (every date) when not inPeriod. */
function useSearchRange(inPeriod: boolean): [string | undefined, string | undefined] {
  const { timeframe } = useTimeframe()
  if (!inPeriod) return [undefined, undefined]
  return [format(timeframe.from, 'yyyy-MM-dd'), format(timeframe.to, 'yyyy-MM-dd')]
}

export function useSearchResults(q: string, kind: SearchKind, page: number, inPeriod: boolean) {
  const [from, to] = useSearchRange(inPeriod)

  return useQuery({
    // Under 'transactions', so editing or deleting one from the results refreshes them.
    queryKey: ['transactions', 'search', q, kind, from, to, page],
    queryFn: () => transactionsApi.list(kind, from, to, page, undefined, undefined, q),
    enabled: q.trim() !== '',
    placeholderData: (previousData) => previousData,
  })
}

export function useSearchTotals(q: string, kind: SearchKind, inPeriod: boolean) {
  const [from, to] = useSearchRange(inPeriod)

  return useQuery({
    queryKey: ['transactions', 'search-totals', q, kind, from, to],
    queryFn: () => transactionsApi.totals(kind, from, to, q),
    enabled: q.trim() !== '',
    placeholderData: (previousData) => previousData,
  })
}
