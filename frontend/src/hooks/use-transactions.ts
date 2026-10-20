import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { transactionsApi } from '@/lib/transactions-api'
import { useTimeframe } from '@/contexts/TimeframeContext'
import type { CreateTransactionRequest, TransactionKind, TransactionListKind } from '@/types/transaction'
import { dataChanged } from '@/lib/data-changed'

export function useTransactions(kind: TransactionListKind, page = 1, showAll = false, accountId?: string) {
  const { timeframe } = useTimeframe()
  const from = showAll ? undefined : format(timeframe.from, 'yyyy-MM-dd')
  const to = showAll ? undefined : format(timeframe.to, 'yyyy-MM-dd')

  return useQuery({
    queryKey: ['transactions', kind, from, to, page, accountId],
    queryFn: () => transactionsApi.list(kind, from, to, page, undefined, accountId),
    placeholderData: (previousData) => previousData,
  })
}

/** A report cell's transactions: one category (or `none`) in one range; nothing while `filter` is null. */
export function useCategoryTransactions(
  filter: { kind: 'withdrawal' | 'deposit'; categoryId: string; from: string; to: string } | null,
  page: number,
) {
  return useQuery({
    queryKey: ['transactions', 'by-category', filter, page],
    queryFn: () => transactionsApi.listByCategory(filter!.kind, filter!.categoryId, filter!.from, filter!.to, page),
    enabled: filter !== null,
    // The previous page stays on screen while the next loads, but never
    // another cell's transactions: those are not this list.
    placeholderData: (previousData, previousQuery) =>
      JSON.stringify(previousQuery?.queryKey[2]) === JSON.stringify(filter) ? previousData : undefined,
  })
}

export function useTransactionDistributions(kind: TransactionKind, enabled = true) {
  const { timeframe } = useTimeframe()
  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')

  return useQuery({
    queryKey: ['transactions', 'distributions', kind, from, to],
    queryFn: () => transactionsApi.distributions(kind, from, to),
    enabled,
  })
}

export function useTransaction(id: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['transactions', 'detail', id],
    queryFn: () => transactionsApi.get(id),
    enabled: !!id,
  })
}

export function useTransactionAuditLog(id: string) {
  return useQuery({
    queryKey: ['transactions', 'audit-log', id],
    queryFn: () => transactionsApi.auditLog(id),
    enabled: !!id,
  })
}

export function useCreateTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateTransactionRequest) => transactionsApi.create(data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useUpdateTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: CreateTransactionRequest }) =>
      transactionsApi.update(id, data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useDeleteTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => transactionsApi.remove(id),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}
