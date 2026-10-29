import { api } from './api'
import type { CsvSettings } from './csv'
import type { CreateTransactionRequest } from '@/types/transaction'

export interface ImportPreset {
  id: string
  name: string
  accountId: string | null
  settings: CsvSettings
  headers: string[]
  updatedAt: string
}

export interface ImportRowIn {
  date: string
  amount: string
  description: string
  counterparty: string
}

/** What the server thinks a row is. */
export interface ImportRowCheck {
  index: number
  duplicate: { id: string; description: string | null; occurredAt: string } | null
  recurring: { id: string; description: string; day: string; accountId: string; categoryId: string | null } | null
  suggestion: { account: { id: string; name: string; type: 'asset' | 'expense' | 'revenue' } | null; categoryId: string | null } | null
}

export const importApi = {
  async presets(): Promise<ImportPreset[]> {
    return (await api.get<ImportPreset[]>('/api/import/presets')).data
  },

  async savePreset(preset: Omit<ImportPreset, 'id' | 'updatedAt'>, id?: string): Promise<ImportPreset> {
    return id ? (await api.put<ImportPreset>(`/api/import/presets/${id}`, preset)).data : (await api.post<ImportPreset>('/api/import/presets', preset)).data
  },

  async deletePreset(id: string): Promise<void> {
    await api.delete(`/api/import/presets/${id}`)
  },

  async preview(accountId: string, rows: ImportRowIn[]): Promise<ImportRowCheck[]> {
    return (await api.post<ImportRowCheck[]>('/api/import/preview', { accountId, rows })).data
  },

  async commit(rows: { transaction: CreateTransactionRequest; recurringId?: string; recurringDay?: string }[]): Promise<number> {
    return (await api.post<{ imported: number }>('/api/import/commit', { rows })).data.imported
  },
}
