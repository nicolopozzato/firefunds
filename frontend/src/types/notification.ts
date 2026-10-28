/** Something the app tells the user (the bell): worded here from its type and params. */
export interface AppNotification {
  id: string
  type: 'task_failing' | 'backup_overdue' | 'prices_stale' | 'rates_stale' | 'recurring_missing' | string
  params: Record<string, string | number | null>
  createdAt: string
  updatedAt: string
  read: boolean
}
