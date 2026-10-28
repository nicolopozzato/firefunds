import type { AuditLogEntry } from '@/types/audit-log'
import { api } from './api'
import type {
  CreateTransactionRequest,
  DateRange,
  Distributions,
  Transaction,
  TransactionKind,
  TransactionListKind,
} from '@/types/transaction'
import type { PaginatedResponse } from '@/types/pagination'
import type { TransactionTotals } from '@/types/search'

export const transactionsApi = {
  async list(
    kind: TransactionListKind,
    from?: string,
    to?: string,
    page = 1,
    pageSize?: number,
    accountId?: string,
    q?: string,
  ): Promise<PaginatedResponse<Transaction>> {
    const response = await api.get<PaginatedResponse<Transaction>>('/api/transactions', {
      params: { kind, from, to, page, pageSize, accountId, q },
    })
    return response.data
  },

  /** What a recurring transaction created, newest first, every date. */
  async listByRecurring(recurringId: string, page = 1): Promise<PaginatedResponse<Transaction>> {
    const response = await api.get<PaginatedResponse<Transaction>>('/api/transactions', {
      params: { kind: 'cash', recurringId, page },
    })
    return response.data
  },

  /** One category's withdrawals or deposits (`none`: those without a category) in a range, e.g. a report's cell. */
  async listByCategory(
    kind: 'withdrawal' | 'deposit',
    categoryId: string,
    from: string,
    to: string,
    page = 1,
  ): Promise<PaginatedResponse<Transaction>> {
    const response = await api.get<PaginatedResponse<Transaction>>('/api/transactions', {
      params: { kind, categoryId, from, to, page },
    })
    return response.data
  },

  /** Past transactions whose description contains the text: the latest of each description. */
  async suggestions(q: string): Promise<Transaction[]> {
    const response = await api.get<Transaction[]>('/api/transactions/suggestions', { params: { q } })
    return response.data
  },

  /** Every money transaction (any kind) with one category or one tag, in a range: a category's or a tag's page. */
  async listByClassification(
    filter: { categoryId: string } | { tagId: string },
    from: string,
    to: string,
    page = 1,
  ): Promise<PaginatedResponse<Transaction>> {
    const response = await api.get<PaginatedResponse<Transaction>>('/api/transactions', {
      params: { kind: 'cash', ...filter, from, to, page },
    })
    return response.data
  },

  /** What the list with the same filters adds up to, per kind, over every page. */
  async totals(kind: TransactionListKind, from?: string, to?: string, q?: string): Promise<TransactionTotals> {
    const response = await api.get<TransactionTotals>('/api/transactions/totals', { params: { kind, from, to, q } })
    return response.data
  },

  async dateRange(): Promise<DateRange> {
    const response = await api.get<DateRange>('/api/transactions/date-range')
    return response.data
  },

  async distributions(kind: TransactionKind, from?: string, to?: string): Promise<Distributions> {
    const response = await api.get<Distributions>('/api/transactions/distributions', { params: { kind, from, to } })
    return response.data
  },

  async get(id: string): Promise<Transaction> {
    const response = await api.get<Transaction>(`/api/transactions/${id}`)
    return response.data
  },

  async create(data: CreateTransactionRequest): Promise<Transaction> {
    const response = await api.post<Transaction>('/api/transactions', data)
    return response.data
  },

  async update(id: string, data: CreateTransactionRequest): Promise<Transaction> {
    const response = await api.put<Transaction>(`/api/transactions/${id}`, data)
    return response.data
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/api/transactions/${id}`)
  },

  /** One change to several money transactions at once: set or clear the category, add a tag, delete. */
  async bulk(request: { ids: string[]; action: 'category' | 'tag' | 'delete'; categoryId?: string | null; tagId?: string }): Promise<number> {
    const response = await api.post<{ count: number }>('/api/transactions/bulk', request)
    return response.data.count
  },

  /** Undo a delete made moments ago. */
  async restore(id: string): Promise<Transaction> {
    const response = await api.post<Transaction>(`/api/transactions/${id}/restore`)
    return response.data
  },

  async auditLog(id: string): Promise<AuditLogEntry[]> {
    const response = await api.get<AuditLogEntry[]>(`/api/transactions/${id}/audit-log`)
    return response.data
  },
}
