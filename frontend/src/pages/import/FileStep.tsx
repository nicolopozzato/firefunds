import { useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { FileUp, Undo2 } from 'lucide-react'
import ConfirmDialog from '@/components/ConfirmDialog'
import FormSection from '@/components/FormSection'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { dataChanged } from '@/lib/data-changed'
import { handleApiError } from '@/lib/error-handler'
import { SPREADSHEET_EXTENSIONS } from '@/lib/excel'
import { importApi, type ImportBatch } from '@/lib/import-api'
import { cn, formatDateTime } from '@/lib/utils'

export default function FileStep({ onFile }: { onFile: (file: File | undefined) => void }) {
  return (
    <>
      <DropZone onFile={onFile} />
      <ImportHistory />
    </>
  )
}

function DropZone({ onFile }: { onFile: (file: File | undefined) => void }) {
  const { t } = useTranslation()
  const [over, setOver] = useState(false)
  const drop = (event: DragEvent) => {
    event.preventDefault()
    setOver(false)
    onFile(event.dataTransfer.files[0])
  }
  return (
    <label
      onDragOver={(event) => {
        event.preventDefault()
        setOver(true)
      }}
      onDragLeave={() => setOver(false)}
      onDrop={drop}
      className={cn(
        'surface flex cursor-pointer flex-col items-center gap-3 border-dashed px-6 py-14 text-center transition-colors',
        over ? 'border-primary bg-primary/5' : 'hover:bg-accent/40'
      )}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
        <FileUp className="h-6 w-6" />
      </span>
      <span className="font-medium">{t('csvImport.drop')}</span>
      <span className="max-w-md text-sm text-muted-foreground">{t('csvImport.dropHint')}</span>
      <input
        type="file"
        accept={['.csv', '.txt', 'text/csv', ...SPREADSHEET_EXTENSIONS.map((extension) => `.${extension}`)].join(',')}
        className="sr-only"
        onChange={(event) => onFile(event.target.files?.[0])}
      />
    </label>
  )
}

/** The latest imports, each undoable as a whole. */
function ImportHistory() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [undoing, setUndoing] = useState<ImportBatch | null>(null)
  const { data: batches } = useQuery({ queryKey: ['import-batches'], queryFn: () => importApi.batches() })
  const undo = useMutation({
    mutationFn: (batch: ImportBatch) => importApi.undo(batch.id),
    onSuccess: (result) => {
      toast.success(t('csvImport.undone', { count: result.transactions }))
      setUndoing(null)
      void queryClient.invalidateQueries({ queryKey: ['import-batches'] })
      void dataChanged(queryClient)
    },
    onError: (error) => toast.error(handleApiError(error)),
  })

  if (!batches || batches.length === 0) return null
  return (
    <FormSection title={t('csvImport.history')}>
      <ul className="divide-y text-sm">
        {batches.map((batch) => (
          <li key={batch.id} className="flex items-center gap-3 py-2.5">
            <div className="min-w-0 flex-1">
              <div className="cell-text font-medium">{batch.fileName}</div>
              <div className="text-xs text-muted-foreground">
                {formatDateTime(batch.createdAt)} · {t('csvImport.batchTransactions', { count: batch.transactions })}
                {batch.createdAccounts > 0 && <> · {t('csvImport.batchAccounts', { count: batch.createdAccounts })}</>}
              </div>
            </div>
            {batch.undoneAt ? (
              <Badge variant="secondary" className="font-normal">{t('csvImport.batchUndone')}</Badge>
            ) : (
              <Button variant="ghost" size="sm" onClick={() => setUndoing(batch)}>
                <Undo2 className="mr-1.5 h-4 w-4" />
                {t('csvImport.undo')}
              </Button>
            )}
          </li>
        ))}
      </ul>
      {undoing && <UndoDialog batch={undoing} pending={undo.isPending} onConfirm={() => undo.mutate(undoing)} onClose={() => setUndoing(null)} />}
    </FormSection>
  )
}

export function UndoDialog({ batch, pending, onConfirm, onClose }: { batch: ImportBatch; pending: boolean; onConfirm: () => void; onClose: () => void }) {
  const { t } = useTranslation()
  return (
    <ConfirmDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={t('csvImport.undoTitle', { name: batch.fileName })}
      description={t('csvImport.undoDescription', { count: batch.transactions })}
      confirmLabel={t('csvImport.undo')}
      onConfirm={onConfirm}
      pending={pending}
    />
  )
}
