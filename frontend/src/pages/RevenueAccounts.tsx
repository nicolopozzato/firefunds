import { useTranslation } from 'react-i18next'
import AccountsTable from '@/components/AccountsTable'

export default function RevenueAccounts() {
  const { t } = useTranslation()

  return (
    <AccountsTable
      type="revenue"
      title={t('revenueAccounts.title')}
      description={t('revenueAccounts.description')}
      emptyTitle={t('revenueAccounts.empty')}
      emptyDescription={t('revenueAccounts.emptyDescription')}
    />
  )
}
