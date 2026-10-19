import { useTranslation } from 'react-i18next'
import TransactionsTable from '@/components/TransactionsTable'

export default function DepositTransactions() {
  const { t } = useTranslation()

  return (
    <TransactionsTable
      kind="deposit"
      title={t('transactionsDeposit.title')}
      description={t('transactionsDeposit.description')}
      emptyTitle={t('transactionsDeposit.empty')}
      emptyDescription={t('transactionsDeposit.emptyDescription')}
    />
  )
}
