import { api } from './api'
import type { Dashboard } from '@/types/dashboard'

export const dashboardApi = {
  async get(from: string, to: string): Promise<Dashboard> {
    const response = await api.get<Dashboard>('/api/dashboard', { params: { from, to } })
    return response.data
  },

  /** Shows exactly these asset accounts on the dashboard, hides the others. */
  async setAccounts(visibleAccountIds: string[]): Promise<void> {
    await api.put('/api/dashboard/accounts', { visibleAccountIds })
  },
}
