import { useQuery } from '@tanstack/react-query'
import { reportsApi } from '@/lib/reports-api'
import type { ReportComparison, ReportGranularity } from '@/types/report'

export function useIncomeExpenseReport(from: string | null, to: string | null, by: ReportGranularity, compare: ReportComparison) {
  return useQuery({
    queryKey: ['reports', 'income-expenses', from, to, by, compare],
    queryFn: () => reportsApi.incomeExpenses(from!, to!, by, compare),
    enabled: from !== null && to !== null,
    placeholderData: (previousData) => previousData,
  })
}

export function useNetWorthReport(year: number) {
  return useQuery({
    queryKey: ['reports', 'net-worth', year],
    queryFn: () => reportsApi.netWorth(year),
    placeholderData: (previousData) => previousData,
  })
}
