import { useTranslation } from 'react-i18next'
import TransactionsTable from '@/components/TransactionsTable'

export default function TransferTransactions() {
  const { t } = useTranslation()

  return (
    <TransactionsTable
      kind="transfer"
      title={t('transactionsTransfer.title')}
      description={t('transactionsTransfer.description')}
      emptyTitle={t('transactionsTransfer.empty')}
      emptyDescription={t('transactionsTransfer.emptyDescription')}
    />
  )
}
