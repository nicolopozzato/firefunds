import { useTranslation } from 'react-i18next'
import ClassificationTable from '@/components/ClassificationTable'

export default function Categories() {
  const { t } = useTranslation()

  return (
    <ClassificationTable
      resource="categories"
      basePath="/categories"
      title={t('categories.title')}
      description={t('categories.description')}
      emptyTitle={t('categories.empty')}
      emptyDescription={t('categories.emptyDescription')}
    />
  )
}
