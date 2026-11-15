import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckCircle2, Undo2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { importApi, type ImportBatch } from '@/lib/import-api'
import { UndoDialog } from './FileStep'

/** What the import recorded, where to see it, and undoing it in one go. */
export default function DoneStep({ batch, onAnother }: { batch: ImportBatch; onAnother: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)
  const undo = useMutation({
    mutationFn: () => importApi.undo(batch.id),
    onSuccess: (result) => {
      toast.success(t('csvImport.undone', { count: result.transactions }))
      void queryClient.invalidateQueries({ queryKey: ['import-batches'] })
      void dataChanged(queryClient)
      onAnother()
    },
    onError: (error) => toast.error(handleApiError(error)),
  })

  const created = [
    batch.createdAccounts > 0 && t('csvImport.createdAccounts', { count: batch.createdAccounts }),
    batch.createdCategories > 0 && t('csvImport.createdCategories', { count: batch.createdCategories }),
    batch.createdTags > 0 && t('csvImport.createdTags', { count: batch.createdTags }),
  ].filter(Boolean)

  return (
    <div className="surface flex flex-col items-center gap-3 px-6 py-12 text-center">
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-positive/10 text-positive">
        <CheckCircle2 className="h-6 w-6" />
      </span>
      <h2 className="section-title">{t('csvImport.imported', { count: batch.transactions })}</h2>
      {(created.length > 0 || batch.adjustments > 0) && (
        <p className="max-w-md text-sm text-muted-foreground">
          {[...created, batch.adjustments > 0 && t('csvImport.createdAdjustments', { count: batch.adjustments })].filter(Boolean).join(' · ')}
        </p>
      )}
      <p className="max-w-md text-sm text-muted-foreground">{t('csvImport.undoLater')}</p>
      <div className="mt-2 flex flex-wrap justify-center gap-2">
        <Button onClick={() => navigate('/transactions')}>{t('csvImport.seeTransactions')}</Button>
        <Button variant="outline" onClick={onAnother}>{t('csvImport.another')}</Button>
        <Button variant="ghost" onClick={() => setConfirming(true)}>
          <Undo2 className="mr-1.5 h-4 w-4" />
          {t('csvImport.undo')}
        </Button>
      </div>
      {confirming && <UndoDialog batch={batch} pending={undo.isPending} onConfirm={() => undo.mutate()} onClose={() => setConfirming(false)} />}
    </div>
  )
}
