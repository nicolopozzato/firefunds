import { api } from './api'
import type { AppNotification } from '@/types/notification'

export const notificationsApi = {
  async list(): Promise<AppNotification[]> {
    const response = await api.get<AppNotification[]>('/api/notifications')
    return response.data
  },

  async readAll(): Promise<void> {
    await api.post('/api/notifications/read')
  },

  async read(id: string): Promise<void> {
    await api.post(`/api/notifications/${id}/read`)
  },

  /** Hidden until its cause is gone and comes back. */
  async dismiss(id: string): Promise<void> {
    await api.post(`/api/notifications/${id}/dismiss`)
  },
}
