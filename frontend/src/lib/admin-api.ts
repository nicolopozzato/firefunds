import { api } from './api'
import type { AdminUser, AdminUserFields, BackupSettings, CreateUserRequest, BackupsOverview, ServerSettings, SetPasswordRequest, TaskKey, TaskRun, TasksOverview, UpdateStatus } from '@/types/admin'
import type { PaginatedResponse } from '@/types/pagination'

export const adminApi = {
  async getSettings(): Promise<ServerSettings> {
    const response = await api.get<ServerSettings>('/api/admin/settings')
    return response.data
  },

  async updateSettings(data: ServerSettings): Promise<ServerSettings> {
    const response = await api.put<ServerSettings>('/api/admin/settings', data)
    return response.data
  },

  async getUpdate(): Promise<UpdateStatus> {
    const response = await api.get<UpdateStatus>('/api/admin/update')
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

  async getTasks(): Promise<TasksOverview> {
    const response = await api.get<TasksOverview>('/api/admin/tasks')
    return response.data
  },

  async getTaskRuns(key: TaskKey, page: number, pageSize: number): Promise<PaginatedResponse<TaskRun>> {
    const response = await api.get<PaginatedResponse<TaskRun>>(`/api/admin/tasks/${key}/runs`, { params: { page, pageSize } })
    return response.data
  },

  /** Runs a task while the request waits: a backup or a first price history can take a minute. */
  async runTask(key: TaskKey): Promise<TasksOverview> {
    const response = await api.post<TasksOverview>(`/api/admin/tasks/${key}/run`, null, { timeout: 10 * 60 * 1000 })
    return response.data
  },

  async listUsers(): Promise<AdminUser[]> {
    const response = await api.get<AdminUser[]>('/api/admin/users')
    return response.data
  },

  async createUser(data: CreateUserRequest): Promise<AdminUser> {
    const response = await api.post<AdminUser>('/api/admin/users', data)
    return response.data
  },

  async updateUser(id: string, data: AdminUserFields): Promise<AdminUser> {
    const response = await api.put<AdminUser>(`/api/admin/users/${id}`, data)
    return response.data
  },

  async setUserPassword(id: string, data: SetPasswordRequest): Promise<AdminUser> {
    const response = await api.put<AdminUser>(`/api/admin/users/${id}/password`, data)
    return response.data
  },

  async setUserActive(id: string, active: boolean): Promise<AdminUser> {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/${active ? 'enable' : 'disable'}`)
    return response.data
  },
}
