import { useParams } from 'react-router-dom'
import InvestmentInstrumentForm from '@/components/InvestmentInstrumentForm'
import FormSkeleton from '@/components/FormSkeleton'
import PageHeader from '@/components/PageHeader'
import { useTranslation } from 'react-i18next'
import { useInvestmentInstrument } from '@/hooks/use-investments'

export default function InvestmentInstrumentFormPage() {
  const { t } = useTranslation()
  const { id } = useParams<{ id?: string }>()
  const isEditing = !!id
  const { data: instrument, isLoading } = useInvestmentInstrument(id ?? '')

  if (isEditing && isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('investmentInstruments.editTitle')} description={t('investmentInstruments.description')} />
        <FormSkeleton fieldCount={4} />
      </div>
    )
  }

  return <InvestmentInstrumentForm instrument={isEditing ? instrument : undefined} />
}
