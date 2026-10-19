import { useTranslation } from 'react-i18next'
import TransactionsTable from '@/components/TransactionsTable'

export default function WithdrawalTransactions() {
  const { t } = useTranslation()

  return (
    <TransactionsTable
      kind="withdrawal"
      title={t('transactionsWithdrawal.title')}
      description={t('transactionsWithdrawal.description')}
      emptyTitle={t('transactionsWithdrawal.empty')}
      emptyDescription={t('transactionsWithdrawal.emptyDescription')}
    />
  )
}
