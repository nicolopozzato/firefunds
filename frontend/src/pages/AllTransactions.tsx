import { useTranslation } from 'react-i18next'
import TransactionsTable from '@/components/TransactionsTable'

export default function AllTransactions() {
  const { t } = useTranslation()

  return (
    <TransactionsTable
      kind="all"
      title={t('transactionsAll.title')}
      emptyTitle={t('transactionsAll.empty')}
      emptyDescription={t('transactionsAll.emptyDescription')}
    />
  )
}
