import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { currenciesApi } from '@/lib/currencies-api'
import type { ComboboxOption } from '@/components/Combobox'
import type { Currency } from '@/types/currency'

export function useCurrencies() {
  return useQuery({
    queryKey: ['currencies'],
    queryFn: () => currenciesApi.list(),
    staleTime: 5 * 60 * 1000,
  })
}

/** The ones people pick most, before the rest of the list. */
const COMMON = ['EUR', 'USD', 'GBP', 'CHF', 'JPY']

/** A currency's name in the interface language ("Dollaro statunitense"), the server's English one when Intl has none. */
export function currencyName(currency: Currency, language: string): string {
  try {
    return new Intl.DisplayNames([language], { type: 'currency' }).of(currency.code) ?? currency.name
  } catch {
    return currency.name
  }
}

/** "USD — Dollaro statunitense ($)" */
export function currencyLabel(currency: Currency, language: string): string {
  return `${currency.code} — ${currencyName(currency, language)} (${currency.symbol})`
}

/** Every currency as options for a Combobox, searchable by code or name. */
export function useCurrencyOptions(): ComboboxOption[] {
  const { data: currencies } = useCurrencies()
  const { i18n } = useTranslation()

  return useMemo(() => {
    const labelled = (currencies ?? []).map((currency) => ({
      value: currency.code,
      label: currencyLabel(currency, i18n.language),
    }))
    const common = COMMON.flatMap((code) => labelled.filter((option) => option.value === code))
    const rest = labelled.filter((option) => !COMMON.includes(option.value)).sort((a, b) => a.label.localeCompare(b.label, i18n.language))
    return [...common, ...rest]
  }, [currencies, i18n.language])
}
