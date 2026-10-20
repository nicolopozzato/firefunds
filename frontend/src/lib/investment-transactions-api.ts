import type { AuditLogEntry } from '@/types/audit-log'
import { api } from './api'
import type {
  CreateInvestmentTransactionRequest,
  InvestmentDistributions,
  InvestmentTransaction,
} from '@/types/investment'
import type { PaginatedResponse } from '@/types/pagination'

export const investmentTransactionsApi = {
  /** `from`/`to` (Y-m-d, both included) limit the list to a period; without them every trade is listed. */
  async list(
    page = 1,
    pageSize?: number,
    accountId?: string,
    instrumentId?: string,
    from?: string,
    to?: string
  ): Promise<PaginatedResponse<InvestmentTransaction>> {
    const response = await api.get<PaginatedResponse<InvestmentTransaction>>('/api/investment-transactions', {
      params: { page, pageSize, accountId, instrumentId, from, to },
    })
    return response.data
  },

  async distributions(from?: string, to?: string): Promise<InvestmentDistributions> {
    const response = await api.get<InvestmentDistributions>('/api/investment-transactions/distributions', {
      params: { from, to },
    })
    return response.data
  },

  async get(id: string): Promise<InvestmentTransaction> {
    const response = await api.get<InvestmentTransaction>(`/api/investment-transactions/${id}`)
    return response.data
  },

  async create(data: CreateInvestmentTransactionRequest): Promise<InvestmentTransaction> {
    const response = await api.post<InvestmentTransaction>('/api/investment-transactions', data)
    return response.data
  },

  async update(id: string, data: CreateInvestmentTransactionRequest): Promise<InvestmentTransaction> {
    const response = await api.put<InvestmentTransaction>(`/api/investment-transactions/${id}`, data)
    return response.data
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/api/investment-transactions/${id}`)
  },

  async auditLog(id: string): Promise<AuditLogEntry[]> {
    const response = await api.get<AuditLogEntry[]>(`/api/investment-transactions/${id}/audit-log`)
    return response.data
  },
}
