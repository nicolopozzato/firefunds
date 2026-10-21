import { api } from './api'
import type { AdminCurrency, AdminUser, BackupSettings, BackupsOverview, CurrencyRequest, ServerSettings } from '@/types/admin'

export const adminApi = {
  async getSettings(): Promise<ServerSettings> {
    const response = await api.get<ServerSettings>('/api/admin/settings')
    return response.data
  },

  async updateSettings(data: ServerSettings): Promise<ServerSettings> {
    const response = await api.put<ServerSettings>('/api/admin/settings', data)
    return response.data
  },

  async getBackups(): Promise<BackupsOverview> {
    const response = await api.get<BackupsOverview>('/api/admin/backups')
    return response.data
  },

  async updateBackupSettings(data: BackupSettings): Promise<BackupsOverview> {
    const response = await api.put<BackupsOverview>('/api/admin/backups/settings', data)
    return response.data
  },

  async createBackup(): Promise<BackupsOverview> {
    const response = await api.post<BackupsOverview>('/api/admin/backups')
    return response.data
  },

  /** Saves a backup through the browser: the request carries the login, so no plain link. */
  async downloadBackup(name: string): Promise<void> {
    const response = await api.get<Blob>(`/api/admin/backups/${encodeURIComponent(name)}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    link.click()
    URL.revokeObjectURL(url)
  },

  async listUsers(): Promise<AdminUser[]> {
    const response = await api.get<AdminUser[]>('/api/admin/users')
    return response.data
  },

  async listCurrencies(): Promise<AdminCurrency[]> {
    const response = await api.get<AdminCurrency[]>('/api/admin/currencies')
    return response.data
  },

  async createCurrency(data: CurrencyRequest): Promise<AdminCurrency> {
    const response = await api.post<AdminCurrency>('/api/admin/currencies', data)
    return response.data
  },

  async updateCurrency(id: string, data: CurrencyRequest): Promise<AdminCurrency> {
    const response = await api.put<AdminCurrency>(`/api/admin/currencies/${id}`, data)
    return response.data
  },

  async removeCurrency(id: string): Promise<void> {
    await api.delete(`/api/admin/currencies/${id}`)
  },
}
