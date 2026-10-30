import { api } from './api'
import type { ApiToken, ApiTokenScope, Draft, PayeeHistory } from '@/types/draft'
import type { CreateTransactionRequest, Transaction } from '@/types/transaction'

export const draftsApi = {
  async list(): Promise<Draft[]> {
    return (await api.get<Draft[]>('/api/drafts')).data
  },

  /** The latest dealt with: recorded, recognised or discarded. */
  async recent(): Promise<Draft[]> {
    return (await api.get<Draft[]>('/api/drafts/recent')).data
  },

  async get(id: string): Promise<Draft> {
    return (await api.get<Draft>(`/api/drafts/${id}`)).data
  },

  /** It becomes these transactions: one, or several when split. */
  /** What the user wrote before with this payee (an expense or revenue account). */
  async payee(accountId: string): Promise<PayeeHistory> {
    return (await api.get<PayeeHistory>(`/api/drafts/payees/${accountId}`)).data
  },

  async accept(id: string, parts: CreateTransactionRequest[]): Promise<Transaction[]> {
    return (await api.post<Transaction[]>(`/api/drafts/${id}/accept`, { parts })).data
  },

  /** It is this transaction, already recorded. */
  async link(id: string, transactionId: string): Promise<void> {
    await api.post(`/api/drafts/${id}/link`, { transactionId })
  },

  async discard(id: string): Promise<void> {
    await api.post(`/api/drafts/${id}/discard`)
  },
}

export const apiTokensApi = {
  async list(): Promise<ApiToken[]> {
    return (await api.get<ApiToken[]>('/api/tokens')).data
  },

  async create(name: string, scope: ApiTokenScope): Promise<ApiToken> {
    return (await api.post<ApiToken>('/api/tokens', { name, scope })).data
  },

  async revoke(id: string): Promise<void> {
    await api.delete(`/api/tokens/${id}`)
  },
}
