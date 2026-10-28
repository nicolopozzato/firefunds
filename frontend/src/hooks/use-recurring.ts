import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { recurringApi } from '@/lib/recurring-api'
import { transactionsApi } from '@/lib/transactions-api'
import { dataChanged } from '@/lib/data-changed'
import type { RecurringTransactionRequest } from '@/types/recurring'

export function useRecurringTransactions() {
  return useQuery({ queryKey: ['recurring', 'list'], queryFn: () => recurringApi.list() })
}

export function useRecurringTransaction(id: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['recurring', 'detail', id],
    queryFn: () => recurringApi.get(id),
    enabled: !!id,
  })
}

export function useUpcomingOccurrences(days = 30) {
  return useQuery({ queryKey: ['recurring', 'upcoming', days], queryFn: () => recurringApi.upcoming(days) })
}

export function usePendingOccurrences() {
  return useQuery({ queryKey: ['recurring', 'pending'], queryFn: () => recurringApi.pending() })
}

export function useRecurringAuditLog(id: string) {
  return useQuery({ queryKey: ['recurring', 'audit-log', id], queryFn: () => recurringApi.auditLog(id), enabled: !!id })
}

/** What a recurrence created, newest first. */
export function useRecurringTransactionHistory(id: string, page: number) {
  return useQuery({
    queryKey: ['transactions', 'by-recurring', id, page],
    queryFn: () => transactionsApi.listByRecurring(id, page),
    enabled: !!id,
    placeholderData: (previousData) => previousData,
  })
}

/** Every change here can create or move transactions: everything is refetched (dataChanged). */
function useRecurringMutation<T, R>(mutationFn: (variables: T) => Promise<R>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useSaveRecurring() {
  return useRecurringMutation(({ id, data }: { id?: string; data: RecurringTransactionRequest }) =>
    id ? recurringApi.update(id, data) : recurringApi.create(data)
  )
}

export function useDeleteRecurring() {
  return useRecurringMutation((id: string) => recurringApi.remove(id))
}

export function usePauseRecurring() {
  return useRecurringMutation(({ id, paused }: { id: string; paused: boolean }) => recurringApi.setPaused(id, paused))
}

export function useConfirmOccurrence() {
  return useRecurringMutation(({ id, amount, day }: { id: string; amount?: string; day?: string }) => recurringApi.confirm(id, { amount, day }))
}

export function useLinkableTransactions(id: string, enabled: boolean) {
  return useQuery({ queryKey: ['recurring', 'linkable', id], queryFn: () => recurringApi.linkable(id), enabled })
}

export function useLinkOccurrence() {
  return useRecurringMutation(({ id, transactionId }: { id: string; transactionId: string }) => recurringApi.link(id, transactionId))
}

export function useSkipOccurrence() {
  return useRecurringMutation((id: string) => recurringApi.skip(id))
}
