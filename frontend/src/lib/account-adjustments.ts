import type { AccountAdjustment } from '@/types/account'

/** A row of the account form's other balance changes (AccountAdjustmentsField): `key` tells rows apart before they have an id. */
export interface AdjustmentRow {
  key: string
  id?: string
  date: string
  amount: string
  description: string
}

let nextKey = 0

export function adjustmentRows(adjustments: AccountAdjustment[] = []): AdjustmentRow[] {
  return adjustments.map((adjustment) => ({
    key: adjustment.id,
    id: adjustment.id,
    date: adjustment.date,
    amount: adjustment.amount,
    description: adjustment.description ?? '',
  }))
}

export function isIncomplete(row: AdjustmentRow): boolean {
  return row.date === '' || row.amount === ''
}

export function newAdjustmentRow(): AdjustmentRow {
  return { key: `new-${nextKey++}`, date: '', amount: '', description: '' }
}
