import { useTranslation } from 'react-i18next'
import HistoryCard, { HistoryValue } from '@/components/HistoryCard'
import { isEmptyValue } from '@/lib/audit-log'
import { useAccountAuditLog } from '@/hooks/use-accounts'
import { formatDateTime, formatMoney } from '@/lib/utils'
import type { AccountAdjustment } from '@/types/account'
import type { AuditLogChange } from '@/types/audit-log'

const FIELD_KEYS = new Set([
  'name',
  'iban',
  'bic',
  'accountNumber',
  'notes',
  'isActive',
  'currency',
  'openingBalance',
  'openingBalanceDate',
])

type Adjustment = Omit<AccountAdjustment, 'id'>

/** "3 ottobre 2026: -150,00 € (Correzione)". */
function formatAdjustment(adjustment: Adjustment, currencySymbol: string | null): string {
  const text = `${formatDateTime(adjustment.date)}: ${formatMoney(adjustment.amount, currencySymbol)}`
  return adjustment.description ? `${text} (${adjustment.description})` : text
}

/**
 * The account's history (HistoryCard), on its edit form: its fields, and
 * each other balance change added, changed or removed. Changing the opening
 * balance or an adjustment rewrites past balances: this says why they moved.
 */
export default function AccountHistoryCard({
  accountId,
  currencySymbol,
  flush,
}: {
  accountId: string
  currencySymbol: string | null
  flush?: boolean
}) {
  const { t } = useTranslation()
  const { data: entries, isLoading } = useAccountAuditLog(accountId)

  const formatValue = (field: string, value: unknown): string | null => {
    if (isEmptyValue(value)) return null
    if (field === 'isActive') return value ? t('common.yes') : t('common.no')
    if (field === 'openingBalance') return formatMoney(String(value), currencySymbol)
    if (field === 'openingBalanceDate') return formatDateTime(String(value))
    return String(value)
  }

  const renderChange = (change: AuditLogChange) => {
    if (change.field !== 'adjustment') return null
    const before = change.oldValue as Adjustment | null
    const after = change.newValue as Adjustment | null
    if (before === null && after !== null) {
      return (
        <>
          {t('accountDetail.historyAdjustmentAdded')}: <HistoryValue>{formatAdjustment(after, currencySymbol)}</HistoryValue>
        </>
      )
    }
    if (after === null && before !== null) {
      return (
        <>
          {t('accountDetail.historyAdjustmentRemoved')}: <HistoryValue>{formatAdjustment(before, currencySymbol)}</HistoryValue>
        </>
      )
    }
    if (before !== null && after !== null) {
      return (
        <>
          {t('accountDetail.historyAdjustmentChanged')}: <HistoryValue>{formatAdjustment(before, currencySymbol)}</HistoryValue>
          {' → '}
          <HistoryValue>{formatAdjustment(after, currencySymbol)}</HistoryValue>
        </>
      )
    }
    return null
  }

  return (
    <HistoryCard
      entries={entries}
      isLoading={isLoading}
      createdLabel={t('accountDetail.historyCreated')}
      fieldLabel={(field) => (FIELD_KEYS.has(field) ? t(`accountDetail.historyField.${field}`) : field)}
      formatValue={formatValue}
      renderChange={renderChange}
      flush={flush}
    />
  )
}
