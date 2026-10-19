import { api } from './api'
import type {
  Account,
  AccountDeletionPreview,
  AccountDistributions,
  AccountListFilters,
  AccountType,
  AccountValueHistoryPoint,
  BalancePoint,
  CreateAccountRequest,
  DeleteAccountOptions,
  MoveAccountTarget,
  UpdateAccountRequest,
} from '@/types/account'
import type { OpenPosition } from '@/types/investment'
import type { PaginatedResponse } from '@/types/pagination'

export const accountsApi = {
  async list(
    type: AccountType,
    page = 1,
    pageSize?: number,
    filters: AccountListFilters = {}
  ): Promise<PaginatedResponse<Account>> {
    const response = await api.get<PaginatedResponse<Account>>(`/api/accounts/${type}`, {
      params: { page, pageSize, status: filters.status, kind: filters.kind },
    })
    return response.data
  },

  async get(id: string): Promise<Account> {
    const response = await api.get<Account>(`/api/accounts/${id}`)
    return response.data
  },

  async create(type: AccountType, data: CreateAccountRequest): Promise<Account> {
    const response = await api.post<Account>(`/api/accounts/${type}`, data)
    return response.data
  },

  async update(id: string, data: UpdateAccountRequest): Promise<Account> {
    const response = await api.put<Account>(`/api/accounts/${id}`, data)
    return response.data
  },

  /** Moves the account next to another one of its type; every list of accounts follows the new order. */
  async move(id: string, target: MoveAccountTarget): Promise<void> {
    await api.put(`/api/accounts/${id}/position`, target)
  },

  async deletionPreview(id: string): Promise<AccountDeletionPreview> {
    const response = await api.get<AccountDeletionPreview>(`/api/accounts/${id}/deletion-preview`)
    return response.data
  },

  /** Marks the account as deleted; its transactions are moved or deleted as `options` says. */
  async remove(id: string, options: DeleteAccountOptions = {}): Promise<void> {
    await api.delete(`/api/accounts/${id}`, { params: options })
  },

  async balanceHistory(id: string, from?: string, to?: string): Promise<BalancePoint[]> {
    const response = await api.get<BalancePoint[]>(`/api/accounts/${id}/balance-history`, {
      params: { from, to },
    })
    return response.data
  },

  async distributions(id: string, from?: string, to?: string): Promise<AccountDistributions> {
    const response = await api.get<AccountDistributions>(`/api/accounts/${id}/distributions`, {
      params: { from, to },
    })
    return response.data
  },

  async valueHistory(id: string, from?: string, to?: string): Promise<AccountValueHistoryPoint[]> {
    const response = await api.get<AccountValueHistoryPoint[]>(`/api/accounts/${id}/value-history`, {
      params: { from, to },
    })
    return response.data
  },

  async positions(id: string, from?: string, to?: string): Promise<OpenPosition[]> {
    const response = await api.get<OpenPosition[]>(`/api/accounts/${id}/positions`, {
      params: { from, to },
    })
    return response.data
  },
}
