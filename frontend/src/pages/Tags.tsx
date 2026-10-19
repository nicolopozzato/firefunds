import { useTranslation } from 'react-i18next'
import ClassificationTable from '@/components/ClassificationTable'

export default function Tags() {
  const { t } = useTranslation()

  return (
    <ClassificationTable
      resource="tags"
      basePath="/tags"
      title={t('tags.title')}
      description={t('tags.description')}
      emptyTitle={t('tags.empty')}
      emptyDescription={t('tags.emptyDescription')}
    />
  )
}
