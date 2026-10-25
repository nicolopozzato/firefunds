import type { NetWorthRow } from '@/types/report'

/** A row's twelve values of the year and of the year before, as numbers (null = month still to come). */
export interface MonthlyValues {
  values: (number | null)[]
  previousValues: (number | null)[]
}

const toNumbers = (values: (string | null)[]) => values.map((value) => (value === null ? null : Number(value)))

export function monthlyValues(row: NetWorthRow): MonthlyValues {
  return { values: toNumbers(row.values), previousValues: toNumbers(row.previousValues) }
}

/** Month by month sums of several rows; a month is null when every row is. */
export function sumRows(rows: NetWorthRow[]): MonthlyValues & { previousOpening: number | null } {
  const sum = (pick: (row: NetWorthRow) => (string | null)[]) =>
    Array.from({ length: 12 }, (_, index) => {
      const present = rows.map((row) => pick(row)[index]).filter((value): value is string => value !== null)
      return present.length === 0 ? null : present.reduce((total, value) => total + Number(value), 0)
    })
  const openings = rows.map((row) => row.previousOpening).filter((value): value is string => value !== null)

  return {
    values: sum((row) => row.values),
    previousValues: sum((row) => row.previousValues),
    previousOpening: openings.length === 0 ? null : openings.reduce((total, value) => total + Number(value), 0),
  }
}

/**
 * The change of each month from the one before, for both years: January
 * from December of the year before (and the year before's January from the
 * December before it). `percent` gives it as a percentage of the month before.
 */
export function monthlyChanges(row: MonthlyValues & { previousOpening: number | null }, percent = false): MonthlyValues {
  const change = (current: number | null, before: number | null) => {
    if (current === null || before === null) return null
    if (!percent) return current - before
    return before === 0 ? null : ((current - before) / Math.abs(before)) * 100
  }

  return {
    values: row.values.map((value, index) => change(value, index === 0 ? row.previousValues[11] : row.values[index - 1])),
    previousValues: row.previousValues.map((value, index) => change(value, index === 0 ? row.previousOpening : row.previousValues[index - 1])),
  }
}

export function withOpening(row: NetWorthRow): MonthlyValues & { previousOpening: number | null } {
  return { ...monthlyValues(row), previousOpening: row.previousOpening === null ? null : Number(row.previousOpening) }
}

/** total − each part month by month (null where any is): what the change leaves once saving, the market and the rates are taken out. */
export function remainder(total: MonthlyValues, ...parts: MonthlyValues[]): MonthlyValues {
  const rest = (a: (number | null)[], others: (number | null)[][]) =>
    a.map((value, index) =>
      value === null || others.some((other) => other[index] === null) ? null : others.reduce((left, other) => left - (other[index] as number), value),
    )
  return {
    values: rest(total.values, parts.map((part) => part.values)),
    previousValues: rest(total.previousValues, parts.map((part) => part.previousValues)),
  }
}
