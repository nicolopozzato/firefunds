import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { investmentInstrumentsApi, investmentPortfolioApi } from '@/lib/investments-api'
import type { InvestmentInstrumentRequest } from '@/types/investment'

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

const LIVE_PRICE_POLL_INTERVAL_MS = 30_000

export function useInvestmentInstrumentLivePrice(id: string) {
  return useQuery({
    queryKey: ['investment-instruments', 'live-price', id],
    queryFn: () => investmentInstrumentsApi.livePrice(id),
    enabled: !!id,
    refetchInterval: LIVE_PRICE_POLL_INTERVAL_MS,
    refetchIntervalInBackground: false,
  })
}

export function useCreateInvestmentInstrument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: InvestmentInstrumentRequest) => investmentInstrumentsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-instruments'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useUpdateInvestmentInstrument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: InvestmentInstrumentRequest }) =>
      investmentInstrumentsApi.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-instruments'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
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
      queryClient.invalidateQueries({ queryKey: ['investment-instruments'] })
      queryClient.invalidateQueries({ queryKey: ['investment-transactions'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useImportInvestmentPrices(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ from, to }: { from: string; to: string }) =>
      investmentInstrumentsApi.importPrices(id, from, to),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['investment-instruments', 'prices', id] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}
