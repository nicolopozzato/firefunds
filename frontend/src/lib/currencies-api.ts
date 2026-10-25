import { api } from './api'
import type { Currency } from '@/types/currency'

export const currenciesApi = {
  async list(): Promise<Currency[]> {
    const response = await api.get<Currency[]>('/api/currencies')
    return response.data
  },
}
