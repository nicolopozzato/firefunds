import { api } from './api'
import type { IncomeExpenseReport, NetWorthReport, ReportComparison, ReportGranularity } from '@/types/report'

export const reportsApi = {
  async incomeExpenses(from: string, to: string, by: ReportGranularity, compare: ReportComparison): Promise<IncomeExpenseReport> {
    const response = await api.get<IncomeExpenseReport>('/api/reports/income-expenses', { params: { from, to, by, compare } })
    return response.data
  },

  async netWorth(year: number): Promise<NetWorthReport> {
    const response = await api.get<NetWorthReport>('/api/reports/net-worth', { params: { year } })
    return response.data
  },
}
