import { useTranslation } from 'react-i18next'
import AccountsTable from '@/components/AccountsTable'

export default function ExpenseAccounts() {
  const { t } = useTranslation()

  return (
    <AccountsTable
      type="expense"
      title={t('expenseAccounts.title')}
      description={t('expenseAccounts.description')}
      emptyTitle={t('expenseAccounts.empty')}
      emptyDescription={t('expenseAccounts.emptyDescription')}
    />
  )
}
