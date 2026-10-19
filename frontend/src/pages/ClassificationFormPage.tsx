import { useParams, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ClassificationForm from '@/components/ClassificationForm'
import FormSkeleton from '@/components/FormSkeleton'
import PageHeader from '@/components/PageHeader'
import { useClassificationItem } from '@/hooks/use-classification'
import type { ClassificationResource } from '@/lib/classification-api'

const CONFIG: Record<ClassificationResource, { new: string; edit: string; description: string; basePath: string }> = {
  categories: {
    new: 'categories.add',
    edit: 'categories.editTitle',
    description: 'categories.description',
    basePath: '/categories',
  },
  tags: {
    new: 'tags.add',
    edit: 'tags.editTitle',
    description: 'tags.description',
    basePath: '/tags',
  },
}

function useResourceFromPath(): ClassificationResource | null {
  const location = useLocation()
  const match = location.pathname.match(/^\/(categories|tags)\b/)
  return (match?.[1] as ClassificationResource) ?? null
}

export default function ClassificationFormPage() {
  const { id } = useParams<{ id?: string }>()
  const resource = useResourceFromPath()
  const { t } = useTranslation()
  const { data: item, isLoading } = useClassificationItem(resource ?? 'categories', id ?? '')

  if (!resource) return null

  const copy = CONFIG[resource]
  const isEditing = !!id

  if (isEditing && isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader title={t(copy.edit)} description={t(copy.description)} />
        <FormSkeleton fieldCount={0} />
      </div>
    )
  }

  return (
    <ClassificationForm
      resource={resource}
      basePath={copy.basePath}
      title={t(isEditing ? copy.edit : copy.new)}
      description={t(copy.description)}
      item={isEditing ? item : undefined}
    />
  )
}
