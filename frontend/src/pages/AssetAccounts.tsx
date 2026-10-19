import { useTranslation } from 'react-i18next'
import AccountsTable from '@/components/AccountsTable'

export default function AssetAccounts() {
  const { t } = useTranslation()

  return (
    <AccountsTable
      type="asset"
      title={t('assetAccounts.title')}
      description={t('assetAccounts.description')}
      emptyTitle={t('assetAccounts.empty')}
      emptyDescription={t('assetAccounts.emptyDescription')}
    />
  )
}
