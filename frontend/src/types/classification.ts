export interface Classification {
  id: string
  name: string
  notes: string | null
  createdAt: string
  updatedAt: string
}

export interface ClassificationRequest {
  name: string
  notes?: string | null
}

/** How many transactions a category or tag is set on, shown before deleting it. */
export interface ClassificationDeletionPreview {
  transactions: number
}
