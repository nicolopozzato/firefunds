import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'
import i18n from '@/i18n/config'
import type { NumberFormat } from '@/types/auth'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

const CURRENCY_SYMBOLS: Record<string, string> = {
  EUR: '€',
  USD: '$',
  GBP: '£',
  CHF: 'CHF',
}

/** Best-effort ISO 4217 code -> symbol, falling back to the code itself. */
export function currencySymbolFor(code: string): string {
  return CURRENCY_SYMBOLS[code] ?? code
}

/**
 * Digit grouping and decimal separator for every number the app renders, from
 * the user's "number format" preference. Set by AuthContext as soon as the user
 * is loaded, before any page renders; Italian until then.
 */
let numberLocale: NumberFormat = 'it-IT'

export function setNumberFormat(format: NumberFormat): void {
  numberLocale = format
}

/**
 * Number formatter for `format` (the user's by default). Grouping is forced
 * because some locales (Italian among them) skip it for 4-digit numbers,
 * which would render 1234,56 next to 12.345,67.
 */
export function numberFormatter(options: Intl.NumberFormatOptions = {}, format: NumberFormat = numberLocale): Intl.NumberFormat {
  return new Intl.NumberFormat(format, { useGrouping: 'always', ...options })
}

/** An IBAN in groups of four characters, as printed on bank documents: "IT60 X054 2811 …". */
export function formatIban(iban: string): string {
  return iban.replace(/\s+/g, '').toUpperCase().replace(/(.{4})(?=.)/g, '$1 ')
}

/**
 * The single money format for the whole app: "1.234,56 €", "-1.234,56 €" when
 * negative (separators follow the user's number format). Every place that
 * renders a monetary amount should go through this.
 */
export function formatMoney(amount: number | string, symbol: string | null = '€'): string {
  const value = typeof amount === 'string' ? Number(amount) : amount
  const formatted = numberFormatter({
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Math.abs(value))

  const sign = value < 0 ? '-' : ''
  return symbol ? `${sign}${formatted} ${symbol}` : `${sign}${formatted}`
}

/** A traded quantity (e.g. ETF units): up to 6 decimals, trailing zeros dropped. */
export function formatQuantity(quantity: number | string): string {
  return numberFormatter({ maximumFractionDigits: 6 }).format(Number(quantity))
}

/** A percentage with a fixed number of decimals: "12,34%" / "12.34%". */
export function formatPercent(value: number | string, decimals: number = 2): string {
  return `${numberFormatter({
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(Number(value))}%`
}

/** Text color class for a monetary amount: green if positive, red if negative, default ink at zero. */
export function moneyColorClass(amount: number | string): string {
  const value = typeof amount === 'string' ? Number(amount) : amount
  if (value > 0) return 'text-positive'
  if (value < 0) return 'text-negative'
  return ''
}

interface AccountBalanceLike {
  name: string
  currentBalance: string
  currencySymbol: string | null
}

export function formatAccountBalance(account: AccountBalanceLike): string {
  return `${account.name} - ${formatMoney(account.currentBalance, account.currencySymbol)}`
}

/**
 * The account's total value: for an investment account, free cash plus the
 * current market value of everything held; for any other account, just its
 * cash balance (investedValue is always null there).
 */
export function totalAccountValue(account: { currentBalance: string; investedValue: string | null }): string {
  if (account.investedValue === null) return account.currentBalance
  return (Number(account.currentBalance) + Number(account.investedValue)).toFixed(4)
}

/** Dates spell out month names, so they follow the interface language, not the number format. */
function dateLocale(): string {
  return i18n.language?.startsWith('it') ? 'it-IT' : 'en-US'
}

export function formatDateTime(date: string | Date): string {
  return new Intl.DateTimeFormat(dateLocale(), {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(new Date(date))
}

/** A day as a list heading: "sabato 3 ottobre", with the year only when it is not the current one. */
export function formatDayHeading(date: string | Date): string {
  const day = new Date(date)
  return new Intl.DateTimeFormat(dateLocale(), {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: day.getFullYear() === new Date().getFullYear() ? undefined : 'numeric',
  }).format(day)
}

export function formatTime24h(date: string | Date): string {
  return new Intl.DateTimeFormat(dateLocale(), {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(date))
}
