import type { TFunction } from 'i18next'
import { currencySymbolFor, formatMoney, formatShortDate } from '@/lib/utils'
import type { AppNotification } from '@/types/notification'

/** A notification in words, and the page that deals with it. */
export function describeNotification(notification: AppNotification, t: TFunction): { title: string; body: string; link: string } {
  const p = notification.params
  switch (notification.type) {
    case 'recurring_missing':
      return {
        title: t('notifications.recurringMissing', { description: p.description }),
        body: t('notifications.recurringMissingBody', { day: formatShortDate(String(p.day)) }),
        link: `/transactions/recurring/${p.recurringId}`,
      }
    case 'draft_new':
      return {
        title: t('notifications.draftNew', { amount: formatMoney(String(p.amount), currencySymbolFor(String(p.currency ?? ''))), description: p.description ?? '' }),
        body: t(p.duplicate ? 'notifications.draftNewDuplicate' : 'notifications.draftNewBody'),
        link: `/transactions/drafts/${p.draftId}`,
      }
    case 'draft_recorded':
      return {
        title: t('notifications.draftRecorded', { description: p.description }),
        body: t('notifications.draftRecordedBody', { amount: formatMoney(String(p.amount), currencySymbolFor(String(p.currency ?? ''))) }),
        link: `/transactions/${p.transactionId}`,
      }
    case 'task_failing':
      return {
        title: t('notifications.taskFailing', { task: t(`admin.tasks.task_${p.task}`) }),
        body: t('notifications.taskFailingBody'),
        link: '/admin/tasks',
      }
    case 'backup_overdue':
      return {
        title: t('notifications.backupOverdue'),
        body: p.since ? t('notifications.backupOverdueBody', { date: formatShortDate(String(p.since)) }) : '',
        link: '/admin/backups',
      }
    case 'prices_stale':
      return { title: t('notifications.pricesStale', { count: Number(p.count) }), body: String(p.symbols ?? ''), link: '/admin/tasks' }
    case 'rates_stale':
      return { title: t('notifications.ratesStale', { count: Number(p.count) }), body: String(p.currencies ?? ''), link: '/admin/tasks' }
    default:
      return { title: notification.type, body: '', link: '/' }
  }
}
