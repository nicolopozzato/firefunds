import { api } from './api'
import type { Classification, ClassificationDeletionPreview, ClassificationMonth, ClassificationRequest } from '@/types/classification'
import type { PaginatedResponse } from '@/types/pagination'

export type ClassificationResource = 'categories' | 'tags'

export function createClassificationApi(resource: ClassificationResource) {
  return {
    async list(page = 1, pageSize?: number): Promise<PaginatedResponse<Classification>> {
      const response = await api.get<PaginatedResponse<Classification>>(`/api/${resource}`, {
        params: { page, pageSize },
      })
      return response.data
    },

    async get(id: string): Promise<Classification> {
      const response = await api.get<Classification>(`/api/${resource}/${id}`)
      return response.data
    },

    /** What was spent and received with it, month by month over a range (days, YYYY-MM-DD). */
    async months(id: string, from: string, to: string): Promise<ClassificationMonth[]> {
      const response = await api.get<ClassificationMonth[]>(`/api/${resource}/${id}/months`, { params: { from, to } })
      return response.data
    },

    async create(data: ClassificationRequest): Promise<Classification> {
      const response = await api.post<Classification>(`/api/${resource}`, data)
      return response.data
    },

    async update(id: string, data: ClassificationRequest): Promise<Classification> {
      const response = await api.put<Classification>(`/api/${resource}/${id}`, data)
      return response.data
    },

    async deletionPreview(id: string): Promise<ClassificationDeletionPreview> {
      const response = await api.get<ClassificationDeletionPreview>(`/api/${resource}/${id}/deletion-preview`)
      return response.data
    },

    async remove(id: string): Promise<void> {
      await api.delete(`/api/${resource}/${id}`)
    },
  }
}

