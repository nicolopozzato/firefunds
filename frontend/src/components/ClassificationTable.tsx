import { useEffect, useState } from 'react'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useClassificationDeletionPreview, useClassificationList, useDeleteClassification } from '@/hooks/use-classification'
import { handleApiError } from '@/lib/error-handler'
import type { ClassificationResource } from '@/lib/classification-api'
import type { Classification } from '@/types/classification'
import EmptyValue from '@/components/EmptyValue'

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
  const [page, setPage] = useState(1)
  const { data, isLoading } = useClassificationList(resource, page)
  const items = data?.items
  const deleteItem = useDeleteClassification(resource)
  const [itemToDelete, setItemToDelete] = useState<Classification | null>(null)
  const { data: deletionPreview, isLoading: isPreviewLoading } = useClassificationDeletionPreview(resource, itemToDelete?.id ?? null)

  useEffect(() => {
    setPage(1)
  }, [resource])

  const handleConfirmDelete = async () => {
    if (!itemToDelete) return
    try {
      await deleteItem.mutateAsync(itemToDelete.id)
      setItemToDelete(null)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

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

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('classificationCommon.name')}</TableHead>
                  <TableHead>{t('classificationCommon.notes')}</TableHead>
                  <TableHead className="text-right">{t('classificationCommon.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableSkeleton
                columns={[
                  { skeletonClassName: 'w-40' },
                  { skeletonClassName: 'w-56' },
                  { cellClassName: 'text-right', skeletonClassName: 'ml-auto h-8 w-8 rounded-full' },
                ]}
              />
            </Table>
          ) : !items || items.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <div className="rounded-full bg-muted p-4 mb-4">
                <Plus className="h-8 w-8 text-muted-foreground" />
              </div>
              <h3 className="text-lg font-semibold mb-2">{emptyTitle}</h3>
              <p className="text-sm text-muted-foreground mb-4 max-w-sm">
                {emptyDescription}
              </p>
              <Button onClick={() => navigate(`${basePath}/new`)}>
                <Plus className="mr-2 h-4 w-4" />
                {t('classificationCommon.add')}
              </Button>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('classificationCommon.name')}</TableHead>
                  <TableHead>{t('classificationCommon.notes')}</TableHead>
                  <TableHead className="text-right">{t('classificationCommon.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.name}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.notes ?? <EmptyValue />}
                    </TableCell>
                    <TableCell className="text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => navigate(`${basePath}/${item.id}/edit`)}>
                            <Pencil className="mr-2 h-4 w-4" />
                            {t('common.edit')}
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => setItemToDelete(item)}
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {t('common.delete')}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
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

      <Dialog open={!!itemToDelete} onOpenChange={(open) => !open && setItemToDelete(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('classificationCommon.deleteConfirmTitle')}</DialogTitle>
            <DialogDescription>
              {t('classificationCommon.deleteConfirmDescription', { name: itemToDelete?.name })}
            </DialogDescription>
          </DialogHeader>
          {deletionPreview && deletionPreview.transactions > 0 && (
            <p className="text-sm">
              {t(resource === 'categories' ? 'classificationCommon.deleteCategoryInUse' : 'classificationCommon.deleteTagInUse', {
                count: deletionPreview.transactions,
              })}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setItemToDelete(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteItem.isPending || isPreviewLoading}
            >
              {t('common.delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
