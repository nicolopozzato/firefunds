import { useCallback, useEffect, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { PaginatedResponse } from '@/types/pagination'

/**
 * A list's page and "every date" switch, kept in the URL (`?page=`, `?all=1`)
 * so going back from a row returns to them. Going back to page 1 whenever
 * `resetOn` changes (the period, a filter), but not when the list is opened
 * again with the same values.
 */
export function useListPage(resetOn: unknown[] = []) {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const showAll = searchParams.get('all') === '1'

  const update = useCallback(
    (changes: Record<string, string | null>) =>
      setSearchParams(
        (current) => {
          const next = new URLSearchParams(current)
          for (const [key, value] of Object.entries(changes)) {
            if (value === null) next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        { replace: true }
      ),
    [setSearchParams]
  )

  const setPage = useCallback((value: number) => update({ page: value > 1 ? String(value) : null }), [update])
  const setShowAll = useCallback((value: boolean) => update({ all: value ? '1' : null, page: null }), [update])

  const resetKey = JSON.stringify(resetOn)
  const lastResetKey = useRef(resetKey)
  useEffect(() => {
    if (lastResetKey.current === resetKey) return
    lastResetKey.current = resetKey
    update({ page: null })
  }, [resetKey, update])

  return { page, setPage, showAll, setShowAll }
}

/**
 * Back to the last page when the one shown has nothing left (its only row
 * deleted, a shorter list): otherwise it reads "nothing here", with no
 * pagination to leave it. `data` only once it is this page's, not a placeholder.
 */
export function useKeepPageInRange(page: number, setPage: (page: number) => void, data: PaginatedResponse<unknown> | undefined) {
  useEffect(() => {
    if (!data || data.pageSize <= 0) return
    const lastPage = Math.max(1, Math.ceil(data.total / data.pageSize))
    if (page > lastPage) setPage(lastPage)
  }, [data, page, setPage])
}
