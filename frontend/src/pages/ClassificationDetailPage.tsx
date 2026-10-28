import { useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { eachMonthOfInterval, format } from 'date-fns'
import { toast } from 'sonner'
import { Pencil, Trash2 } from 'lucide-react'
import PageHeader from '@/components/PageHeader'
import PageActions from '@/components/PageActions'
import Stat from '@/components/Stat'
import EmptyState from '@/components/EmptyState'
import ConfirmDialog from '@/components/ConfirmDialog'
import TransactionList from '@/components/TransactionList'
import LoadFailed from '@/components/LoadFailed'
import GroupedBarChart, { BarLegend } from '@/components/reports/GroupedBarChart'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useClassificationDeletionPreview,
  useClassificationItem,
  useClassificationMonths,
  useClassificationTransactions,
  useDeleteClassification,
} from '@/hooks/use-classification'
import { useTimeframe } from '@/contexts/TimeframeContext'
import { useAuth } from '@/contexts/AuthContext'
import { handleApiError } from '@/lib/error-handler'
import { dateLocale, periodLabel } from '@/lib/report-format'
import { currencySymbolFor, formatMoney } from '@/lib/utils'
import type { ClassificationResource } from '@/lib/classification-api'

const EXPENSE_COLOR = 'hsl(var(--negative))'
const INCOME_COLOR = 'hsl(var(--positive))'

/**
 * A category's or a tag's page, like an account's: over the selected period
 * what was spent and received with it, month by month, and its transactions.
 */
export default function ClassificationDetailPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { id = '' } = useParams<{ id: string }>()
  const resource: ClassificationResource = location.pathname.startsWith('/tags') ? 'tags' : 'categories'
  const basePath = `/${resource}`
  const { user } = useAuth()
  const currency = currencySymbolFor(user?.currency ?? '')
  const { timeframe } = useTimeframe()
  const from = format(timeframe.from, 'yyyy-MM-dd')
  const to = format(timeframe.to, 'yyyy-MM-dd')
  const [page, setPage] = useState(1)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const { data: item, isLoading, error, refetch } = useClassificationItem(resource, id)
  const { data: months, isLoading: isMonthsLoading } = useClassificationMonths(resource, id, from, to)
  const { data: transactions, isLoading: isTransactionsLoading } = useClassificationTransactions(resource, id, from, to, page)
  const { data: deletionPreview } = useClassificationDeletionPreview(resource, confirmDelete ? id : null)
  const deleteItem = useDeleteClassification(resource)

  // Every month of the period, the empty ones too, so the bars keep their place.
  const chart = useMemo(() => {
    const byMonth = new Map((months ?? []).map((month) => [month.month, month]))
    const keys = eachMonthOfInterval({ start: timeframe.from, end: timeframe.to }).map((day) => format(day, 'yyyy-MM'))
    return {
      keys,
      expense: keys.map((key) => Number(byMonth.get(key)?.expense ?? 0)),
      income: keys.map((key) => Number(byMonth.get(key)?.income ?? 0)),
    }
  }, [months, timeframe.from, timeframe.to])

  const totalExpense = chart.expense.reduce((sum, value) => sum + value, 0)
  const totalIncome = chart.income.reduce((sum, value) => sum + value, 0)
  const hasIncome = totalIncome > 0
  const series = [
    { id: 'expense', label: t('classificationDetail.spent'), color: EXPENSE_COLOR, values: chart.expense },
    ...(hasIncome ? [{ id: 'income', label: t('classificationDetail.received'), color: INCOME_COLOR, values: chart.income }] : []),
  ]

  const handleDelete = async () => {
    try {
      await deleteItem.mutateAsync(id)
      setConfirmDelete(false)
      navigate(basePath)
    } catch (deleteError) {
      toast.error(handleApiError(deleteError))
    }
  }

  if (!item && error) {
    return <LoadFailed error={error} onRetry={() => void refetch()} />
  }

  if (isLoading || !item) {
    return (
      <div className="space-y-6">
        <PageHeader title={t(`${resource}.title`)} />
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-72 w-full rounded-xl" />
      </div>
    )
  }

  return (
    <div className="space-y-4 md:space-y-6">
      <PageHeader
        title={item.name}
        compactActions
        actions={
          <PageActions
            actions={[
              { label: t('common.edit'), icon: Pencil, primary: true, onClick: () => navigate(`${basePath}/${id}/edit`) },
              { label: t('common.delete'), icon: Trash2, destructive: true, onClick: () => setConfirmDelete(true) },
            ]}
          />
        }
      />

      <Card>
        <CardContent className="space-y-4 pt-4 sm:pt-5">
          <div className="grid grid-cols-2 gap-x-6 gap-y-4 lg:grid-cols-4">
            <Stat size="lg" label={t('classificationDetail.spent')} value={formatMoney(totalExpense, currency)} />
            {hasIncome && <Stat size="lg" label={t('classificationDetail.received')} value={formatMoney(totalIncome, currency)} />}
            <Stat size="lg" label={t('classificationDetail.transactions')} value={transactions?.total ?? '—'} />
          </div>
          {item.notes && (
            <div className="border-t pt-4">
              <div className="stat-label">{t('classificationCommon.notes')}</div>
              <p className="mt-1 whitespace-pre-wrap text-sm">{item.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {isMonthsLoading ? (
        <Skeleton className="h-72 w-full rounded-xl" />
      ) : (
        <section className="surface p-4 sm:p-5">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="section-title">{t('classificationDetail.byMonth')}</h3>
            <BarLegend series={series} />
          </div>
          <GroupedBarChart
            labels={chart.keys.map((key) => periodLabel(key, 'month', dateLocale(i18n.language)))}
            series={series}
            currency={currency}
            emptyLabel={t('charts.noData')}
            tooltip={(index) => (
              <>
                <div className="font-medium capitalize">{periodLabel(chart.keys[index], 'month', dateLocale(i18n.language), true)}</div>
                <div className="text-negative">{t('classificationDetail.spent')}: {formatMoney(chart.expense[index], currency)}</div>
                {hasIncome && <div className="text-positive">{t('classificationDetail.received')}: {formatMoney(chart.income[index], currency)}</div>}
              </>
            )}
          />
        </section>
      )}

      <TransactionList
        data={transactions}
        isLoading={isTransactionsLoading}
        onPageChange={setPage}
        empty={<EmptyState title={t('classificationDetail.noTransactions')} description={t('classificationDetail.noTransactionsDescription')} />}
      />

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t('classificationCommon.deleteConfirmTitle')}
        description={t('classificationCommon.deleteConfirmDescription', { name: item.name })}
        onConfirm={handleDelete}
        pending={deleteItem.isPending || !deletionPreview}
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
