import { api } from './api'
import type { Occurrence, RecurringTransaction, RecurringTransactionRequest } from '@/types/recurring'
import type { Transaction } from '@/types/transaction'
import type { AuditLogEntry } from '@/types/audit-log'

export const recurringApi = {
  async list(): Promise<RecurringTransaction[]> {
    const response = await api.get<RecurringTransaction[]>('/api/recurring-transactions')
    return response.data
  },

  async get(id: string): Promise<RecurringTransaction> {
    const response = await api.get<RecurringTransaction>(`/api/recurring-transactions/${id}`)
    return response.data
  },

  /** What falls over the next `days`, every recurrence together, by day. */
  async upcoming(days: number): Promise<Occurrence[]> {
    const response = await api.get<Occurrence[]>('/api/recurring-transactions/upcoming', { params: { days } })
    return response.data
  },

  /** Due and waiting: the first of each recurrence that asks to be confirmed. */
  async pending(): Promise<Occurrence[]> {
    const response = await api.get<Occurrence[]>('/api/recurring-transactions/pending')
    return response.data
  },

  async create(data: RecurringTransactionRequest): Promise<RecurringTransaction> {
    const response = await api.post<RecurringTransaction>('/api/recurring-transactions', data)
    return response.data
  },

  async update(id: string, data: RecurringTransactionRequest): Promise<RecurringTransaction> {
    const response = await api.put<RecurringTransaction>(`/api/recurring-transactions/${id}`, data)
    return response.data
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/api/recurring-transactions/${id}`)
  },

  async setPaused(id: string, paused: boolean): Promise<RecurringTransaction> {
    const response = await api.post<RecurringTransaction>(`/api/recurring-transactions/${id}/${paused ? 'pause' : 'resume'}`)
    return response.data
  },

  /** The next waiting occurrence, with the amount paid and its day when they differ. */
  async confirm(id: string, data: { amount?: string; day?: string }): Promise<Transaction> {
    const response = await api.post<Transaction>(`/api/recurring-transactions/${id}/confirm`, data)
    return response.data
  },

  async auditLog(id: string): Promise<AuditLogEntry[]> {
    const response = await api.get<AuditLogEntry[]>(`/api/recurring-transactions/${id}/audit-log`)
    return response.data
  },

  /** Transactions that could be the first waiting occurrence. */
  async linkable(id: string): Promise<Transaction[]> {
    const response = await api.get<Transaction[]>(`/api/recurring-transactions/${id}/linkable`)
    return response.data
  },

  /** "I entered it already": that transaction is the first waiting occurrence. */
  async link(id: string, transactionId: string): Promise<Transaction> {
    const response = await api.post<Transaction>(`/api/recurring-transactions/${id}/link`, { transactionId })
    return response.data
  },

  async skip(id: string): Promise<RecurringTransaction> {
    const response = await api.post<RecurringTransaction>(`/api/recurring-transactions/${id}/skip`)
    return response.data
  },
}
