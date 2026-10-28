import type { AccountRef } from '@/types/transaction'

export type RecurrenceUnit = 'day' | 'week' | 'month' | 'year'
/** Read off the start date: the 8th, the last day, the second Tuesday, the last Tuesday. */
export type RecurrenceMonthDay = 'day' | 'last_day' | 'weekday' | 'last_weekday'
export type WeekendShift = 'none' | 'before' | 'after'
/** Created by itself on its day, or waiting to be confirmed (an amount that changes). */
export type RecurrenceMode = 'auto' | 'confirm'
export type RecurringKind = 'withdrawal' | 'deposit' | 'transfer'

export interface RecurringTransaction {
  id: string
  description: string
  kind: RecurringKind
  sourceAccount: AccountRef | null
  destinationAccount: AccountRef | null
  amount: string
  /** Planned changes, oldest first: from that day on, that amount. */
  amountChanges: AmountChange[]
  /** The amount of the next occurrence. */
  nextAmount: string
  currency: string
  destinationAmount: string | null
  categoryId: string | null
  categoryName: string | null
  tags: { id: string; name: string }[]
  notes: string | null
  startDate: string
  unit: RecurrenceUnit
  interval: number
  monthDay: RecurrenceMonthDay
  weekendShift: WeekendShift
  /** 1-12; null for every month. */
  months: number[] | null
  endDate: string | null
  count: number | null
  /** "HH:MM", where it happens (`timezone`). */
  time: string
  timezone: string
  mode: RecurrenceMode
  /** Entered by hand: days after its day before the user is reminded. */
  reminderDays: number
  paused: boolean
  /** The next occurrence not dealt with yet; null once it has ended. */
  nextOccurrence: string | null
  /** Mode confirm: how many are due and waiting. */
  pending: number
  /** What it moves over the next twelve months, per month. */
  monthlyAmount: string
}

export interface AmountChange {
  from: string
  amount: string
}

export interface RecurringTransactionRequest {
  description: string
  amount: string
  amountChanges: AmountChange[]
  startDate: string
  sourceAccountId: string | null
  destinationAccountId: string | null
  newSourceAccountName: string | null
  newDestinationAccountName: string | null
  destinationAmount: string | null
  categoryId: string | null
  newCategoryName: string | null
  tagIds: string[]
  newTagNames: string[]
  notes: string | null
  unit: RecurrenceUnit
  interval: number
  monthDay: RecurrenceMonthDay
  weekendShift: WeekendShift
  months: number[] | null
  endDate: string | null
  count: number | null
  time: string
  timezone?: string
  mode: RecurrenceMode
  reminderDays: number
  fromTransactionId?: string | null
}

/** A day a recurrence falls on: coming, or due and waiting for the user. */
export interface Occurrence {
  day: string
  time: string
  /** That day's amount. */
  amount: string
  recurring: RecurringTransaction
}
