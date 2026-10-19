import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import AccountForm from '@/components/AccountForm'
import FormSkeleton from '@/components/FormSkeleton'
import PageHeader from '@/components/PageHeader'
import { useAccount, useAccountTypeFromPath } from '@/hooks/use-accounts'
import type { AccountType } from '@/types/account'

const TITLES: Record<AccountType, { new: string; edit: string; description: string }> = {
  asset: { new: 'assetAccounts.add', edit: 'assetAccounts.editTitle', description: 'assetAccounts.description' },
  expense: { new: 'expenseAccounts.add', edit: 'expenseAccounts.editTitle', description: 'expenseAccounts.description' },
  revenue: { new: 'revenueAccounts.add', edit: 'revenueAccounts.editTitle', description: 'revenueAccounts.description' },
}

export default function AccountFormPage() {
  const { id } = useParams<{ id?: string }>()
  const type = useAccountTypeFromPath()
  const { t } = useTranslation()
  const { data: account, isLoading } = useAccount(id ?? '')

  if (!type) return null

  const copy = TITLES[type]
  const isEditing = !!id

  if (isEditing && isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader title={t(copy.edit)} description={t(copy.description)} />
        <FormSkeleton fieldCount={type === 'asset' ? 8 : 5} />
      </div>
    )
  }

  return (
    <AccountForm
      type={type}
      title={t(isEditing ? copy.edit : copy.new)}
      description={t(copy.description)}
      account={isEditing ? account : undefined}
    />
  )
}
