import { api } from './api'
import type { ExchangeRate, ExchangeRatesOverview } from '@/types/exchange-rate'

export const exchangeRatesApi = {
  async overview(): Promise<ExchangeRatesOverview> {
    const response = await api.get<ExchangeRatesOverview>('/api/exchange-rates')
    return response.data
  },

  async days(currency: string, from: string, to: string): Promise<ExchangeRate[]> {
    const response = await api.get<ExchangeRate[]>(`/api/exchange-rates/${currency}`, { params: { from, to } })
    return response.data
  },

  async set(currency: string, date: string, rate: string): Promise<ExchangeRate> {
    const response = await api.put<ExchangeRate>(`/api/exchange-rates/${currency}/${date}`, { rate })
    return response.data
  },

  async revert(currency: string, date: string): Promise<void> {
    await api.delete(`/api/exchange-rates/${currency}/${date}`)
  },
}
