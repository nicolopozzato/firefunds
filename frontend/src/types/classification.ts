export interface Classification {
  id: string
  name: string
  notes: string | null
  createdAt: string
  updatedAt: string
  /** Day of the latest transaction it is set on (lists only); null when none. */
  lastTransactionAt?: string | null
}

/** One month on a category's or a tag's page: what was spent and received with it. */
export interface ClassificationMonth {
  /** YYYY-MM */
  month: string
  expense: string
  income: string
}

export interface ClassificationRequest {
  name: string
  notes?: string | null
}

/** How many transactions a category or tag is set on, shown before deleting it. */
export interface ClassificationDeletionPreview {
  transactions: number
}
