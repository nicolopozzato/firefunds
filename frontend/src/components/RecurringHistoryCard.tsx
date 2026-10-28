import { useTranslation } from 'react-i18next'
import HistoryCard from '@/components/HistoryCard'
import { useRecurringAuditLog } from '@/hooks/use-recurring'
import { isEmptyValue } from '@/lib/audit-log'
import { dateLocale, formatMoney, formatShortDate } from '@/lib/utils'
import type { AmountChange } from '@/types/recurring'

const FIELDS = new Set([
  'description',
  'sourceAccount',
  'destinationAccount',
  'amount',
  'amountChanges',
  'destinationAmount',
  'category',
  'tags',
  'notes',
  'startDate',
  'unit',
  'interval',
  'monthDay',
  'weekendShift',
  'months',
  'endDate',
  'count',
  'time',
  'mode',
  'paused',
])

/** A recurring transaction's history (HistoryCard): what each save, pause or resume changed. */
export default function RecurringHistoryCard({ recurringId, currencySymbol }: { recurringId: string; currencySymbol: string | null }) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useRecurringAuditLog(recurringId)
  const monthName = (month: number) => new Intl.DateTimeFormat(dateLocale(), { month: 'short' }).format(new Date(2026, month - 1, 1))

  const formatValue = (field: string, value: unknown): string | null => {
    // The missing side, as on the page.
    if ((field === 'sourceAccount' || field === 'destinationAccount') && value === null) return '—'
    if (field === 'paused') return value ? t('common.yes') : t('common.no')
    if (isEmptyValue(value)) return null
    switch (field) {
      case 'amount':
      case 'destinationAmount':
        return formatMoney(String(value), currencySymbol)
      case 'amountChanges':
        return (value as AmountChange[])
          .map((change) => `${t('recurring.changeFrom')} ${formatShortDate(change.from)}: ${formatMoney(change.amount, currencySymbol)}`)
          .join('; ')
      case 'startDate':
      case 'endDate':
        return formatShortDate(String(value))
      case 'unit':
        return t(`recurring.unit_${value}`, { count: 1 })
      case 'monthDay':
        return t(`recurring.monthDayName_${value}`)
      case 'weekendShift':
        return t(`recurring.weekendOption_${value}`)
      case 'mode':
        return t(`recurring.mode_${value}`)
      case 'months':
        return (value as number[]).map(monthName).join(', ')
      case 'tags':
        return (value as string[]).join(', ')
      default:
        return String(value)
    }
  }

  return (
    <HistoryCard
      entries={entries}
      isLoading={isLoading}
      createdLabel={t('recurring.historyCreated')}
      fieldLabel={(field) => (FIELDS.has(field) ? t(`recurring.historyField.${field}`) : field)}
      formatValue={formatValue}
    />
  )
}
