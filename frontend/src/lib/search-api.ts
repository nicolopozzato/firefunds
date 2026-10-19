import { api } from './api'
import type { SearchSuggestions } from '@/types/search'

export const searchApi = {
  async suggestions(q: string): Promise<SearchSuggestions> {
    const response = await api.get<SearchSuggestions>('/api/search/suggestions', { params: { q } })
    return response.data
  },
}
