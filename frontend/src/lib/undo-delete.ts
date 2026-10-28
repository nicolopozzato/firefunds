import { toast } from 'sonner'
import type { QueryClient } from '@tanstack/react-query'
import i18n from '@/i18n/config'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'

/**
 * After a transaction or a trade is deleted: says so, with "Undo" for a few
 * seconds (the backend accepts it for 15 minutes, RestoreWindow). Deletes are
 * soft, so undoing brings back the very same record, history included.
 */
export function offerUndo(queryClient: QueryClient, restore: () => Promise<unknown>): void {
  toast(i18n.t('undo.deleted'), {
    duration: 8000,
    action: {
      label: i18n.t('undo.undo'),
      onClick: () => {
        restore()
          .then(() => {
            void dataChanged(queryClient)
            toast.success(i18n.t('undo.restored'))
          })
          .catch((error) => toast.error(handleApiError(error)))
      },
    },
  })
}
