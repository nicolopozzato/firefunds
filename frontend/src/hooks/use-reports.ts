import { useQuery } from '@tanstack/react-query'
import { reportsApi } from '@/lib/reports-api'
import type { CashFlowOptions, ReportComparison, ReportGranularity } from '@/types/report'

export function useIncomeExpenseReport(from: string | null, to: string | null, by: ReportGranularity, compare: ReportComparison) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['reports', 'income-expenses', from, to, by, compare],
    queryFn: () => reportsApi.incomeExpenses(from!, to!, by, compare),
    enabled: from !== null && to !== null,
    placeholderData: (previousData) => previousData,
  })
}

export function useNetWorthReport(year: number) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['reports', 'net-worth', year],
    queryFn: () => reportsApi.netWorth(year),
    placeholderData: (previousData) => previousData,
  })
}

export function useCashFlowReport(options: CashFlowOptions) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['reports', 'cash-flow', options],
    queryFn: () => reportsApi.cashFlow(options),
    placeholderData: (previousData) => previousData,
  })
}
