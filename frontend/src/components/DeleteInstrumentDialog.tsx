import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import ConfirmDialog from '@/components/ConfirmDialog'
import { useDeleteInvestmentInstrument, useInstrumentDeletionPreview } from '@/hooks/use-investments'
import { handleApiError } from '@/lib/error-handler'
import type { InvestmentInstrument } from '@/types/investment'

interface DeleteInstrumentDialogProps {
  /** The instrument to delete; the dialog is open while this is set. */
  instrument: InvestmentInstrument | null
  onClose: () => void
  onDeleted?: () => void
}

/**
 * Confirms deleting an instrument. If it still has investment transactions,
 * says how many (and how many positions are still open) and that they will
 * be deleted with it; its recurring purchases (PAC) go with it too.
 */
export default function DeleteInstrumentDialog({ instrument, onClose, onDeleted }: DeleteInstrumentDialogProps) {
  const { t } = useTranslation()
  const { data: preview, isLoading } = useInstrumentDeletionPreview(instrument?.id ?? null)
  const deleteInstrument = useDeleteInvestmentInstrument()

  const inUse = !!preview && preview.investmentTransactions > 0
  const hasPlans = !!preview && preview.recurring > 0

  const handleConfirm = async () => {
    if (!instrument) return
    try {
      await deleteInstrument.mutateAsync({ id: instrument.id, deleteTransactions: inUse })
      onClose()
      onDeleted?.()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <ConfirmDialog
      open={!!instrument}
      onOpenChange={(open) => !open && onClose()}
      title={t('investmentInstruments.deleteConfirmTitle')}
      description={t('investmentInstruments.deleteConfirmDescription', { name: instrument?.name })}
      onConfirm={handleConfirm}
      pending={isLoading || !preview || deleteInstrument.isPending}
    >
      {(inUse || hasPlans) && preview && (
        <div className="space-y-2 text-sm">
          <ul className="list-disc space-y-1 pl-5">
            {inUse && <li>{t('accountsCommon.deleteInvestmentTransactionsCount', { count: preview.investmentTransactions })}</li>}
            {preview.openPositions > 0 && <li>{t('accountsCommon.deleteOpenPositionsCount', { count: preview.openPositions })}</li>}
            {hasPlans && <li>{t('accountsCommon.deleteRecurringCount', { count: preview.recurring })}</li>}
          </ul>
          {inUse && <p className="text-muted-foreground">{t('investmentInstruments.deleteInUseWarning')}</p>}
        </div>
      )}
    </ConfirmDialog>
  )
}
