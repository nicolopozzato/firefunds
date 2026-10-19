import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminApi } from '@/lib/admin-api'
import type { CurrencyRequest, ServerSettings } from '@/types/admin'

export function useServerSettings() {
  return useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: () => adminApi.getSettings(),
  })
}

export function useUpdateServerSettings() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: ServerSettings) => adminApi.updateSettings(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] })
    },
  })
}

export function useAdminUsers() {
  return useQuery({
    queryKey: ['admin', 'users'],
    queryFn: () => adminApi.listUsers(),
  })
}

export function useAdminCurrencies() {
  return useQuery({
    queryKey: ['admin', 'currencies'],
    queryFn: () => adminApi.listCurrencies(),
  })
}

export function useCreateAdminCurrency() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CurrencyRequest) => adminApi.createCurrency(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'currencies'] })
      queryClient.invalidateQueries({ queryKey: ['currencies'] })
    },
  })
}

export function useUpdateAdminCurrency() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: CurrencyRequest }) => adminApi.updateCurrency(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'currencies'] })
      queryClient.invalidateQueries({ queryKey: ['currencies'] })
    },
  })
}

export function useDeleteAdminCurrency() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => adminApi.removeCurrency(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'currencies'] })
      queryClient.invalidateQueries({ queryKey: ['currencies'] })
    },
  })
}
