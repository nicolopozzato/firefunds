import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import PageHeader from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useCreateClassification, useUpdateClassification } from '@/hooks/use-classification'
import { useFormFill } from '@/hooks/use-form-fill'
import { handleApiError } from '@/lib/error-handler'
import type { ClassificationResource } from '@/lib/classification-api'
import type { Classification } from '@/types/classification'
import FormActions from '@/components/FormActions'

interface ClassificationFormProps {
  resource: ClassificationResource
  basePath: string
  title: string
  description: string
  item?: Classification
}

export default function ClassificationForm({
  resource,
  basePath,
  title,
  description,
  item,
}: ClassificationFormProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const createItem = useCreateClassification(resource)
  const updateItem = useUpdateClassification(resource)

  const [name, setName] = useState(item?.name ?? '')
  const [notes, setNotes] = useState(item?.notes ?? '')

  // Once per category or tag, not on every refetch: see useFormFill.
  useFormFill(item?.id ?? 'new', () => {
    setName(item?.name ?? '')
    setNotes(item?.notes ?? '')
  })

  const isEditing = !!item
  const isSaving = createItem.isPending || updateItem.isPending

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()

    const payload = { name, notes: notes || null }

    try {
      if (isEditing) {
        await updateItem.mutateAsync({ id: item.id, data: payload })
      } else {
        await createItem.mutateAsync(payload)
      }

      navigate(basePath)
    } catch (error) {
      toast.error(handleApiError(error))
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={title} description={description} />

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="name">{t('classificationCommon.name')}</Label>
              <Input
                id="name"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="notes">{t('classificationCommon.notes')}</Label>
              <textarea
                id="notes"
                rows={3}
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>

            <FormActions>
              <Button type="button" variant="outline" onClick={() => navigate(basePath)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={isSaving}>
                {t('common.save')}
              </Button>
            </FormActions>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
