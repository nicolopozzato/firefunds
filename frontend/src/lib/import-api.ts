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
  /** The user's account the row is on; left out for one the import creates. */
  accountId?: string
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

export interface CommitImportBody {
  fileName: string
  accounts: { key: string; name: string; currencyCode: string; openingBalance?: string; openingBalanceDate?: string }[]
  rows: { transaction: CreateTransactionRequest; sourceAccountKey?: string; destinationAccountKey?: string; recurringId?: string; recurringDay?: string }[]
  adjustments: { accountId?: string; accountKey?: string; date: string; amount: string; description: string | null }[]
}

/** One import, to see what it recorded or undo it. */
export interface ImportBatch {
  id: string
  fileName: string
  createdAt: string
  transactions: number
  adjustments: number
  createdAccounts: number
  createdCategories: number
  createdTags: number
  undoneAt: string | null
}

export interface UndoneImport {
  transactions: number
  adjustments: number
  accounts: number
  categories: number
  tags: number
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

  /** The checks of every row, in order (in one request: a transaction is the duplicate of one row only). */
  async preview(rows: ImportRowIn[]): Promise<ImportRowCheck[]> {
    return (await api.post<ImportRowCheck[]>('/api/import/preview', { rows })).data
  },

  async commit(body: CommitImportBody): Promise<ImportBatch> {
    return (await api.post<ImportBatch>('/api/import/commit', body)).data
  },

  async batches(): Promise<ImportBatch[]> {
    return (await api.get<ImportBatch[]>('/api/import/batches')).data
  },

  async undo(id: string): Promise<UndoneImport> {
    return (await api.post<UndoneImport>(`/api/import/batches/${id}/undo`)).data
  },
}
