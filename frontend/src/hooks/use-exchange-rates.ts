import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { dataChanged } from '@/lib/data-changed'
import { exchangeRatesApi } from '@/lib/exchange-rates-api'

/** The currencies the user has rates for, with their latest rate. Empty for someone with a single currency. */
export function useExchangeRatesOverview() {
  return useQuery({
    queryKey: ['exchange-rates'],
    queryFn: () => exchangeRatesApi.overview(),
    staleTime: 5 * 60 * 1000,
  })
}

export function useExchangeRateDays(currency: string | undefined, from: string, to: string) {
  return useQuery({
    queryKey: ['exchange-rates', currency, from, to],
    queryFn: () => exchangeRatesApi.days(currency ?? '', from, to),
    enabled: !!currency,
  })
}

/** The user's own rates: setting one or taking it away changes every figure that converts, so everything is refetched. */
export function useExchangeRateEdits(currency: string) {
  const queryClient = useQueryClient()
  const onSuccess = () => void dataChanged(queryClient)

  return {
    set: useMutation({
      mutationFn: ({ date, rate }: { date: string; rate: string }) => exchangeRatesApi.set(currency, date, rate),
      onSuccess,
    }),
    revert: useMutation({ mutationFn: (date: string) => exchangeRatesApi.revert(currency, date), onSuccess }),
  }
}
