import { useQuery } from '@tanstack/react-query'
import { currenciesApi } from '@/lib/currencies-api'

export function useCurrencies() {
  return useQuery({
    queryKey: ['currencies'],
    queryFn: () => currenciesApi.listEnabled(),
    staleTime: 5 * 60 * 1000,
  })
}
