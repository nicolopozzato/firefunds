import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MoreHorizontal, Pencil, Trash2, Plus } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import Pagination from '@/components/Pagination'
import TableSkeleton from '@/components/TableSkeleton'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useClassificationDeletionPreview, useClassificationList, useDeleteClassification } from '@/hooks/use-classification'
import { handleApiError } from '@/lib/error-handler'
import type { ClassificationResource } from '@/lib/classification-api'
import type { Classification } from '@/types/classification'
import EmptyValue from '@/components/EmptyValue'
import DateWithTime from '@/components/DateWithTime'
import { MobileListCard, MobileListSkeleton } from '@/components/MobileList'
import { useIsMobile } from '@/hooks/use-media-query'
import { useKeepPageInRange, useListPage } from '@/hooks/use-list-page'
import EmptyState from '@/components/EmptyState'
import ConfirmDialog from '@/components/ConfirmDialog'

interface ClassificationTableProps {
  resource: ClassificationResource
  basePath: string
  title: string
  description: string
  emptyTitle: string
  emptyDescription: string
}

export default function ClassificationTable({
  resource,
  basePath,
  title,
  description,
  emptyTitle,
  emptyDescription,
}: ClassificationTableProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const isMobile = useIsMobile()
  const { page, setPage } = useListPage()
  const { data, isLoading, isPlaceholderData } = useClassificationList(resource, page)
  useKeepPageInRange(page, setPage, isPlaceholderData ? undefined : data)
  const items = data?.items
  const deleteItem = useDeleteClassification(resource)
  const [itemToDelete, setItemToDelete] = useState<Classification | null>(null)
  const { data: deletionPreview, isLoading: isPreviewLoading } = useClassificationDeletionPreview(resource, itemToDelete?.id ?? null)

  const handleConfirmDelete = async () => {
    if (!itemToDelete) return
    try {
      await deleteItem.mutateAsync(itemToDelete.id)
      setItemToDelete(null)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  const actionsMenu = (item: Classification) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t('common.moreActions')}>
          <MoreHorizontal className="h-4 w-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => navigate(`${basePath}/${item.id}/edit`)}>
          <Pencil className="mr-2 h-4 w-4" />
          {t('common.edit')}
        </DropdownMenuItem>
        <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setItemToDelete(item)}>
          <Trash2 className="mr-2 h-4 w-4" />
          {t('common.delete')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={title}
        description={description}
        actions={
          // Phones add a category from the bottom bar's "+" (tags aren't in it).
          <Button
            onClick={() => navigate(`${basePath}/new`)}
            className={resource === 'categories' ? 'hidden md:inline-flex' : undefined}
          >
            <Plus className="mr-2 h-4 w-4" />
            {t('classificationCommon.add')}
          </Button>
        }
      />

      {isMobile ? (
        isLoading ? (
          <MobileListSkeleton />
        ) : !items || items.length === 0 ? (
          <MobileListCard>
            <EmptyState title={emptyTitle} description={emptyDescription} action={{ label: t('classificationCommon.add'), onClick: () => navigate(`${basePath}/new`) }} />
          </MobileListCard>
        ) : (
          <div className="space-y-4">
            <MobileListCard>
              {items.map((item) => (
                <div key={item.id} className="flex items-center gap-2 py-1 pl-4 pr-2">
                  <button type="button" className="min-w-0 flex-1 py-2 text-left" onClick={() => navigate(`${basePath}/${item.id}`)}>
                    <span className="block truncate font-medium">{item.name}</span>
                    {item.notes && <span className="block truncate text-xs text-muted-foreground">{item.notes}</span>}
                  </button>
                  {actionsMenu(item)}
                </div>
              ))}
            </MobileListCard>
            {data && data.total > data.pageSize && (
              <MobileListCard className="[&>div]:border-t-0">
                <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
              </MobileListCard>
            )}
          </div>
        )
      ) : (
        <Card className="overflow-hidden">
          <CardContent className="p-0 sm:p-0">
            {isLoading ? (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[32%]">{t('classificationCommon.name')}</TableHead>
                    <TableHead>{t('classificationCommon.notes')}</TableHead>
                    <TableHead className="w-40">{t('classificationDetail.lastTransaction')}</TableHead>
                    <TableHead className="w-16"><span className="sr-only">{t('classificationCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableSkeleton
                  columns={[
                    { skeletonClassName: 'w-40' },
                    { skeletonClassName: 'w-56' },
                  { skeletonClassName: 'w-24' },
                    { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                  ]}
                />
              </Table>
            ) : !items || items.length === 0 ? (
              <EmptyState title={emptyTitle} description={emptyDescription} action={{ label: t('classificationCommon.add'), onClick: () => navigate(`${basePath}/new`) }} />
            ) : (
              <Table className="table-fixed">
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[32%]">{t('classificationCommon.name')}</TableHead>
                    <TableHead>{t('classificationCommon.notes')}</TableHead>
                    <TableHead className="w-40">{t('classificationDetail.lastTransaction')}</TableHead>
                    <TableHead className="w-16"><span className="sr-only">{t('classificationCommon.actions')}</span></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.map((item) => (
                    <TableRow key={item.id} className="cursor-pointer" onClick={() => navigate(`${basePath}/${item.id}`)}>
                      <TableCell className="font-medium">{item.name}</TableCell>
                      <TableCell className="text-muted-foreground">
                        {item.notes ?? <EmptyValue />}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                      {item.lastTransactionAt ? <DateWithTime date={item.lastTransactionAt} /> : <EmptyValue />}
                    </TableCell>
                    <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>{actionsMenu(item)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
          {data && (
            <Pagination
              page={data.page}
              pageSize={data.pageSize}
              total={data.total}
              onPageChange={setPage}
            />
          )}
        </Card>
      )}

      <ConfirmDialog
        open={!!itemToDelete}
        onOpenChange={(open) => !open && setItemToDelete(null)}
        title={t('classificationCommon.deleteConfirmTitle')}
        description={t('classificationCommon.deleteConfirmDescription', { name: itemToDelete?.name })}
        onConfirm={handleConfirmDelete}
        pending={deleteItem.isPending || isPreviewLoading}
      >
        {deletionPreview && deletionPreview.transactions > 0 && (
          <p className="text-sm">
            {t(resource === 'categories' ? 'classificationCommon.deleteCategoryInUse' : 'classificationCommon.deleteTagInUse', {
              count: deletionPreview.transactions,
            })}
          </p>
        )}
      </ConfirmDialog>
    </div>
  )
}
