import { api } from './api'
import type {
  InstrumentDeletionPreview,
  InvestmentInstrument,
  InvestmentInstrumentRequest,
  InvestmentLivePrice,
  InvestmentPrice,
  Portfolio,
} from '@/types/investment'
import type { PaginatedResponse } from '@/types/pagination'

export const investmentInstrumentsApi = {
  async list(page = 1, pageSize?: number): Promise<PaginatedResponse<InvestmentInstrument>> {
    const response = await api.get<PaginatedResponse<InvestmentInstrument>>('/api/investment-instruments', {
      params: { page, pageSize },
    })
    return response.data
  },

  async get(id: string): Promise<InvestmentInstrument> {
    const response = await api.get<InvestmentInstrument>(`/api/investment-instruments/${id}`)
    return response.data
  },

  async create(data: InvestmentInstrumentRequest): Promise<InvestmentInstrument> {
    const response = await api.post<InvestmentInstrument>('/api/investment-instruments', data)
    return response.data
  },

  async update(id: string, data: InvestmentInstrumentRequest): Promise<InvestmentInstrument> {
    const response = await api.put<InvestmentInstrument>(`/api/investment-instruments/${id}`, data)
    return response.data
  },

  async deletionPreview(id: string): Promise<InstrumentDeletionPreview> {
    const response = await api.get<InstrumentDeletionPreview>(`/api/investment-instruments/${id}/deletion-preview`)
    return response.data
  },

  /** Marks the instrument as deleted; `deleteTransactions` confirms deleting its investment transactions too. */
  async remove(id: string, deleteTransactions = false): Promise<void> {
    await api.delete(`/api/investment-instruments/${id}`, { params: deleteTransactions ? { deleteTransactions: 1 } : {} })
  },

  async prices(id: string, from?: string, to?: string): Promise<InvestmentPrice[]> {
    const response = await api.get<InvestmentPrice[]>(`/api/investment-instruments/${id}/prices`, {
      params: { from, to },
    })
    return response.data
  },

  async livePrice(id: string): Promise<InvestmentLivePrice> {
    const response = await api.get<InvestmentLivePrice>(`/api/investment-instruments/${id}/live-price`)
    return response.data
  },

  /** The user's price for a day: a new one, or a correction of the imported one (one per day). */
  async setPrice(id: string, date: string, price: string): Promise<InvestmentPrice> {
    const response = await api.put<InvestmentPrice>(`/api/investment-instruments/${id}/prices/${date}`, { price })
    return response.data
  },

  /** Back to the imported value of a day the user corrected. */
  async restorePrice(id: string, date: string): Promise<InvestmentPrice> {
    const response = await api.post<InvestmentPrice>(`/api/investment-instruments/${id}/prices/${date}/restore`)
    return response.data
  },

  /** Removes a day only the user entered. */
  async removePrice(id: string, date: string): Promise<void> {
    await api.delete(`/api/investment-instruments/${id}/prices/${date}`)
  },

  async importPrices(id: string, from: string, to: string): Promise<void> {
    await api.post(`/api/investment-instruments/${id}/prices/import`, { from, to })
  },
}

export const investmentPortfolioApi = {
  async get(from?: string, to?: string): Promise<Portfolio> {
    const response = await api.get<Portfolio>('/api/investments/portfolio', { params: { from, to } })
    return response.data
  },
}
