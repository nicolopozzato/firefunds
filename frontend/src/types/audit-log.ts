/** One field a save changed. */
export interface AuditLogChange {
  field: string
  oldValue: unknown
  newValue: unknown
}

/** One entry of a record's history (a transaction, a trade, an account): newest first from the API. */
export interface AuditLogEntry {
  id: string
  action: 'created' | 'updated' | 'deleted' | 'restored'
  changes: AuditLogChange[] | null
  createdAt: string
}
