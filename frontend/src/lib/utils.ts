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

/** ISO 4217 code -> its symbol ("$", "£", "¥"…): the few the app fixes, else what Intl knows, else the code itself. */
export function currencySymbolFor(code: string): string {
  const fixed = CURRENCY_SYMBOLS[code]
  if (fixed) return fixed
  try {
    const part = new Intl.NumberFormat('en', { style: 'currency', currency: code, currencyDisplay: 'narrowSymbol' })
      .formatToParts(0)
      .find((p) => p.type === 'currency')
    return part?.value ?? code
  } catch {
    return code
  }
}

/**
 * The symbol an amount gets when none is given: the user's own currency (their
 * preference), where different currencies are added up. Set by AuthContext as
 * soon as the user is loaded; the euro until then.
 */
let defaultSymbol = '€'

export function setDefaultCurrency(code: string): void {
  defaultSymbol = currencySymbolFor(code)
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
/**
 * "Hide amounts" (this device only, `hooks/use-hide-amounts.ts`): every
 * amount formatted here shows as dots, percentages and quantities as they are.
 */
let amountsHidden = false
const AMOUNT_MASK = '•••••'

export function setAmountsHidden(hidden: boolean): void {
  amountsHidden = hidden
}

export function areAmountsHidden(): boolean {
  return amountsHidden
}

/** An amount without decimals, for chart axes: "7.550 €". */
export function formatWholeMoney(amount: number, symbol: string | null | undefined): string {
  const formatted = amountsHidden ? AMOUNT_MASK : numberFormatter({ maximumFractionDigits: 0 }).format(amount)
  return symbol ? `${formatted} ${symbol}` : formatted
}

export function formatMoney(amount: number | string, symbol?: string | null): string {
  const shown = symbol === undefined ? defaultSymbol : symbol
  if (amountsHidden) return shown ? `${AMOUNT_MASK} ${shown}` : AMOUNT_MASK
  const value = typeof amount === 'string' ? Number(amount) : amount
  const formatted = numberFormatter({
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Math.abs(value))

  const sign = value < 0 ? '-' : ''
  return shown ? `${sign}${formatted} ${shown}` : `${sign}${formatted}`
}

/** An exchange rate: "0,9091", at most 8 decimals. */
export function formatExchangeRate(rate: string | number): string {
  return numberFormatter({ minimumFractionDigits: 4, maximumFractionDigits: 8 }).format(Number(rate))
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
/**
 * A date from the API as a Date showing the same day and time here. Days
 * ("2026-10-01") and transaction times ("2026-10-01T00:30:00", the clock time
 * where they happened) carry no offset and are read as local times: `new
 * Date('2026-10-01')` would take UTC midnight, the day before west of UTC.
 * Timestamps with an offset (createdAt, audit logs) are instants.
 */
export function parseDate(value: string | Date): Date {
  if (value instanceof Date) return value
  const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  return day ? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3])) : new Date(value)
}

/** The value of a `datetime-local` input for a transaction time from the API ("2026-10-01T00:30"). */
export function toDatetimeLocal(occurredAt: string): string {
  return occurredAt.slice(0, 16)
}

/** Now, as a `datetime-local` input value on this device's clock. */
export function nowAsDatetimeLocal(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`
}

/**
 * A `datetime-local` value as the API takes it: the clock time as written,
 * no offset. The zone goes alongside (deviceTimezone()).
 */
export function fromDatetimeLocal(value: string): string {
  return value.length === 16 ? `${value}:00` : value
}

/** This device's timezone (IANA): where a new transaction is being written. */
export function deviceTimezone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone
}

function dateLocale(): string {
  return i18n.language?.startsWith('it') ? 'it-IT' : 'en-US'
}

export function formatDateTime(date: string | Date): string {
  return new Intl.DateTimeFormat(dateLocale(), {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(parseDate(date))
}

/** A day as a list heading: "sabato 3 ottobre", with the year only when it is not the current one. */
export function formatDayHeading(date: string | Date): string {
  const day = parseDate(date)
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
  }).format(parseDate(date))
}
