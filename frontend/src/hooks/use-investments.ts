import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { investmentInstrumentsApi, investmentPortfolioApi } from '@/lib/investments-api'
import type { InvestmentInstrumentRequest } from '@/types/investment'
import { dataChanged } from '@/lib/data-changed'

export function useInvestmentInstruments(page = 1) {
  return useQuery({
    queryKey: ['investment-instruments', page],
    queryFn: () => investmentInstrumentsApi.list(page),
    placeholderData: (previousData) => previousData,
  })
}

/**
 * Every investment account at once. Keyed under 'accounts': whatever changes a
 * balance (and so the net worth) already invalidates that key.
 */
export function useInvestmentPortfolio(from?: string, to?: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['accounts', 'portfolio', from, to],
    queryFn: () => investmentPortfolioApi.get(from, to),
  })
}

/** For selection UI (comboboxes) that must offer every instrument, not just one page. */
export function useAllInvestmentInstruments() {
  return useQuery({
    queryKey: ['investment-instruments', 'all'],
    queryFn: () => investmentInstrumentsApi.list(1, -1),
  })
}

export function useInvestmentInstrument(id: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['investment-instruments', 'detail', id],
    queryFn: () => investmentInstrumentsApi.get(id),
    enabled: !!id,
  })
}

export function useInvestmentInstrumentPrices(id: string, from?: string, to?: string) {
  return useQuery({
    queryKey: ['investment-instruments', 'prices', id, from, to],
    queryFn: () => investmentInstrumentsApi.prices(id, from, to),
    enabled: !!id,
  })
}

/** Until the first answer says otherwise (refreshSeconds). */
const DEFAULT_LIVE_PRICE_POLL_SECONDS = 30

export function useInvestmentInstrumentLivePrice(id: string) {
  return useQuery({
    // Polled: a failed poll just keeps the last close on screen.
    meta: { quietError: true },
    queryKey: ['investment-instruments', 'live-price', id],
    queryFn: () => investmentInstrumentsApi.livePrice(id),
    enabled: !!id,
    refetchInterval: (query) => (query.state.data?.refreshSeconds ?? DEFAULT_LIVE_PRICE_POLL_SECONDS) * 1000,
    refetchIntervalInBackground: false,
  })
}

export function useCreateInvestmentInstrument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: InvestmentInstrumentRequest) => investmentInstrumentsApi.create(data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useUpdateInvestmentInstrument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: InvestmentInstrumentRequest }) =>
      investmentInstrumentsApi.update(id, data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useInstrumentDeletionPreview(id: string | null) {
  return useQuery({
    queryKey: ['investment-instruments', 'deletion-preview', id],
    queryFn: () => investmentInstrumentsApi.deletionPreview(id as string),
    enabled: id !== null,
    staleTime: 0,
  })
}

export function useDeleteInvestmentInstrument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, deleteTransactions }: { id: string; deleteTransactions: boolean }) =>
      investmentInstrumentsApi.remove(id, deleteTransactions),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useImportInvestmentPrices(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ from, to }: { from: string; to: string }) =>
      investmentInstrumentsApi.importPrices(id, from, to),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

/** The user's own prices of an instrument: set (new or correcting the imported one), restore, remove. */
export function useManualPrices(id: string) {
  const queryClient = useQueryClient()
  const onSuccess = () => void dataChanged(queryClient)

  return {
    set: useMutation({
      mutationFn: ({ date, price }: { date: string; price: string }) => investmentInstrumentsApi.setPrice(id, date, price),
      onSuccess,
    }),
    restore: useMutation({ mutationFn: (date: string) => investmentInstrumentsApi.restorePrice(id, date), onSuccess }),
    remove: useMutation({ mutationFn: (date: string) => investmentInstrumentsApi.removePrice(id, date), onSuccess }),
  }
}
