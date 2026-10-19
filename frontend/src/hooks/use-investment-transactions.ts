import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { investmentTransactionsApi } from '@/lib/investment-transactions-api'
import { useTimeframe } from '@/contexts/TimeframeContext'
import type { CreateInvestmentTransactionRequest } from '@/types/investment'

/** The trades in the selected timeframe, or all of them with `showAll`. */
export function useInvestmentTransactions(page = 1, showAll = false, accountId?: string, instrumentId?: string) {
  const { timeframe } = useTimeframe()
  const from = showAll ? undefined : format(timeframe.from, 'yyyy-MM-dd')
  const to = showAll ? undefined : format(timeframe.to, 'yyyy-MM-dd')

  return useQuery({
    queryKey: ['investment-transactions', from, to, page, accountId, instrumentId],
    queryFn: () => investmentTransactionsApi.list(page, undefined, accountId, instrumentId, from, to),
    placeholderData: (previousData) => previousData,
  })
}

export function useInvestmentTransactionDistributions(enabled = true) {
  const { timeframe } = useTimeframe()
  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')

  return useQuery({
    queryKey: ['investment-transactions', 'distributions', from, to],
    queryFn: () => investmentTransactionsApi.distributions(from, to),
    enabled,
  })
}

export function useInvestmentTransaction(id: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['investment-transactions', 'detail', id],
    queryFn: () => investmentTransactionsApi.get(id),
    enabled: !!id,
  })
}

export function useInvestmentTransactionAuditLog(id: string) {
  return useQuery({
    queryKey: ['investment-transactions', 'audit-log', id],
    queryFn: () => investmentTransactionsApi.auditLog(id),
    enabled: !!id,
  })
}

export function useCreateInvestmentTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateInvestmentTransactionRequest) => investmentTransactionsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-transactions'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useUpdateInvestmentTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: CreateInvestmentTransactionRequest }) =>
      investmentTransactionsApi.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-transactions'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useDeleteInvestmentTransaction() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => investmentTransactionsApi.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-transactions'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}
