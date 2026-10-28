import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Trash2, X } from 'lucide-react'
import Combobox from '@/components/Combobox'
import ConfirmDialog from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/button'
import { useAllClassification } from '@/hooks/use-classification'
import { useBulkTransactions } from '@/hooks/use-transactions'
import { handleApiError } from '@/lib/error-handler'

const NO_CATEGORY = '__none__'

interface TransactionBulkBarProps {
  ids: string[]
  onDone: () => void
  /** Phones: a panel over the bottom bar instead of a strip over the table. */
  floating?: boolean
}

/**
 * Over a list with rows selected: what to do to all of them at once (set or
 * take off the category, add a tag, delete), as Firefly does.
 */
export default function TransactionBulkBar({ ids, onDone, floating }: TransactionBulkBarProps) {
  const { t } = useTranslation()
  const { data: categories } = useAllClassification('categories')
  const { data: tags } = useAllClassification('tags')
  const bulk = useBulkTransactions()
  const [confirmDelete, setConfirmDelete] = useState(false)

  const run = async (request: Parameters<typeof bulk.mutateAsync>[0]) => {
    try {
      const count = await bulk.mutateAsync(request)
      toast.success(t(request.action === 'delete' ? 'bulk.deleted' : 'bulk.updated', { count }))
      setConfirmDelete(false)
      onDone()
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const categoryPicker = (
    <Combobox
      options={[
        { value: NO_CATEGORY, label: t('bulk.noCategory') },
        ...(categories?.items ?? []).map((category) => ({ value: category.id, label: category.name })),
      ]}
      value=""
      onChange={(value) => value && void run({ ids, action: 'category', categoryId: value === NO_CATEGORY ? null : value })}
      placeholder={t(floating ? 'transactionForm.category' : 'bulk.setCategory')}
    />
  )
  const tagPicker = (
    <Combobox
      options={(tags?.items ?? []).map((tag) => ({ value: tag.id, label: tag.name }))}
      value=""
      onChange={(value) => value && void run({ ids, action: 'tag', tagId: value })}
      placeholder={t(floating ? 'transactionForm.tags' : 'bulk.addTag')}
    />
  )
  const clear = (
    <Button variant="ghost" size="icon" className="ml-auto" aria-label={t('bulk.clear')} title={t('bulk.clear')} onClick={onDone}>
      <X className="h-4 w-4" />
    </Button>
  )
  const confirm = (
    <ConfirmDialog
      open={confirmDelete}
      onOpenChange={setConfirmDelete}
      title={t('bulk.deleteTitle', { count: ids.length })}
      description={t('bulk.deleteDescription')}
      onConfirm={() => void run({ ids, action: 'delete' })}
      pending={bulk.isPending}
    />
  )

  if (floating) {
    return (
      <div className="surface fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 space-y-2 p-3 shadow-lg animate-in fade-in-0 slide-in-from-bottom-2">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium tabular-nums">{t('bulk.selected', { count: ids.length })}</span>
          {clear}
        </div>
        <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-2">
          {categoryPicker}
          {tagPicker}
          <Button variant="outline" size="icon" className="text-destructive hover:text-destructive" aria-label={t('common.delete')} onClick={() => setConfirmDelete(true)}>
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
        {confirm}
      </div>
    )
  }

  return (
    <div className="flex flex-wrap items-center gap-2 border-b bg-accent/40 px-4 py-2 animate-in fade-in-0 slide-in-from-top-1">
      <span className="mr-2 text-sm font-medium tabular-nums">{t('bulk.selected', { count: ids.length })}</span>
      <div className="w-52">{categoryPicker}</div>
      <div className="w-52">{tagPicker}</div>
      <Button variant="outline" className="text-destructive hover:text-destructive" onClick={() => setConfirmDelete(true)}>
        <Trash2 className="mr-2 h-4 w-4" />
        {t('common.delete')}
      </Button>
      {clear}
      {confirm}
    </div>
  )
}
