import { api } from './api'
import type { CashFlowOptions, CashFlowReport, IncomeExpenseReport, NetWorthReport, ReportComparison, ReportGranularity } from '@/types/report'

export const reportsApi = {
  async incomeExpenses(from: string, to: string, by: ReportGranularity, compare: ReportComparison): Promise<IncomeExpenseReport> {
    const response = await api.get<IncomeExpenseReport>('/api/reports/income-expenses', { params: { from, to, by, compare } })
    return response.data
  },

  async netWorth(year: number): Promise<NetWorthReport> {
    const response = await api.get<NetWorthReport>('/api/reports/net-worth', { params: { year } })
    return response.data
  },

  async cashFlow({ layers, ...options }: CashFlowOptions): Promise<CashFlowReport> {
    const response = await api.get<CashFlowReport>('/api/reports/cash-flow', { params: { ...options, layers: layers.join(',') } })
    return response.data
  },
}
