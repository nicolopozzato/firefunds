import { useTranslation } from 'react-i18next'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface PaginationProps {
  page: number
  pageSize: number
  total: number
  onPageChange: (page: number) => void
}

export default function Pagination({ page, pageSize, total, onPageChange }: PaginationProps) {
  const { t } = useTranslation()

  if (total <= pageSize) return null

  const pageCount = Math.max(1, Math.ceil(total / pageSize))
  const firstItem = total === 0 ? 0 : (page - 1) * pageSize + 1
  const lastItem = Math.min(page * pageSize, total)

  return (
    <div className="flex items-center justify-between gap-2 border-t px-4 py-3">
      <span className="text-sm text-muted-foreground">
        {t('pagination.range', { first: firstItem, last: lastItem, total })}
      </span>
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={page <= 1}
          aria-label={t('pagination.previous')}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="h-4 w-4" />
          <span className="hidden sm:inline">{t('pagination.previous')}</span>
        </Button>
        <span className="text-sm text-muted-foreground">
          {t('pagination.pageOf', { page, pageCount })}
        </span>
        <Button
          variant="outline"
          size="sm"
          disabled={page >= pageCount}
          aria-label={t('pagination.next')}
          onClick={() => onPageChange(page + 1)}
        >
          <span className="hidden sm:inline">{t('pagination.next')}</span>
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}
